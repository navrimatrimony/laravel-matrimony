<?php

namespace App\Services\Matching;

use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\Api\MobileDiscoveryFilterService;
use App\Services\Location\LocationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use App\Services\ProfileRotationService;
use App\Support\SchemaPresence;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "जवळची स्थळे" / nearby feed: own taluka first, then outward in widening bands, with no district,
 * state or country ceiling — the last band is the rest of India.
 *
 * WHY A BAND WALK AND NOT ONE BIG DISTANCE SORT
 * ---------------------------------------------
 * The naive version of this feature resolves every candidate's residence hierarchy in PHP and
 * haversines it against the seeker. That is one `Location::find()` + full hierarchy walk PER CANDIDATE,
 * which is exactly the shape that made a previous change on this codebase four times slower on
 * production (invisible locally, because local holds a fraction of the rows).
 *
 * So geography is resolved ONCE for the seeker, converted into an ordered list of taluka id bands, and
 * every band is a plain indexed SQL predicate. The walk stops the moment the requested page is full, so
 * a member sitting in a busy taluka never causes a single row outside that taluka to be examined. Cost
 * is a function of page size and how sparse the seeker's neighbourhood is — never of how many profiles
 * exist in India.
 *
 * ORDERING (see {@see bandProfileIds()})
 *   1. band index          — own taluka, then the nearest talukas, widening, then everything else
 *   2. approved photo      — inside a band, profiles with a usable photo come first
 *   3. updated_at, id DESC — stable and deterministic, so page 2 never repeats page 1
 *
 * ALREADY-VIEWED PROFILES ARE INCLUDED. This feed deliberately does not consult `profile_views`: it
 * neither excludes seen profiles (as {@see ProfileRotationService::applyDiscoverScope()} does for the
 * "new" feed) nor sinks them to the bottom (as {@see MatchingService} does for its tabs). A member
 * re-browsing their own neighbourhood expects to see who is there, not a shrinking list.
 */
