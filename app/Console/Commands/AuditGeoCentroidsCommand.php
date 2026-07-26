<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Location\GeoCentroidBackfillService;
use App\Support\SchemaPresence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only quality audit for the derived taluka / district coordinates.
 *
 * This is the verification twin of {@see BackfillGeoCentroidsCommand}: that one WRITES centres, this
 * one never writes anything and only reports whether the centres can be trusted for a "nearby"
 * decision. Run it after every address import, after `locations:update-village-coordinates`, and
 * after every centroid backfill.
 *
 * Why it exists: `addresses` village coordinates were geocoded by NAME, not by LGD code, so a village
 * can carry the coordinate of a same-named village 600 km away. The measured effect on the 2026-07
 * Maharashtra data was that ~26% of village coordinates sit more than 25 km from their own taluka's
 * centre, and a handful of talukas (Gaganbawada, Talasari, Ajra, Shegaon, Kalyan, Malwan, Radhanagari)
 * had a MAJORITY of wrong villages, which puts the median itself in the wrong district. A centre like
 * that produces confidently wrong "nearby" answers, which is worse than having no centre at all.
 *
 * The three acceptance checks below are what separate a usable centre from a wrong one. A taluka that
 * fails any of them must keep a NULL coordinate and degrade to the plain hierarchy rule (same district
 * = nearby) — never to a guessed centre.
 */
class AuditGeoCentroidsCommand extends Command
{
    protected $signature = 'locations:audit-geo-centroids
                            {--state=maharashtra : State slug to audit (must have bounds in GeoCentroidBackfillService)}
                            {--radius=25 : Km around a taluka centre that counts as "its own village"}
                            {--consensus=0.70 : Min share of villages that must fall inside that radius}
                            {--district-max=100 : Max km a taluka centre may sit from its district centre}
                            {--list=25 : How many failing talukas to print}';

    protected $description = 'Audit (never write) the village coordinates and the derived taluka/district centres';

    /** Equirectangular km — accurate well past the scale of an Indian district and far cheaper than haversine. */
    private static function km(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        return 111.32 * sqrt(
            ($aLat - $bLat) ** 2 + ((($aLng - $bLng) * cos(deg2rad($aLat))) ** 2)
        );
    }

