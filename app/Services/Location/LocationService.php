<?php

namespace App\Services\Location;

use App\Models\Location;
use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LocationService
{
    /**
     * Return upward chain from immediate parent to root.
     *
     * @return array<int, Location>
     */
    public function getAncestors(Location $location): array
    {
        $ancestors = [];
        if (! $location->relationLoaded('parent')) {
            $location->load('parent');
        }
        $current = $location->parent;

        while ($current !== null) {
            $ancestors[] = $current;
            if (! $current->relationLoaded('parent')) {
                $current->load('parent');
            }
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * Return ordered hierarchy from state -> district -> taluka -> place.
     *
     * @return array<string, Location|null>
     */
    public function getFullHierarchy(Location $location): array
    {
        $state = null;
        $district = null;
        $taluka = null;
        $city = null;

        $lineage = array_reverse($this->getAncestors($location)); // root -> ... -> parent

        foreach ($lineage as $node) {
            if ($node->type === 'state') {
                $state = $node;
            } elseif ($node->type === 'district') {
                $district = $node;
            } elseif ($node->type === 'taluka') {
                $taluka = $node;
            } elseif ($node->type === 'city') {
                $city = $node;
            }
        }

        return [
            'state' => $state,
            'district' => $district,
            'taluka' => $taluka,
            'city' => $city,
            'place' => $location,
        ];
    }

    /**
     * Walk {@see Location::parent} chain (including self) for the first row with {@code type}.
     */
    public function getAncestorByType(Location $location, string $type): ?Location
    {
        if ($location->type === $type) {
            return $location;
        }
        foreach ($this->getAncestors($location) as $a) {
            if ($a->type === $type) {
                return $a;
            }
        }

        return null;
    }

    /**
     * Profile / listing / search / nearby line from canonical {@see Location} ({@code addresses} row).
     * Delegates to {@see LocationFormatterService} (tag-driven UI; {@code parent_id} structure only).
     */
    public function getDisplayLabel(Location $location): string
    {
        return app(LocationFormatterService::class)->formatForLocation($location);
    }

    public function normalizeInput(string $input): string
    {
        $normalized = mb_strtolower(trim($input), 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Text search over unified geo SSOT ({@see Location} / {@code addresses}): {@code name}, {@code slug},
     * {@code name_mr} when present; {@code pincode} when column exists (3–8 digits from input); optional {@code location_aliases}.
     * Requires {@code is_active} truthy.
     *
     * Multi-word queries (e.g. {@code islampur sangli}, {@code vita sangli}): candidates match the first token on the row;
     * results are filtered so **every** token appears somewhere in the leaf + ancestor names (Marathi + English),
     * then ranked so the intended village/town surfaces near the top.
     *
     * @return array<int, array{id:int,name:string,type:string,display_label:string}>
     */
    public function search(string $query): array
    {
        $normalized = $this->normalizeInput($query);
        if ($normalized === '') {
            return [];
        }

        $tokens = $this->distinctSearchTokens($normalized);
        if (count($tokens) >= 2) {
            return $this->searchMultiToken($tokens);
        }

        $like = '%'.$normalized.'%';

        $geo = Location::geoTable();

        $digitsOnly = preg_replace('/\D+/u', '', $normalized);

        $locations = Location::query()
            ->with('parent')
            ->where('is_active', true)
            ->where(function ($q) use ($normalized, $like, $geo, $digitsOnly) {
                $q->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like);
                if (Schema::hasColumn($geo, 'name_mr')) {
                    $q->orWhere('name_mr', 'like', $like);
                }
                if (Schema::hasColumn($geo, 'pincode') && strlen($digitsOnly) >= 3 && strlen($digitsOnly) <= 8) {
                    if (strlen($digitsOnly) === 6) {
                        $q->orWhere('pincode', $digitsOnly);
                    } else {
                        $q->orWhere('pincode', 'like', $digitsOnly.'%');
                    }
                }
                if (Schema::hasTable('location_aliases')) {
                    $q->orWhereExists(function ($sub) use ($normalized, $like, $geo) {
                        $sub->selectRaw('1')
                            ->from('location_aliases')
                            ->whereColumn('location_aliases.location_id', $geo.'.id')
                            ->where(function ($a) use ($normalized, $like) {
                                $a->where('location_aliases.normalized_alias', 'like', $like)
                                    ->orWhere('location_aliases.normalized_alias', $normalized);
                            });
                    });
                }
            })
            ->when(
                strlen($digitsOnly) === 6 && Schema::hasColumn($geo, 'pincode'),
                static function ($q) use ($digitsOnly): void {
                    $q->orderByRaw('CASE WHEN pincode = ? THEN 0 ELSE 1 END', [$digitsOnly]);
                }
            )
            ->orderByRaw(
                'CASE
                    WHEN LOWER(TRIM(name)) = ? THEN 0
                    WHEN LOWER(TRIM(name)) LIKE ? THEN 1
                    WHEN LOWER(TRIM(name)) LIKE ? THEN 2
                    ELSE 3
                END',
                [$normalized, $normalized.'%', '%'.$normalized.'%']
            )
            ->orderByRaw(
                "CASE type
                    WHEN 'city' THEN 0
                    WHEN 'district' THEN 1
                    WHEN 'suburb' THEN 2
                    WHEN 'taluka' THEN 3
                    WHEN 'village' THEN 4
                    WHEN 'state' THEN 5
                    WHEN 'country' THEN 6
                    ELSE 7
                END"
            )
            ->orderBy('name')
            ->limit(25)
            ->get();

        $this->hydrateParentChain($locations);

        return $locations->map(function (Location $location): array {
            return [
                'id' => (int) $location->id,
                'name' => (string) $location->name,
                'type' => (string) $location->type,
                'display_label' => $this->getDisplayLabel($location),
            ];
        })->values()->all();
    }

    /**
     * @param  list<string>  $tokens  Lowercase tokens, length ≥ 2, de-duplicated.
     * @return array<int, array{id:int,name:string,type:string,display_label:string}>
     */
    private function searchMultiToken(array $tokens): array
    {
        $geo = Location::geoTable();
        $primary = $tokens[0];
        $primaryLike = '%'.$primary.'%';

        $locations = Location::query()
            ->where('is_active', true)
            ->where(function ($q) use ($primary, $primaryLike, $geo): void {
                $q->where('name', 'like', $primaryLike)
                    ->orWhere('slug', 'like', $primaryLike);
                if (Schema::hasColumn($geo, 'name_mr')) {
                    $q->orWhere('name_mr', 'like', $primaryLike);
                }
                if (Schema::hasTable('location_aliases')) {
                    $q->orWhereExists(function ($sub) use ($primary, $primaryLike, $geo): void {
                        $sub->selectRaw('1')
                            ->from('location_aliases')
                            ->whereColumn('location_aliases.location_id', $geo.'.id')
                            ->where(function ($a) use ($primary, $primaryLike): void {
                                $a->where('location_aliases.normalized_alias', 'like', $primaryLike)
                                    ->orWhere('location_aliases.normalized_alias', $primary);
                            });
                    });
                }
            })
            ->limit(220)
            ->get();

        if ($locations->isEmpty()) {
            $locations = Location::query()
                ->where('is_active', true)
                ->where(function ($q) use ($tokens, $geo): void {
                    foreach ($tokens as $t) {
                        $tl = '%'.$t.'%';
                        $q->orWhere(function ($inner) use ($tl, $geo): void {
                            $inner->where('name', 'like', $tl)
                                ->orWhere('slug', 'like', $tl);
                            if (Schema::hasColumn($geo, 'name_mr')) {
                                $inner->orWhere('name_mr', 'like', $tl);
                            }
                        });
                    }
                })
                ->limit(320)
                ->get();
        }

        $this->hydrateParentChain($locations);

        $filtered = $locations->filter(function (Location $loc) use ($tokens): bool {
            return $this->locationMatchesAllSearchTokens($loc, $tokens);
        });

        $sorted = $filtered->sort(function (Location $a, Location $b) use ($tokens): int {
            return $this->compareMultiTokenSearchRank($a, $b, $tokens);
        })->values();

        return $sorted->take(25)->map(function (Location $location): array {
            return [
                'id' => (int) $location->id,
                'name' => (string) $location->name,
                'type' => (string) $location->type,
                'display_label' => $this->getDisplayLabel($location),
            ];
        })->all();
    }

    /**
     * @return list<string>
     */
    private function distinctSearchTokens(string $normalized): array
    {
        $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $p) {
            $p = trim(mb_strtolower((string) $p, 'UTF-8'));
            if ($p === '' || mb_strlen($p) < 2) {
                continue;
            }
            if (! in_array($p, $out, true)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Leaf + every ancestor localized name, lowercased (for substring token match).
     */
    private function locationSearchHaystack(Location $loc): string
    {
        $parts = [mb_strtolower(trim($loc->localizedName()), 'UTF-8')];
        $cur = $loc->parent;
        $guard = 0;
        while ($cur !== null && $guard++ < 28) {
            $parts[] = mb_strtolower(trim($cur->localizedName()), 'UTF-8');
            if (! $cur->relationLoaded('parent')) {
                $cur->load('parent');
            }
            $cur = $cur->parent;
        }

        return implode(' ', array_filter($parts, static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function locationMatchesAllSearchTokens(Location $loc, array $tokens): bool
    {
        $haystack = $this->locationSearchHaystack($loc);
        foreach ($tokens as $t) {
            if (! str_contains($haystack, $t)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lower rank value = sort earlier (better).
     *
     * @param  list<string>  $tokens
     */
    private function multiTokenRank(Location $loc, array $tokens): array
    {
        $nameLower = mb_strtolower(trim((string) $loc->name), 'UTF-8');
        $t0 = $tokens[0];
        $exactName = $nameLower === $t0 ? 0 : 1;
        $startsName = str_starts_with($nameLower, $t0) ? 0 : 1;
        $wordBoundary = preg_match('/(^|[\s,\-])'.preg_quote($t0, '/').'($|[\s,\-])/u', $this->locationSearchHaystack($loc)) ? 0 : 1;

        $typeOrder = match ((string) ($loc->type ?? '')) {
            'village' => 0,
            'suburb' => 1,
            'city' => 2,
            'taluka' => 3,
            'district' => 4,
            'state' => 5,
            'country' => 6,
            default => 7,
        };

        return [$exactName, $startsName, $wordBoundary, $typeOrder, mb_strlen($nameLower)];
    }

    /**
     * @param  list<string>  $tokens
     */
    private function compareMultiTokenSearchRank(Location $a, Location $b, array $tokens): int
    {
        $ra = $this->multiTokenRank($a, $tokens);
        $rb = $this->multiTokenRank($b, $tokens);
        foreach ($ra as $i => $v) {
            $cmp = $v <=> ($rb[$i] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return strcmp((string) $a->name, (string) $b->name);
    }

    /**
     * @return array<int, array{id:int,name:string,type:string,display_label:string,distance_km:float}>
     */
    public function getNearbyLocations(int $locationId, int $radiusKm = 10, ?string $type = null): array
    {
        $radiusKm = max(1, $radiusKm);

        $source = Location::query()->whereKey($locationId)->first();

        if ($source === null) {
            Log::info('Nearby search skipped due to missing coordinates', [
                'location_id' => $locationId,
                'reason' => 'source_location_missing',
            ]);

            return [];
        }

        if ($source->latitude === null || $source->longitude === null) {
            Log::info('Nearby search skipped due to missing coordinates', [
                'location_id' => $locationId,
                'reason' => 'source_coordinates_missing',
            ]);

            return [];
        }

        $sourceLat = (float) $source->latitude;
        $sourceLng = (float) $source->longitude;

        $candidates = Location::query()
            ->select(['id', 'latitude', 'longitude'])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('id', '!=', $locationId)
            ->get();

        $distanceByLocation = [];
        foreach ($candidates as $candidate) {
            $distance = $this->haversineDistanceKm(
                $sourceLat,
                $sourceLng,
                (float) $candidate->latitude,
                (float) $candidate->longitude
            );

            if ($distance > $radiusKm) {
                continue;
            }

            $candidateLocationId = (int) $candidate->id;
            if (! isset($distanceByLocation[$candidateLocationId]) || $distance < $distanceByLocation[$candidateLocationId]) {
                $distanceByLocation[$candidateLocationId] = $distance;
            }
        }

        if ($distanceByLocation === []) {
            return [];
        }

        $locationsQuery = Location::query()
            ->with('parent')
            ->whereIn('id', array_keys($distanceByLocation))
            ->where('is_active', true);

        if ($type !== null && $type !== '') {
            $locationsQuery->where('type', $type);
        }

        $locations = $locationsQuery->get();

        $this->hydrateParentChain($locations);

        return $locations
            ->map(function (Location $location) use ($distanceByLocation): array {
                $distance = $distanceByLocation[(int) $location->id] ?? null;

                return [
                    'id' => (int) $location->id,
                    'name' => (string) $location->name,
                    'type' => (string) $location->type,
                    'display_label' => $this->getDisplayLabel($location),
                    'distance_km' => $distance !== null ? round((float) $distance, 2) : 0.0,
                ];
            })
            ->sortBy('distance_km')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{profile_id:int,name:string,location_id:int,location_label:string,distance_km:float}>
     */
    public function getNearbyProfiles(int $locationId, int $radiusKm = 10, ?string $type = null): array
    {
        $nearbyLocations = $this->getNearbyLocations($locationId, $radiusKm, $type);
        if ($nearbyLocations === []) {
            return [];
        }

        $locationById = [];
        foreach ($nearbyLocations as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $locationById[$id] = $row;
        }

        if ($locationById === []) {
            return [];
        }

        $locationIds = array_keys($locationById);
        $profiles = MatrimonyProfile::query()
            ->select(['id', 'full_name', 'location_id'])
            ->whereIn('location_id', $locationIds)
            ->get();

        return $profiles
            ->map(function (MatrimonyProfile $profile) use ($locationById): ?array {
                $locationId = (int) ($profile->location_id ?? 0);
                if ($locationId <= 0 || ! isset($locationById[$locationId])) {
                    return null;
                }

                $locationRow = $locationById[$locationId];

                return [
                    'profile_id' => (int) $profile->id,
                    'name' => (string) ($profile->full_name ?? ''),
                    'location_id' => $locationId,
                    'location_label' => (string) ($locationRow['display_label'] ?? ''),
                    'distance_km' => (float) ($locationRow['distance_km'] ?? 0.0),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function haversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Batch-load ancestors for a single {@see Location} (same logic as search/nearby hydration).
     * Call before {@see getFullHierarchy()} when parents may not be eager-loaded.
     */
    public function ensureAncestorsLoaded(Location $location): void
    {
        $this->hydrateParentChain(collect([$location]));
    }

    /**
     * Preload parent chain in small batches and attach loaded relations to avoid N+1 reads.
     *
     * @param  \Illuminate\Support\Collection<int, Location>  $locations
     */
    private function hydrateParentChain($locations): void
    {
        if ($locations->isEmpty()) {
            return;
        }

        $known = [];
        foreach ($locations as $location) {
            $known[(int) $location->id] = $location;
        }

        $pendingParentIds = [];
        foreach ($locations as $location) {
            if ($location->parent_id !== null && ! isset($known[(int) $location->parent_id])) {
                $pendingParentIds[(int) $location->parent_id] = true;
            }
        }

        while ($pendingParentIds !== []) {
            $batchIds = array_keys($pendingParentIds);
            $pendingParentIds = [];

            $parents = Location::query()
                ->with('parent')
                ->whereIn('id', $batchIds)
                ->get();

            foreach ($parents as $parent) {
                $known[(int) $parent->id] = $parent;
            }

            foreach ($parents as $parent) {
                if ($parent->parent_id !== null && ! isset($known[(int) $parent->parent_id])) {
                    $pendingParentIds[(int) $parent->parent_id] = true;
                }
            }
        }

        foreach ($known as $node) {
            if ($node->parent_id !== null && isset($known[(int) $node->parent_id])) {
                $node->setRelation('parent', $known[(int) $node->parent_id]);
            }
        }
    }
}
