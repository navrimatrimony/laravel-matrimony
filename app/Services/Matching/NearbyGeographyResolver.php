<?php

namespace App\Services\Matching;

use App\Models\Location;
use App\Services\Location\LocationService;
use App\Support\SchemaPresence;
use Illuminate\Support\Facades\DB;

/**
 * Memoised geography proximity for the matching engine.
 *
 * This deliberately owns NO distance maths of its own — the haversine implementation and the
 * bounding-box strategy already live in {@see LocationService::nearbyTalukasByCoordinate()} (which
 * {@see \App\Services\PartnerPreferenceSuggestionService::defaultLocationPivotsFromNearbyTalukas()}
 * also consumes). Writing a second one would violate the frozen no-duplicate rule, so this class is a
 * per-run cache in front of that single helper: matching compares up to 200 candidates in both
 * directions and must not re-run the geo scan per pair.
 *
 * {@see flush()} is called once at the start of each matching run so the cache never outlives it.
 *
 * The expensive half of this used to be {@see coordinateFor()} falling through to an AVG over the
 * child/grandchild rows, because taluka and district rows had never been geocoded. They now carry
 * their derived centroid in their own `addresses.lat` / `addresses.lng`
 * ({@see \App\Services\Location\GeoCentroidBackfillService}), so step 1 answers immediately and the
 * aggregates below are a fallback for un-backfilled rows only — not the normal path.
 */
final class NearbyGeographyResolver
{
    /** @var array<string, list<int>> */
    private static array $nearbyTalukaCache = [];

    /** @var array<string, list<int>> */
    private static array $nearbyDistrictCache = [];

    /** @var array<int, int|null> */
    private static array $talukaDistrictCache = [];

    /** @var array<int, array{lat: float, lng: float}|null> */
    private static array $coordinateCache = [];

    public static function flush(): void
    {
        self::$nearbyTalukaCache = [];
        self::$nearbyDistrictCache = [];
        self::$talukaDistrictCache = [];
        self::$coordinateCache = [];
    }

    public static function radiusKm(): int
    {
        return max(1, (int) config('matching.relaxation.nearby_radius_km', 75));
    }

    public static function limit(): int
    {
        return max(1, (int) config('matching.relaxation.nearby_limit', 12));
    }

    /**
     * Talukas within {@see radiusKm()} of the given taluka, including the taluka itself.
     *
     * @return list<int>
     */
    public static function nearbyTalukaIds(int $talukaId): array
    {
        $talukaId = (int) $talukaId;
        if ($talukaId <= 0) {
            return [];
        }
        $key = $talukaId.'@'.self::radiusKm().'/'.self::limit();
        if (isset(self::$nearbyTalukaCache[$key])) {
            return self::$nearbyTalukaCache[$key];
        }

        $coordinate = self::coordinateFor($talukaId);
        if ($coordinate === null) {
            return self::$nearbyTalukaCache[$key] = [$talukaId];
        }

        $rows = app(LocationService::class)->nearbyTalukasByCoordinate(
            $coordinate['lat'],
            $coordinate['lng'],
            self::radiusKm(),
            self::limit(),
        );

        $ids = [$talukaId => true];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return self::$nearbyTalukaCache[$key] = array_keys($ids);
    }