    /** Marginal median (median of latitudes, median of longitudes) — the robust centre. */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : (($values[$mid - 1] + $values[$mid]) / 2);
    }

    public function handle(): int
    {
        $stateSlug = strtolower(trim((string) $this->option('state')));
        $bounds = GeoCentroidBackfillService::STATE_BOUNDS[$stateSlug] ?? null;

        if ($bounds === null) {
            $this->error("No bounding box configured for state [{$stateSlug}]. "
                .'Add it to GeoCentroidBackfillService::STATE_BOUNDS first — an unchecked centre is worse than none.');

            return self::FAILURE;
        }

        $radius = (float) $this->option('radius');
        $consensusMin = (float) $this->option('consensus');
        $districtMax = (float) $this->option('district-max');
        $listLimit = (int) $this->option('list');

        $table = Location::geoTable();
        $state = DB::table($table)->where('hierarchy', 'state')->where('slug', $stateSlug)->first();

        if ($state === null) {
            $this->error("State row [{$stateSlug}] not found in {$table}.");

            return self::FAILURE;
        }

        $villages = DB::table("{$table} as v")
            ->join("{$table} as t", 'v.parent_id', '=', 't.id')
            ->join("{$table} as d", 't.parent_id', '=', 'd.id')
            ->where('v.hierarchy', 'village')
            ->where('d.parent_id', $state->id)
            ->select('v.lat', 'v.lng', 't.id as tid', 't.name as tal', 'd.id as did', 'd.name as dis')
            ->get();

        if ($villages->isEmpty()) {
            $this->error("No villages found under state [{$stateSlug}].");

            return self::FAILURE;
        }

        // ---- A. source defects -------------------------------------------------------------------
        $missing = $outOfBox = 0;
        $coordKeys = [];
        $byTaluka = [];
        $byDistrict = [];

        foreach ($villages as $v) {
            if ($v->lat === null || $v->lng === null || ((float) $v->lat === 0.0 && (float) $v->lng === 0.0)) {
                $missing++;

                continue;
            }

            $lat = (float) $v->lat;
            $lng = (float) $v->lng;

            if ($lat < $bounds['min_lat'] || $lat > $bounds['max_lat'] || $lng < $bounds['min_lng'] || $lng > $bounds['max_lng']) {
                $outOfBox++;

                continue; // excluded from every centre — a coordinate outside the state is never evidence
            }

            $coordKeys[$lat.','.$lng] = true;
            $byTaluka[$v->tid]['lat'][] = $lat;
            $byTaluka[$v->tid]['lng'][] = $lng;
            $byTaluka[$v->tid]['meta'] = [$v->dis.'/'.$v->tal, $v->did];
            $byDistrict[$v->did]['lat'][] = $lat;
            $byDistrict[$v->did]['lng'][] = $lng;
        }

        $usable = $villages->count() - $missing - $outOfBox;

        $this->line('');
        $this->info("SOURCE DATA — state={$stateSlug}");
        $this->line('  villages                : '.$villages->count());
        $this->line('  missing / (0,0) lat-lng : '.$missing);
        $this->line('  outside state bounds    : '.$outOfBox.'  (excluded from every centre)');
        $this->line('  usable                  : '.$usable);
        $this->line('  distinct coordinates    : '.count($coordKeys)
            .'  → '.round(100 - (100 * count($coordKeys) / max(1, $usable)), 1).'% of villages reuse another village\'s coordinate');

        // ---- district centres --------------------------------------------------------------------
        $districtCentre = [];
        foreach ($byDistrict as $did => $pts) {
            $districtCentre[$did] = [self::median($pts['lat']), self::median($pts['lng'])];
        }

        // ---- B. mean vs median + the three acceptance checks --------------------------------------
        $shiftBuckets = [10 => 0, 25 => 0, 50 => 0];
        $failReasons = ['bounds' => 0, 'consensus' => 0, 'district' => 0];
        $pass = 0;
        $failures = [];

        foreach ($byTaluka as $tid => $pts) {
            [$label, $did] = $pts['meta'];
            $n = count($pts['lat']);

            $medLat = self::median($pts['lat']);
            $medLng = self::median($pts['lng']);
            $meanLat = array_sum($pts['lat']) / $n;
            $meanLng = array_sum($pts['lng']) / $n;

            $shift = self::km($medLat, $medLng, $meanLat, $meanLng);
            foreach (array_keys($shiftBuckets) as $limit) {
                if ($shift > $limit) {
                    $shiftBuckets[$limit]++;
                }
            }

            $near = 0;
            foreach ($pts['lat'] as $i => $lat) {
                if (self::km($medLat, $medLng, $lat, $pts['lng'][$i]) <= $radius) {
                    $near++;
                }
            }
            $consensus = $near / $n;

            $toDistrict = isset($districtCentre[$did])
                ? self::km($medLat, $medLng, $districtCentre[$did][0], $districtCentre[$did][1])
                : INF;

            $okBounds = $medLat >= $bounds['min_lat'] && $medLat <= $bounds['max_lat']
                && $medLng >= $bounds['min_lng'] && $medLng <= $bounds['max_lng'];
            $okConsensus = $consensus >= $consensusMin;
            $okDistrict = $toDistrict <= $districtMax;

            if ($okBounds && $okConsensus && $okDistrict) {
                $pass++;

                continue;
            }

            if (! $okBounds) {
                $failReasons['bounds']++;
            }
            if (! $okConsensus) {
                $failReasons['consensus']++;
            }
            if (! $okDistrict) {
                $failReasons['district']++;
            }

            $failures[] = sprintf(
                '%-42s villages=%-4d consensus=%.2f  toDistrict=%.0fkm  [%s]',
                $label, $n, $consensus, $toDistrict,
                implode(',', array_keys(array_filter([
                    'bounds' => ! $okBounds, 'consensus' => ! $okConsensus, 'district' => ! $okDistrict,
                ])))
            );
        }

        $total = count($byTaluka);

        $this->line('');
        $this->info('MEAN vs MEDIAN (talukas whose centre moves when you switch)');
        $this->line('  >10 km : '.$shiftBuckets[10]);
        $this->line('  >25 km : '.$shiftBuckets[25]);
        $this->line('  >50 km : '.$shiftBuckets[50].'   → median is the only defensible choice');

        $this->line('');
        $this->info('ACCEPTANCE — centre must be in-bounds, agreed by its own villages, and near its district');
        $this->line("  radius={$radius}km  consensus>={$consensusMin}  district-max={$districtMax}km");
        $this->line('  talukas    : '.$total);
        $this->line('  PASS       : '.$pass.' ('.round(100 * $pass / max(1, $total)).'%)');
        $this->line('  FAIL       : '.($total - $pass).'  → must stay NULL and fall back to same-district');
        $this->line('  reasons    : outside-bounds='.$failReasons['bounds']
            .'  low-consensus='.$failReasons['consensus']
            .'  far-from-district='.$failReasons['district']);

        if ($listLimit > 0 && $failures !== []) {
            $this->line('');
            $this->warn('FAILING TALUKAS (first '.min($listLimit, count($failures)).' of '.count($failures).')');
            foreach (array_slice($failures, 0, $listLimit) as $line) {
                $this->line('  '.$line);
            }
        }

        // ---- C. drift against whatever is already stored ------------------------------------------
        $tracksSource = SchemaPresence::hasColumn($table, 'geo_source');

        $stored = DB::table("{$table} as t")
            ->join("{$table} as d", 't.parent_id', '=', 'd.id')
            ->where('t.hierarchy', 'taluka')
            ->where('d.parent_id', $state->id)
            ->whereNotNull('t.lat')
            ->whereNotNull('t.lng')
            ->select(array_merge(
                ['t.id', 't.lat', 't.lng'],
                $tracksSource ? ['t.geo_source'] : []
            ))
            ->get();

        if ($stored->isNotEmpty()) {
            $drift = 0;
            $orphan = 0;
            $manual = 0;
            foreach ($stored as $row) {
                // An owner-supplied centre is deliberately NOT the village median — that median is
                // exactly what the acceptance gate refused. Measuring it against the median and calling
                // the difference "stale" would report a permanent false alarm and invite a --force that
                // (correctly) refuses to change anything.
                if ($tracksSource && ($row->geo_source ?? null) === GeoCentroidBackfillService::SOURCE_MANUAL) {
                    $manual++;

                    continue;
                }

                if (! isset($byTaluka[$row->id])) {
                    $orphan++;

                    continue;
                }
                $pts = $byTaluka[$row->id];
                $d = self::km(
                    (float) $row->lat, (float) $row->lng,
                    self::median($pts['lat']), self::median($pts['lng'])
                );
                if ($d > 1.0) {
                    $drift++;
                }
            }
            $this->line('');
            $this->info('STORED CENTRES');
            $this->line('  taluka rows with lat/lng   : '.$stored->count());
            $this->line('  owner-set (geo_source='.GeoCentroidBackfillService::SOURCE_MANUAL.'): '.$manual
                .'   (excluded from the drift check — not derived from villages, never recomputed)');
            $this->line('  >1 km from recomputed median: '.$drift.'   (stale — re-run the backfill with --force)');
            $this->line('  stored but no usable villages: '.$orphan.'   (must be NULLed, the centre has no evidence)');
        } else {
            $this->line('');
            $this->comment('STORED CENTRES: none yet — backfill has not run for this state.');
        }

        $this->line('');

        return $pass === $total ? self::SUCCESS : self::FAILURE;
    }
}
