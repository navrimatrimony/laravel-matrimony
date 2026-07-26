<?php

namespace App\Services\Location;

use App\Console\Commands\AuditGeoCentroidsCommand;
use App\Models\Location;
use App\Support\SchemaPresence;
use Illuminate\Support\Facades\DB;

/**
 * Derives `addresses.lat` / `addresses.lng` for taluka and district rows from the villages beneath them.
 *
 * `addresses` already carries `lat`/`lng` at every hierarchy level, but only village rows were ever
 * geocoded — every taluka and district had the columns sitting empty. Everything that needed a taluka's
 * position therefore re-derived it at request time with an AVG over the village rows. On production
 * that aggregate ran 63 times per suggestions request at ~255 ms a call: 16,081 ms, 73% of the whole
 * request, to recompute a geographic constant.
 *
 * The fact belongs on the address row itself, so this fills the EXISTING columns — no parallel column,
 * no side table, no cache key (frozen no-duplicate rule). Being a real column, it survives
 * `cache:clear` / `optimize:clear` with no cold-start stall.
 *
 * ---------------------------------------------------------------------------------------------------
 * WHY THIS IS SO DEFENSIVE
 * ---------------------------------------------------------------------------------------------------
 * The village coordinates are NOT authoritative. LGD publishes no coordinates, so `addresses` was
 * geocoded BY NAME — and same-named villages across Maharashtra were handed each other's points. The
 * 2026-07 audit measured it: 44,853 MH villages hold only 10,220 distinct coordinates, i.e. 77.2% of
 * villages carry some other village's point; 25.8% sit more than 25 km from their own taluka's median
 * and 9.1% more than 100 km away. Every one of those bad points is INSIDE Maharashtra, so a bounding
 * box catches exactly zero of them.
 *
 * A median absorbs a minority of bad points but not a majority: Kolhapur/Gaganbawada has 29 of its 42
 * villages geocoded near Akola, so its median lands 635 km from the real taluka. A centre like that
 * produces confidently WRONG "nearby" answers, which is strictly worse than having no centre — a
 * missing centre degrades to the hierarchy rule and merely narrows the pool, it never mis-points it.
 *
 * Hence {@see accept()}: three checks, all must pass, and a failure writes NOTHING.
 *
 *   1. BOUNDS      — the centre is inside the state box. Cheap, catches nothing on this data, kept as
 *                    the floor of sanity.
 *   2. CONSENSUS   — >= 70% of the taluka's villages lie within 25 km of the median. Maharashtra
 *                    talukas are 20-40 km across and the measured consensus distribution is
 *                    p25=0.64 / p50=0.82 / p90=0.95, so 0.70 separates "a few bad villages" from
 *                    "the median itself followed the bad villages".
 *   3. DISTRICT    — the centre sits <= 100 km from its own district's centre. From the accepted
 *                    population: p50=32.7 / p90=67 / p95=76.4 / p99=94.8 / max=139.2 km. This is the
 *                    check that catches a Sangli taluka landing near Nashik.
 *
 * Against OpenStreetMap on 20 talukas, the ACCEPTED centres are median 14.2 km / max 24.4 km off —
 * ample for a 75 km "nearby" decision. All 7 catastrophic cases (196-635 km) are rejected. A few safe
 * ones are also rejected; that is the correct direction to err.
 *
 * Districts are checked on BOUNDS only: a district centre is a median over tens of thousands of
 * villages, which no minority of bad points can move, and a 25 km consensus radius is meaningless for
 * a body that size.
 *
 * ---------------------------------------------------------------------------------------------------
 * SCOPE
 * ---------------------------------------------------------------------------------------------------
 * One state at a time, resolved through `addresses.slug`, never a hardcoded id. Only Maharashtra is
 * enabled today. Adding a state is one entry in {@see STATE_BOUNDS} — no schema change.
 *
 * Verify with `php artisan locations:audit-geo-centroids --state=maharashtra`
 * ({@see AuditGeoCentroidsCommand}), which applies these same three checks read-only and exits 1 if a
 * WRITTEN centre would fail them. Recompute with `php artisan locations:backfill-geo-centroids --force`.
 *
 * ---------------------------------------------------------------------------------------------------
 * OWNER-SUPPLIED CENTRES ARE NOT DERIVED, AND ARE NEVER RECOMPUTED
 * ---------------------------------------------------------------------------------------------------
 * A rejected taluka keeps a NULL centre until somebody who knows the ground supplies one. That value is
 * NOT evidence from the villages — recomputing it would replace a known-correct point with the very
 * median the gate already refused, and `--force` would additionally CLEAR it (see the rejection branch
 * below). So a row whose {@see SOURCE_MANUAL} stamp says "a human put this here" is skipped before
 * either branch is reached, `--force` included.
 *
 * The stamp lives in the EXISTING `addresses.geo_source` column, which already answers "where did this
 * row's current coordinate come from?" for villages
 * ({@see \App\Console\Commands\RepairVillageCoordinatesCommand}). Same question, same column, same
 * vocabulary — no `is_manual` flag, no override table, no second source of truth (frozen no-duplicate
 * rule). This service simply extends that column upward to taluka / district rows, stamping what it
 * derives as {@see SOURCE_VILLAGE_MEDIAN} so every populated centre says who set it.
 */