    /**
     * Union of the nearby talukas of every supplied taluka.
     *
     * @param  iterable<mixed>  $talukaIds
     * @return list<int>
     */
    public static function nearbyTalukaIdsForAny(iterable $talukaIds): array
    {
        $out = [];
        foreach ($talukaIds as $talukaId) {
            foreach (self::nearbyTalukaIds((int) $talukaId) as $id) {
                $out[$id] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Districts within {@see radiusKm()} of the given district, including the district itself.
     * Derived from the same taluka scan — nearby talukas roll up to their parent districts.
     *
     * @return list<int>
     */
    public static function nearbyDistrictIds(int $districtId): array
    {
        $districtId = (int) $districtId;
        if ($districtId <= 0) {
            return [];
        }
        $key = $districtId.'@'.self::radiusKm().'/'.self::limit();
        if (isset(self::$nearbyDistrictCache[$key])) {
            return self::$nearbyDistrictCache[$key];
        }

        $coordinate = self::coordinateFor($districtId);
        if ($coordinate === null) {
            return self::$nearbyDistrictCache[$key] = [$districtId];
        }

        $rows = app(LocationService::class)->nearbyTalukasByCoordinate(
            $coordinate['lat'],
            $coordinate['lng'],
            self::radiusKm(),
            self::limit(),
        );

        // One batched parent lookup for the whole row set instead of one query per row. Same answers,
        // same order — only the number of round trips changes.
        self::warmTalukaDistrictMemo(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows
        ));

        $ids = [$districtId => true];
        foreach ($rows as $row) {
            $parent = self::districtIdForTaluka((int) ($row['id'] ?? 0));
            if ($parent !== null && $parent > 0) {
                $ids[$parent] = true;
            }
        }

        return self::$nearbyDistrictCache[$key] = array_keys($ids);
    }

    /**
     * @param  iterable<mixed>  $districtIds
     * @return list<int>
     */
    public static function nearbyDistrictIdsForAny(iterable $districtIds): array
    {
        $out = [];
        foreach ($districtIds as $districtId) {
            foreach (self::nearbyDistrictIds((int) $districtId) as $id) {
                $out[$id] = true;
            }
        }

        return array_keys($out);
    }

    public static function districtIdForTaluka(int $talukaId): ?int
    {
        $talukaId = (int) $talukaId;
        if ($talukaId <= 0) {
            return null;
        }
        if (array_key_exists($talukaId, self::$talukaDistrictCache)) {
            return self::$talukaDistrictCache[$talukaId];
        }
        if (! self::geoTableExists()) {
            return self::$talukaDistrictCache[$talukaId] = null;
        }

        $parentId = DB::table(Location::geoTable())->where('id', $talukaId)->value('parent_id');
        $parentId = $parentId !== null ? (int) $parentId : 0;

        return self::$talukaDistrictCache[$talukaId] = $parentId > 0 ? $parentId : null;
    }

    /**
     * District ids of the supplied talukas.
     *
     * @param  iterable<mixed>  $talukaIds
     * @return list<int>
     */
    public static function districtIdsForTalukas(iterable $talukaIds): array
    {
        $talukaIds = is_array($talukaIds) ? $talukaIds : iterator_to_array($talukaIds, false);
        self::warmTalukaDistrictMemo(array_map('intval', $talukaIds));

        $out = [];
        foreach ($talukaIds as $talukaId) {
            $districtId = self::districtIdForTaluka((int) $talukaId);
            if ($districtId !== null) {
                $out[$districtId] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Resolve a batch of taluka → district parents in ONE query and seed the per-run memo with them.
     * Purely a round-trip saving: {@see districtIdForTaluka()} produces the identical value per id.
     *
     * @param  list<int>  $talukaIds
     */
    private static function warmTalukaDistrictMemo(array $talukaIds): void
    {
        $missing = [];
        foreach ($talukaIds as $talukaId) {
            $talukaId = (int) $talukaId;
            if ($talukaId > 0 && ! array_key_exists($talukaId, self::$talukaDistrictCache)) {
                $missing[$talukaId] = true;
            }
        }

        if (count($missing) < 2 || ! self::geoTableExists()) {
            return;
        }

        $rows = DB::table(Location::geoTable())
            ->whereIn('id', array_keys($missing))
            ->get(['id', 'parent_id']);

        foreach ($rows as $row) {
            $parentId = $row->parent_id !== null ? (int) $row->parent_id : 0;
            self::$talukaDistrictCache[(int) $row->id] = $parentId > 0 ? $parentId : null;
        }

        // Ids with no row at all must still be remembered as "no district", otherwise every caller
        // re-asks one by one and the batch buys nothing.
        foreach (array_keys($missing) as $talukaId) {
            self::$talukaDistrictCache[$talukaId] ??= null;
        }
    }

    /**
     * Own coordinate when geocoded, else the centroid of the row's geocoded direct children, else of
     * its grandchildren.
     *
     * Steps 2 and 3 are the formulas {@see \App\Services\Location\GeoCentroidBackfillService} persists
     * into the row's own `lat`/`lng`, so on a backfilled database step 1 always wins and neither
     * aggregate runs. They stay as the safety net for a row added after the last backfill run.
     *
     * @return array{lat: float, lng: float}|null
     */
    private static function coordinateFor(int $locationId): ?array
    {
        if (array_key_exists($locationId, self::$coordinateCache)) {
            return self::$coordinateCache[$locationId];
        }
        if (! self::geoTableExists()) {
            return self::$coordinateCache[$locationId] = null;
        }

        $geo = Location::geoTable();

        $own = DB::table($geo)->select(['lat', 'lng'])->where('id', $locationId)->first();
        if ($own !== null && $own->lat !== null && $own->lng !== null) {
            return self::$coordinateCache[$locationId] = ['lat' => (float) $own->lat, 'lng' => (float) $own->lng];
        }

        $child = DB::table($geo)
            ->selectRaw('AVG(lat) as lat, AVG(lng) as lng')
            ->where('parent_id', $locationId)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->first();
        if ($child !== null && $child->lat !== null && $child->lng !== null) {
            return self::$coordinateCache[$locationId] = ['lat' => (float) $child->lat, 'lng' => (float) $child->lng];
        }

        $grandchild = DB::table($geo.' as leaf')
            ->join($geo.' as mid', 'mid.id', '=', 'leaf.parent_id')
            ->selectRaw('AVG(leaf.lat) as lat, AVG(leaf.lng) as lng')
            ->where('mid.parent_id', $locationId)
            ->whereNotNull('leaf.lat')
            ->whereNotNull('leaf.lng')
            ->first();
        if ($grandchild !== null && $grandchild->lat !== null && $grandchild->lng !== null) {
            return self::$coordinateCache[$locationId] = ['lat' => (float) $grandchild->lat, 'lng' => (float) $grandchild->lng];
        }

        return self::$coordinateCache[$locationId] = null;
    }

    /** Process-level memo — table presence cannot change inside a process, but this is asked per taluka. */
    private static function geoTableExists(): bool
    {
        return SchemaPresence::hasTable(Location::geoTable());
    }
}