final class NearbyFeedService
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    /**
     * Band widths over the distance-sorted taluka list, after band 0 (the seeker's own taluka).
     *
     * Fine-grained near the seeker, where a few kilometres genuinely change the answer, and coarse far
     * away, where they do not. Bands keep growing by the last width until the list is exhausted, so
     * there is no radius at which the feed stops widening.
     *
     * @var list<int>
     */
    private const BAND_WIDTHS = [8, 16, 32, 64, 128];

    /**
     * Ceiling on the proximity scan. India end to end; only geocoded talukas can be ranked at all, and
     * everything else lands in the final "rest of India" band.
     */
    private const SCAN_RADIUS_KM = 4000;

    private const SCAN_TALUKA_LIMIT = 10000;

    public function __construct(
        private readonly LocationService $locations,
        private readonly MobileDiscoveryFilterService $discovery,
    ) {}

    /**
     * One page of the feed, as profile ids in display order.
     *
     * @param  callable(Builder<MatrimonyProfile>): void  $applyRequestFilters  the caller's non-geographic
     *                                                                          filters (age, caste, height, …)
     * @return array{ids: list<int>, page: int, per_page: int, has_more: bool}
     */
    public function page(
        User $viewer,
        MatrimonyProfile $viewerProfile,
        ?int $originLeafId,
        int $page,
        int $perPage,
        callable $applyRequestFilters,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset = ($page - 1) * $perPage;

        // One row more than the page needs: its existence IS the has_more answer, so the feed never
        // pays for a COUNT over the whole pool just to render a "load more" affordance.
        $target = $offset + $perPage + 1;

        $collected = [];
        foreach ($this->bands($viewerProfile, $originLeafId) as $band) {
            $remaining = $target - count($collected);
            if ($remaining <= 0) {
                break;
            }

            $ids = $this->bandProfileIds($viewer, $band, $collected, $remaining, $applyRequestFilters);
            foreach ($ids as $id) {
                $collected[] = $id;
            }

            // A band that came back short is exhausted; only then may the walk widen. A band that
            // filled the request is where the page ends — nothing further out is even queried.
            if (count($ids) >= $remaining) {
                break;
            }
        }

        $window = array_slice($collected, $offset, $perPage);

        return [
            'ids' => array_values($window),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => count($collected) > $offset + count($window),
        ];
    }

    /**
     * The seeker's geographic origin: an explicit leaf when the member picked one on the tab, else
     * their own stored residence.
     *
     * On this feed a chosen location is an ORIGIN, not a filter. The product owner asked for "own
     * taluka first, then further and further, all India" — treating the picked location as a hard
     * `where` is what limited the feed to one place, and is the opposite of what the tab is for.
     *
     * @return array{district_id: int|null, state_id: int|null, country_id: int|null, taluka_id: int|null, lat: float|null, lng: float|null}
     */
    public function origin(MatrimonyProfile $viewerProfile, ?int $originLeafId): array
    {
        if ($originLeafId !== null && $originLeafId > 0) {
            $geo = MatrimonyProfile::geoAddressIdsForLeaf($originLeafId);
            if ($geo['taluka_id'] !== null || $geo['lat'] !== null) {
                return $geo;
            }
        }

        return $viewerProfile->residenceGeoAddressIds();
    }

    /**
     * Ordered bands of taluka ids, widening outward, ending with the open "rest of India" band.
     *
     * Exactly two queries regardless of how far the walk goes: one bounding-box scan over the geocoded
     * taluka rows, and (only when the seeker's own taluka is not geocoded) nothing else. Both are
     * bounded by the size of the geography master data, which does not grow with the member base.
     *
     * @return list<list<int>|null> `null` = the final open band (no geographic predicate)
     */
    private function bands(MatrimonyProfile $viewerProfile, ?int $originLeafId): array
    {
        $geo = $this->origin($viewerProfile, $originLeafId);
        $ownTaluka = $geo['taluka_id'] !== null ? (int) $geo['taluka_id'] : 0;
        $lat = $geo['lat'];
        $lng = $geo['lng'];

        $bands = [];
        if ($ownTaluka > 0) {
            $bands[] = [$ownTaluka];
        }

        if ($lat !== null && $lng !== null && SchemaPresence::hasTable(Location::geoTable())) {
            $ranked = $this->locations->talukaIdsByDistance(
                (float) $lat,
                (float) $lng,
                self::SCAN_RADIUS_KM,
                self::SCAN_TALUKA_LIMIT,
            );

            $ordered = [];
            foreach (array_keys($ranked) as $talukaId) {
                if ((int) $talukaId !== $ownTaluka) {
                    $ordered[] = (int) $talukaId;
                }
            }

            $cursor = 0;
            $widthIndex = 0;
            $total = count($ordered);
            while ($cursor < $total) {
                $width = self::BAND_WIDTHS[min($widthIndex, count(self::BAND_WIDTHS) - 1)];
                $bands[] = array_slice($ordered, $cursor, $width);
                $cursor += $width;
                $widthIndex++;
            }
        }

        // Everything the bands above could not place: profiles in un-geocoded talukas, residences
        // recorded only at district/state level, and profiles with no residence at all. Without this
        // the feed would silently have a ceiling, which is the one thing the product owner ruled out.
        $bands[] = null;

        return $bands;
    }

    /**
     * @param  list<int>|null  $band  taluka ids, or null for the open final band
     * @param  list<int>  $exclude  ids already emitted by nearer bands
     * @param  callable(Builder<MatrimonyProfile>): void  $applyRequestFilters
     * @return list<int>
     */
    private function bandProfileIds(
        User $viewer,
        ?array $band,
        array $exclude,
        int $limit,
        callable $applyRequestFilters,
    ): array {
        if ($band !== null && $band === []) {
            return [];
        }

        $query = MatrimonyProfile::query();
        $this->discovery->applyCandidateQuery($query, $viewer);
        $applyRequestFilters($query);

        if ($band !== null) {
            $this->whereResidenceTalukaIn($query, $band);
        }
        if ($exclude !== []) {
            $query->whereNotIn('matrimony_profiles.id', $exclude);
        }

        // Photo preference, inside the band only — a photo never lifts a distant profile above a
        // nearer one, it only decides who leads within the same locality.
        ProfileRotationService::applyApprovedPhotoOrdering($query);

        return $query
            ->orderByDesc('matrimony_profiles.updated_at')
            ->orderByDesc('matrimony_profiles.id')
            ->limit($limit)
            ->pluck('matrimony_profiles.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Canonical residence (self + "current" in `profile_addresses`) resolves to one of these talukas.
     *
     * `addresses` is country > state > district > taluka > village, and EVERY village row's parent is
     * its taluka, so the taluka of a residence is either the leaf itself or the leaf's parent — one
     * level, no recursive CTE. Both sides use an existing index (`addresses` primary key, `idx_addr_parent`).
     *
     * @param  Builder<MatrimonyProfile>  $query
     * @param  list<int>  $talukaIds
     */
    private function whereResidenceTalukaIn(Builder $query, array $talukaIds): void
    {
        $typeId = ProfileCanonicalResidenceService::currentAddressTypeId();
        if ($typeId === null || ! SchemaPresence::hasTable('profile_addresses')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $leafColumn = SchemaPresence::hasColumn('profile_addresses', 'location_id') ? 'location_id' : 'city_id';
        $geo = Location::geoTable();

        $query->whereExists(function ($sub) use ($talukaIds, $typeId, $leafColumn, $geo): void {
            $sub->selectRaw('1')
                ->from('profile_addresses as pa')
                ->join($geo.' as leaf', 'leaf.id', '=', 'pa.'.$leafColumn)
                ->whereColumn('pa.profile_id', 'matrimony_profiles.id')
                ->where('pa.address_scope', 'self')
                ->where('pa.address_type_id', $typeId)
                ->where(function ($where) use ($talukaIds): void {
                    $where
                        ->where(function ($self) use ($talukaIds): void {
                            $self->where('leaf.hierarchy', 'taluka')->whereIn('leaf.id', $talukaIds);
                        })
                        ->orWhere(function ($child) use ($talukaIds): void {
                            $child->where('leaf.hierarchy', 'village')->whereIn('leaf.parent_id', $talukaIds);
                        });
                });
        });
    }
}