final class GeoCentroidBackfillService
{
    /**
     * States this backfill is enabled for, keyed by `addresses.slug` of the state row, with the hard
     * coordinate bounds for each. Widening to another state = one more entry here.
     *
     * Public because {@see AuditGeoCentroidsCommand} reads it — the auditor and the writer must never
     * disagree about what "inside the state" means.
     *
     * @var array<string, array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}>
     */
    public const STATE_BOUNDS = [
        'maharashtra' => ['min_lat' => 15.5, 'max_lat' => 22.2, 'min_lng' => 72.5, 'max_lng' => 80.9],
    ];

    public const DEFAULT_STATE = 'maharashtra';

    /** Km around a taluka centre that counts as "its own village". */
    public const CONSENSUS_RADIUS_KM = 25.0;

    /** Share of a taluka's villages that must fall inside {@see CONSENSUS_RADIUS_KM} of its median. */
    public const MIN_CONSENSUS = 0.70;

    /** Furthest a taluka centre may sit from its own district's centre. */
    public const MAX_DISTRICT_DISTANCE_KM = 100.0;

    /** A centre needs at least one village behind it. */
    public const MIN_SAMPLE = 1;

    /**
     * `addresses.geo_source` value meaning "a human supplied this taluka / district centre by hand".
     * Rows carrying it are never recomputed and never cleared by this service, `--force` included.
     */
    public const SOURCE_MANUAL = 'owner_manual';

    /** `addresses.geo_source` value stamped on the centres this service derives from village medians. */
    public const SOURCE_VILLAGE_MEDIAN = 'taluka_village_median';

    /** Rows per write batch. Keeps a few-hundred-row backfill off one giant statement. */
    private const BATCH = 250;

    /**
     * @return array{filled: int, skipped: int, manual: int, without_source: int, rejected: int, rejections: array<string, int>, state: string}
     */
    public function backfillDistricts(bool $force = false, string $stateSlug = self::DEFAULT_STATE): array
    {
        return $this->backfill('district', $force, $stateSlug);
    }

    /**
     * Run AFTER {@see backfillDistricts()} for readability — the district centre used by check 3 is
     * recomputed from the villages here rather than read from the column, so the order cannot change
     * the outcome and a rejected district can never cascade into rejecting its talukas.
     *
     * @return array{filled: int, skipped: int, manual: int, without_source: int, rejected: int, rejections: array<string, int>, state: string}
     */
    public function backfillTalukas(bool $force = false, string $stateSlug = self::DEFAULT_STATE): array
    {
        return $this->backfill('taluka', $force, $stateSlug);
    }

    /**
     * @return list<string>  Slugs this service is configured for.
     */
    public static function supportedStates(): array
    {
        return array_keys(self::STATE_BOUNDS);
    }

    /**
     * The single acceptance gate. Everything that can refuse a computed centre lives here, so the
     * thresholds can be tightened in one place and the auditor keeps agreeing with the writer.
     *
     * @param  array{lat: float, lng: float}  $centre
     * @param  list<float>  $lats  The sample the centre was computed from.
     * @param  list<float>  $lngs
     * @param  array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}  $bounds
     * @param  array{lat: float, lng: float}|null  $districtCentre  Null for a district (checks 2 and 3 do not apply).
     * @return string|null  Null when the centre may be written; otherwise the reason it was refused.
     */
    public static function accept(array $centre, array $lats, array $lngs, array $bounds, ?array $districtCentre): ?string
    {
        if (count($lats) < self::MIN_SAMPLE) {
            return 'no_sample';
        }

        if ($centre['lat'] < $bounds['min_lat'] || $centre['lat'] > $bounds['max_lat']
            || $centre['lng'] < $bounds['min_lng'] || $centre['lng'] > $bounds['max_lng']) {
            return 'bounds';
        }

        // Districts stop here: their median is over tens of thousands of villages and a 25 km
        // consensus radius does not describe a body that size.
        if ($districtCentre === null) {
            return null;
        }

        $near = 0;
        foreach ($lats as $i => $lat) {
            if (self::km($centre['lat'], $centre['lng'], $lat, $lngs[$i]) <= self::CONSENSUS_RADIUS_KM) {
                $near++;
            }
        }

        if ($near / count($lats) < self::MIN_CONSENSUS) {
            return 'consensus';
        }

        if (self::km($centre['lat'], $centre['lng'], $districtCentre['lat'], $districtCentre['lng'])
            > self::MAX_DISTRICT_DISTANCE_KM) {
            return 'district';
        }

        return null;
    }

    /**
     * Equirectangular km. Identical to the private helper in {@see AuditGeoCentroidsCommand} on
     * purpose: the auditor must reach the same verdict as the writer, to the metre. Accurate far past
     * the scale of an Indian district and much cheaper than haversine
     * ({@see LocationService::haversineDistanceKm()} remains the one used for user-facing distances).
     */
    public static function km(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        return 111.32 * sqrt(
            ($aLat - $bLat) ** 2 + ((($aLng - $bLng) * cos(deg2rad($aLat))) ** 2)
        );
    }

    /**
     * Marginal median: the median latitude paired with the median longitude. Not a geometric median —
     * that needs iteration and buys nothing here, because the defect being defended against is a set
     * of grossly mis-geocoded villages, which a per-axis median already absorbs. A MEAN is not an
     * option: the audit found talukas whose centre moves more than 50 km between mean and median.
     *
     * @param  list<float>  $lats
     * @param  list<float>  $lngs
     * @return array{lat: float, lng: float}
     */
    public static function centreOf(array $lats, array $lngs): array
    {
        return ['lat' => self::median($lats), 'lng' => self::median($lngs)];
    }

    /**
     * @param  list<float>  $values
     */
    private static function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @return array{filled: int, skipped: int, manual: int, without_source: int, rejected: int, rejections: array<string, int>, state: string}
     */
    private function backfill(string $hierarchy, bool $force, string $stateSlug): array
    {
        $empty = [
            'filled' => 0, 'skipped' => 0, 'manual' => 0, 'without_source' => 0, 'rejected' => 0,
            'rejections' => [], 'state' => $stateSlug,
        ];

        $bounds = self::STATE_BOUNDS[$stateSlug] ?? null;
        $geo = Location::geoTable();

        if ($bounds === null
            || ! SchemaPresence::hasTable($geo)
            || ! SchemaPresence::hasColumn($geo, 'lat')
            || ! SchemaPresence::hasColumn($geo, 'lng')) {
            return $empty;
        }

        $stateId = (int) (DB::table($geo)
            ->where('hierarchy', 'state')
            ->where('slug', $stateSlug)
            ->value('id') ?? 0);

        if ($stateId <= 0) {
            return $empty;
        }

        $targets = $this->targetRows($geo, $hierarchy, $stateId);
        if ($targets === []) {
            return $empty;
        }

        $samples = $this->villageSamples($geo, $stateId, $bounds);

        // Recomputed from the same sample rather than read back from the column, so district ordering
        // and any earlier rejection cannot influence a taluka's verdict.
        $districtCentres = [];
        foreach ($samples['district'] as $districtId => $sample) {
            $districtCentres[$districtId] = self::centreOf($sample['lats'], $sample['lngs']);
        }

        $filled = 0;
        $skipped = 0;
        $manual = 0;
        $withoutSource = 0;
        $rejected = 0;
        $rejections = [];
        $pending = [];

        foreach ($targets as $id => $target) {
            // FIRST, ahead of --force: an owner-supplied centre is not derived from anything here, so
            // there is nothing to recompute and nothing this service is entitled to clear.
            if ($target['is_manual']) {
                $manual++;

                continue;
            }

            // Idempotent by default: a populated coordinate is left exactly as it is, so a re-run (or a
            // replayed migration) can never overwrite a hand-corrected position.
            if ($target['has_coordinate'] && ! $force) {
                $skipped++;

                continue;
            }

            $sample = $samples[$hierarchy][$id] ?? null;
            if ($sample === null || $sample['lats'] === []) {
                $withoutSource++;

                continue;
            }

            $centre = self::centreOf($sample['lats'], $sample['lngs']);
            $districtCentre = $hierarchy === 'district'
                ? null
                : ($districtCentres[$target['district_id']] ?? null);

            $reason = $hierarchy === 'district'
                ? self::accept($centre, $sample['lats'], $sample['lngs'], $bounds, null)
                : ($districtCentre === null
                    ? 'district'
                    : self::accept($centre, $sample['lats'], $sample['lngs'], $bounds, $districtCentre));

            if ($reason !== null) {
                $rejected++;
                $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;

                // A refused centre must leave the row alone. With --force that means an existing value
                // is CLEARED — keeping a value the current checks reject would be the worst of both.
                // (Manual rows never reach here; they were skipped above.)
                if ($force && $target['has_coordinate']) {
                    $cleared = ['lat' => null, 'lng' => null];
                    if ($this->tracksSource($geo)) {
                        $cleared['geo_source'] = null;
                    }
                    DB::table($geo)->where('id', $id)->update($cleared);
                }

                continue;
            }

            $pending[$id] = $centre;
            if (count($pending) >= self::BATCH) {
                $filled += $this->flushBatch($geo, $pending);
                $pending = [];
            }
        }

        if ($pending !== []) {
            $filled += $this->flushBatch($geo, $pending);
        }

        return [
            'filled' => $filled,
            'skipped' => $skipped,
            'manual' => $manual,
            'without_source' => $withoutSource,
            'rejected' => $rejected,
            'rejections' => $rejections,
            'state' => $stateSlug,
        ];
    }

    /**
     * Whether provenance can be recorded at all. `addresses.geo_source` arrives in the
     * 2026_07_26_190000 migration; on a database that predates it the service still runs, it just
     * cannot distinguish a manual centre — so it degrades to the old "never overwrite without --force"
     * behaviour rather than refusing to work.
     */
    private function tracksSource(string $geo): bool
    {
        return SchemaPresence::hasColumn($geo, 'geo_source');
    }

    /**
     * @return array<int, array{has_coordinate: bool, is_manual: bool, district_id: int}>
     */
    private function targetRows(string $geo, string $hierarchy, int $stateId): array
    {
        $tracksSource = $this->tracksSource($geo);

        if ($hierarchy === 'district') {
            $columns = ['id', 'lat', 'lng'];
            if ($tracksSource) {
                $columns[] = 'geo_source';
            }

            $rows = DB::table($geo)
                ->where('parent_id', $stateId)
                ->where('hierarchy', 'district')
                ->get($columns);

            $out = [];
            foreach ($rows as $row) {
                $out[(int) $row->id] = [
                    'has_coordinate' => $row->lat !== null && $row->lng !== null,
                    'is_manual' => $tracksSource && ($row->geo_source ?? null) === self::SOURCE_MANUAL,
                    'district_id' => (int) $row->id,
                ];
            }

            return $out;
        }

        $columns = ['taluka.id as id', 'taluka.lat as lat', 'taluka.lng as lng', 'district.id as district_id'];
        if ($tracksSource) {
            $columns[] = 'taluka.geo_source as geo_source';
        }

        $rows = DB::table($geo.' as taluka')
            ->join($geo.' as district', 'district.id', '=', 'taluka.parent_id')
            ->where('district.parent_id', $stateId)
            ->where('taluka.hierarchy', 'taluka')
            ->get($columns);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = [
                'has_coordinate' => $row->lat !== null && $row->lng !== null,
                'is_manual' => $tracksSource && ($row->geo_source ?? null) === self::SOURCE_MANUAL,
                'district_id' => (int) $row->district_id,
            ];
        }

        return $out;
    }

    /**
     * Village coordinates grouped per taluka AND per district, already filtered exactly as
     * {@see AuditGeoCentroidsCommand} filters them: null / (0,0) dropped, anything outside the state
     * bounds dropped (a coordinate outside the state is never evidence about a place inside it).
     *
     * A district samples its VILLAGES, not the centres of its talukas: a median over taluka centres
     * weights a 12-village taluka the same as a 200-village one, and would inherit every rejected
     * taluka's problem.
     *
     * @param  array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}  $bounds
     * @return array{taluka: array<int, array{lats: list<float>, lngs: list<float>}>, district: array<int, array{lats: list<float>, lngs: list<float>}>}
     */
    private function villageSamples(string $geo, int $stateId, array $bounds): array
    {
        $out = ['taluka' => [], 'district' => []];

        DB::table($geo.' as village')
            ->join($geo.' as taluka', 'taluka.id', '=', 'village.parent_id')
            ->join($geo.' as district', 'district.id', '=', 'taluka.parent_id')
            ->where('district.parent_id', $stateId)
            ->where('village.hierarchy', 'village')
            ->whereNotNull('village.lat')
            ->whereNotNull('village.lng')
            ->select([
                'village.lat as lat',
                'village.lng as lng',
                'taluka.id as taluka_id',
                'district.id as district_id',
            ])
            ->orderBy('village.id')
            ->chunk(20000, function ($rows) use (&$out, $bounds): void {
                foreach ($rows as $row) {
                    $lat = (float) $row->lat;
                    $lng = (float) $row->lng;

                    if ($lat === 0.0 && $lng === 0.0) {
                        continue;
                    }
                    if ($lat < $bounds['min_lat'] || $lat > $bounds['max_lat']
                        || $lng < $bounds['min_lng'] || $lng > $bounds['max_lng']) {
                        continue;
                    }

                    $talukaId = (int) $row->taluka_id;
                    $districtId = (int) $row->district_id;

                    $out['taluka'][$talukaId]['lats'][] = $lat;
                    $out['taluka'][$talukaId]['lngs'][] = $lng;
                    $out['district'][$districtId]['lats'][] = $lat;
                    $out['district'][$districtId]['lngs'][] = $lng;
                }
            });

        return $out;
    }

    /**
     * Portable batched write — a MySQL multi-table `UPDATE … JOIN` would be faster but would not run on
     * the SQLite connection the test suite uses, and this is a maintenance path, not a hot one.
     *
     * @param  array<int, array{lat: float, lng: float}>  $pending
     */
    private function flushBatch(string $geo, array $pending): int
    {
        $written = 0;
        $tracksSource = $this->tracksSource($geo);

        DB::transaction(function () use ($geo, $pending, $tracksSource, &$written): void {
            foreach ($pending as $id => $centre) {
                $values = [
                    // `lat`/`lng` are decimal(10,7); rounding here rather than letting the driver do it
                    // keeps the written value and the checked value the same number.
                    'lat' => number_format($centre['lat'], 7, '.', ''),
                    'lng' => number_format($centre['lng'], 7, '.', ''),
                ];

                // Stamp what produced this pair, so a later reader (and this service's own next run)
                // can tell a derived centre from one a human set. Never overwrites SOURCE_MANUAL —
                // those rows never make it into $pending.
                if ($tracksSource) {
                    $values['geo_source'] = self::SOURCE_VILLAGE_MEDIAN;
                }

                $written += DB::table($geo)->where('id', $id)->update($values);
            }
        });

        return $written;
    }
}
