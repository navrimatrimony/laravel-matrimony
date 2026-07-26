<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Location\GeoCentroidBackfillService;
use App\Support\Location\NominatimClient;
use App\Support\Location\PostalDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY evaluation of the India Post office-directory CSV as a replacement coordinate source
 * for `addresses` village/taluka/district positions.
 *
 * THROWAWAY ANALYSIS TOOL. It writes NOTHING to the database. It exists to answer one question:
 * does the postal CSV beat our name-geocoded village coordinates when fed through the EXISTING
 * acceptance gate in {@see GeoCentroidBackfillService::accept()}?
 *
 *   php artisan geo:analyze-pincode-source
 *   php artisan geo:analyze-pincode-source --osm          (adds the Nominatim spot-check, ~25 calls)
 */
class AnalyzePincodeGeoSourceCommand extends Command
{
    protected $signature = 'geo:analyze-pincode-source
                            {--state=maharashtra}
                            {--csv= : Path to the all-india pincode CSV}
                            {--osm : Also run the Nominatim spot-check (slow, rate-limited)}
                            {--osm-extra=0 : Add N extra evenly-spaced talukas that pass under BOTH sources, so old and new can be scored on the same panel}
                            {--dump= : Write the raw per-taluka result JSON here}';

    protected $description = 'READ-ONLY: score the India Post pincode CSV against our current village coordinates';

    /**
     * The spot-check panel. Includes every taluka the 2026-07 audit named as catastrophic plus a
     * spread of currently-accepted ones. Kolhapur/Gaganbawada, Sangli/Khanapur and Sangli/Atpadi
     * are mandatory.
     *
     * @var list<array{0:string,1:string}>  [district, taluka]
     */
    private const OSM_PANEL = [
        ['Kolhapur', 'Gaganbawada'],
        ['Sangli', 'Khanapur'],
        ['Sangli', 'Atpadi'],
        ['Kolhapur', 'Ajra'],
        ['Kolhapur', 'Radhanagari'],
        ['Palghar', 'Talasari'],
        ['Buldhana', 'Shegaon'],
        ['Thane', 'Kalyan'],
        ['Sindhudurg', 'Malvan'],
        ['Pune', 'Baramati'],
        ['Pune', 'Haveli'],
        ['Nashik', 'Malegaon'],
        ['Nagpur', 'Katol'],
        ['Solapur', 'Pandharpur'],
        ['Satara', 'Karad'],
        ['Ratnagiri', 'Chiplun'],
        ['Latur', 'Ausa'],
        ['Nanded', 'Kinwat'],
        ['Yavatmal', 'Pusad'],
        ['Amravati', 'Achalpur'],
        ['Jalgaon', 'Bhusawal'],
        ['Beed', 'Ambejogai'],
    ];

    private array $json = [];

    public function handle(): int
    {
        $stateSlug = strtolower(trim((string) $this->option('state')));
        $bounds = GeoCentroidBackfillService::STATE_BOUNDS[$stateSlug] ?? null;
        if ($bounds === null) {
            $this->error("No bounds configured for [{$stateSlug}].");

            return self::FAILURE;
        }

        $csvPath = (string) ($this->option('csv')
            ?: 'E:/laravel backup/country,state,district,taluka,village,pincode/all india pincode/all india.csv');
        if (! is_file($csvPath)) {
            $this->error("CSV not found: {$csvPath}");

            return self::FAILURE;
        }

        $csv = $this->loadCsv($csvPath, $stateSlug, $bounds);
        $ours = $this->loadOurVillages($stateSlug, $bounds);

        $this->section0($csv, $ours);
        $matches = $this->section1Join($csv, $ours);
        $trust = $this->section2DistrictCrossCheck($csv, $ours, $matches);
        $this->section3CurrentError($csv, $ours, $matches, $trust);
        $centres = $this->section4Centres($csv, $ours, $matches, $trust, $bounds, $stateSlug);

        if ($this->option('osm')) {
            $this->section5Osm($centres);
        }

        $dump = (string) ($this->option('dump') ?: '');
        if ($dump !== '') {
            file_put_contents($dump, json_encode($this->json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("\nJSON dump → {$dump}");
        }

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- loading

    /**
     * @return array{rows: list<array{name:string,norm:string,loose:string,pin:string,district:string,lat:float,lng:float}>,
     *               byPin: array<string, list<int>>, stats: array<string,int>}
     */
    private function loadCsv(string $path, string $stateSlug, array $bounds): array
    {
        $dir = PostalDirectory::load($path, $stateSlug, $bounds);

        return ['rows' => $dir->rows, 'byPin' => $dir->byPin, 'stats' => $dir->stats];
    }

    /**
     * @return list<array{id:int,name:string,norm:string,loose:string,pin:string,lat:float,lng:float,
     *                    tid:int,did:int,tal:string,dis:string,disNorm:string,inBox:bool}>
     */
    private function loadOurVillages(string $stateSlug, array $bounds): array
    {
        $geo = Location::geoTable();
        $stateId = (int) DB::table($geo)->where('hierarchy', 'state')->where('slug', $stateSlug)->value('id');

        $out = [];
        DB::table("{$geo} as v")
            ->join("{$geo} as t", 't.id', '=', 'v.parent_id')
            ->join("{$geo} as d", 'd.id', '=', 't.parent_id')
            ->where('v.hierarchy', 'village')
            ->where('d.parent_id', $stateId)
            ->select('v.id', 'v.name', 'v.pincode', 'v.lat', 'v.lng',
                't.id as tid', 't.name as tal', 'd.id as did', 'd.name as dis')
            ->orderBy('v.id')
            ->chunk(20000, function ($rows) use (&$out, $bounds): void {
                foreach ($rows as $r) {
                    $lat = $r->lat === null ? null : (float) $r->lat;
                    $lng = $r->lng === null ? null : (float) $r->lng;
                    $inBox = $lat !== null && $lng !== null
                        && ! ($lat === 0.0 && $lng === 0.0)
                        && $lat >= $bounds['min_lat'] && $lat <= $bounds['max_lat']
                        && $lng >= $bounds['min_lng'] && $lng <= $bounds['max_lng'];

                    $out[] = [
                        'id' => (int) $r->id,
                        'name' => (string) $r->name,
                        'norm' => self::normStrict((string) $r->name),
                        'loose' => self::normLoose((string) $r->name),
                        'pin' => preg_replace('/\D/', '', (string) $r->pincode),
                        'lat' => $lat, 'lng' => $lng, 'inBox' => $inBox,
                        'tid' => (int) $r->tid, 'did' => (int) $r->did,
                        'tal' => (string) $r->tal, 'dis' => (string) $r->dis,
                        'disNorm' => self::normDistrict((string) $r->dis),
                    ];
                }
            });

        return $out;
    }

    // ---------------------------------------------------------- normalisation

    private static function normStrict(string $s): string
    {
        return PostalDirectory::normStrict($s);
    }

    private static function normLoose(string $s): string
    {
        return PostalDirectory::normLoose($s);
    }

    private static function normDistrict(string $s): string
    {
        return PostalDirectory::normDistrict($s);
    }

    private static function km(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        return GeoCentroidBackfillService::km($aLat, $aLng, $bLat, $bLng);
    }

    /** @param list<float> $v */
    private static function pct(array $v, float $p): float
    {
        if ($v === []) {
            return 0.0;
        }
        sort($v, SORT_NUMERIC);
        $i = (int) round(($p / 100) * (count($v) - 1));

        return $v[max(0, min(count($v) - 1, $i))];
    }

    // -------------------------------------------------------------- section 0

    private function section0(array $csv, array $ours): void
    {
        $distinct = [];
        foreach ($csv['rows'] as $r) {
            $distinct[$r['lat'].','.$r['lng']] = true;
        }
        $ourDistinct = [];
        $ourInBox = 0;
        foreach ($ours as $v) {
            if ($v['inBox']) {
                $ourInBox++;
                $ourDistinct[$v['lat'].','.$v['lng']] = true;
            }
        }

        $this->line('');
        $this->info('=== 0. SOURCES ===============================================================');
        $this->line(sprintf('  CSV  state rows=%d  NA-coord=%d  out-of-box=%d  usable=%d  distinct pts=%d  (%.1f%% reuse)',
            $csv['stats']['state_rows'], $csv['stats']['na_coord'], $csv['stats']['out_of_box'],
            $csv['stats']['usable'], count($distinct),
            100 - 100 * count($distinct) / max(1, $csv['stats']['usable'])));
        $this->line(sprintf('  CSV  distinct pincodes=%d', count($csv['byPin'])));
        $this->line(sprintf('  OURS villages=%d  in-box=%d  distinct pts=%d  (%.1f%% reuse)',
            count($ours), $ourInBox, count($ourDistinct),
            100 - 100 * count($ourDistinct) / max(1, $ourInBox)));

        $this->json['sources'] = [
            'csv' => $csv['stats'] + ['distinct_points' => count($distinct), 'distinct_pincodes' => count($csv['byPin'])],
            'ours' => ['villages' => count($ours), 'in_box' => $ourInBox, 'distinct_points' => count($ourDistinct)],
        ];
    }

    // -------------------------------------------------------------- section 1

    /**
     * @return array<int, array{mode:string, idx:int|null, lat:float|null, lng:float|null, n:int}>
     */
    private function section1Join(array $csv, array $ours): array
    {
        // pincode -> normStrict -> row indexes ; pincode -> normLoose -> row indexes
        $strictIdx = [];
        $looseIdx = [];
        foreach ($csv['byPin'] as $pin => $idxs) {
            foreach ($idxs as $i) {
                $strictIdx[$pin][$csv['rows'][$i]['norm']][] = $i;
                $looseIdx[$pin][$csv['rows'][$i]['loose']][] = $i;
            }
        }
        // state-wide loose name index, for the "name matches but pincode does not" diagnostic
        $nameOnly = [];
        foreach ($csv['rows'] as $i => $r) {
            $nameOnly[$r['loose']][] = $i;
        }

        $out = [];
        $c = ['strict' => 0, 'loose' => 0, 'pin_only' => 0, 'none' => 0,
            'no_pin' => 0, 'pin_absent' => 0, 'name_elsewhere' => 0];

        foreach ($ours as $v) {
            $pin = $v['pin'];
            if ($pin === '' || strlen($pin) !== 6) {
                $c['no_pin']++;
                $out[$v['id']] = ['mode' => 'none', 'idx' => null, 'lat' => null, 'lng' => null, 'n' => 0];

                continue;
            }
            if (! isset($csv['byPin'][$pin])) {
                $c['pin_absent']++;
                $c['none']++;
                if (isset($nameOnly[$v['loose']])) {
                    $c['name_elsewhere']++;
                }
                $out[$v['id']] = ['mode' => 'none', 'idx' => null, 'lat' => null, 'lng' => null, 'n' => 0];

                continue;
            }

            $hit = $strictIdx[$pin][$v['norm']] ?? null;
            $mode = 'strict';
            if ($hit === null) {
                $hit = $looseIdx[$pin][$v['loose']] ?? null;
                $mode = 'loose';
            }

            if ($hit !== null) {
                $c[$mode]++;
                // several offices can share a normalised name inside one pincode — take their median
                $lats = array_map(fn ($i) => $csv['rows'][$i]['lat'], $hit);
                $lngs = array_map(fn ($i) => $csv['rows'][$i]['lng'], $hit);
                $ctr = GeoCentroidBackfillService::centreOf($lats, $lngs);
                $out[$v['id']] = ['mode' => 'name', 'idx' => $hit[0], 'lat' => $ctr['lat'], 'lng' => $ctr['lng'], 'n' => count($hit)];

                continue;
            }

            $c['pin_only']++;
            $idxs = $csv['byPin'][$pin];
            $lats = array_map(fn ($i) => $csv['rows'][$i]['lat'], $idxs);
            $lngs = array_map(fn ($i) => $csv['rows'][$i]['lng'], $idxs);
            $ctr = GeoCentroidBackfillService::centreOf($lats, $lngs);
            $out[$v['id']] = ['mode' => 'pin', 'idx' => $idxs[0], 'lat' => $ctr['lat'], 'lng' => $ctr['lng'], 'n' => count($idxs)];
        }

        // how tight is a pincode area?  (spread of its own offices around their median)
        $spreadP50 = [];
        $spreadP90 = [];
        foreach ($csv['byPin'] as $pin => $idxs) {
            if (count($idxs) < 2) {
                continue;
            }
            $lats = array_map(fn ($i) => $csv['rows'][$i]['lat'], $idxs);
            $lngs = array_map(fn ($i) => $csv['rows'][$i]['lng'], $idxs);
            $ctr = GeoCentroidBackfillService::centreOf($lats, $lngs);
            $ds = [];
            foreach ($lats as $k => $la) {
                $ds[] = self::km($ctr['lat'], $ctr['lng'], $la, $lngs[$k]);
            }
            $spreadP50[] = self::pct($ds, 50);
            $spreadP90[] = self::pct($ds, 90);
        }

        $tot = count($ours);
        $name = $c['strict'] + $c['loose'];
        $this->line('');
        $this->info('=== 1. JOIN QUALITY (Maharashtra, '.$tot.' villages) ==========================');
        $this->line(sprintf('  (a) name+pincode  : %6d  (%.1f%%)   [strict=%d  loose-fold=%d]',
            $name, 100 * $name / $tot, $c['strict'], $c['loose']));
        $this->line(sprintf('  (b) pincode only  : %6d  (%.1f%%)   cumulative %.1f%%',
            $c['pin_only'], 100 * $c['pin_only'] / $tot, 100 * ($name + $c['pin_only']) / $tot));
        $this->line(sprintf('      neither       : %6d  (%.1f%%)   [no pincode=%d, pincode not in CSV=%d]',
            $c['none'] + $c['no_pin'], 100 * ($c['none'] + $c['no_pin']) / $tot, $c['no_pin'], $c['pin_absent']));
        $this->line(sprintf('      ... of those, %d have a name that DOES exist in the CSV under another pincode', $c['name_elsewhere']));
        $this->line(sprintf('  pincode-area spread (multi-office pincodes, n=%d): median-of-p50=%.1f km  median-of-p90=%.1f km',
            count($spreadP50), self::pct($spreadP50, 50), self::pct($spreadP90, 50)));
        $this->line(sprintf('  pincodes whose own offices scatter: p90-spread >25km=%d (%.1f%%)  >100km=%d (%.1f%%)  worst=%.0f km',
            count(array_filter($spreadP90, fn ($x) => $x > 25)), 100 * count(array_filter($spreadP90, fn ($x) => $x > 25)) / max(1, count($spreadP90)),
            count(array_filter($spreadP90, fn ($x) => $x > 100)), 100 * count(array_filter($spreadP90, fn ($x) => $x > 100)) / max(1, count($spreadP90)),
            $spreadP90 ? max($spreadP90) : 0));

        $this->json['join'] = $c + ['name_total' => $name, 'villages' => $tot,
            'pin_spread_p50' => round(self::pct($spreadP50, 50), 2),
            'pin_spread_p90' => round(self::pct($spreadP90, 50), 2)];

        return $out;
    }

    // -------------------------------------------------------------- section 2

    /**
     * @return array{badPins: array<string,bool>, badPairs: array<string,bool>}
     */
    private function section2DistrictCrossCheck(array $csv, array $ours, array $matches): array
    {
        // pincode -> set of CSV districts
        $pinDistricts = [];
        foreach ($csv['byPin'] as $pin => $idxs) {
            foreach ($idxs as $i) {
                $pinDistricts[$pin][$csv['rows'][$i]['district']] = true;
            }
        }

        $agree = 0;
        $disagree = 0;
        $badPairs = [];
        $badPins = [];
        $examples = [];
        $pairSeen = [];

        foreach ($ours as $v) {
            $pin = $v['pin'];
            if ($pin === '' || ! isset($pinDistricts[$pin])) {
                continue;
            }
            $ok = isset($pinDistricts[$pin][$v['disNorm']]);
            if ($ok) {
                $agree++;

                continue;
            }
            $disagree++;
            $badPairs[$pin.'|'.$v['disNorm']] = true;
            $badPins[$pin] = true;
            $key = $v['disNorm'].' -> '.implode('/', array_keys($pinDistricts[$pin]));
            if (! isset($pairSeen[$key])) {
                $pairSeen[$key] = 0;
            }
            $pairSeen[$key]++;
            if (count($examples) < 12 && $pairSeen[$key] === 1) {
                $examples[] = sprintf('%s (%s/%s) pin %s → CSV says %s',
                    $v['name'], $v['dis'], $v['tal'], $pin, implode(',', array_keys($pinDistricts[$pin])));
            }
        }

        $checked = $agree + $disagree;
        $this->line('');
        $this->info('=== 2. PINCODE TRUST — our district vs the CSV district for the same pincode ===');
        $this->line(sprintf('  villages whose pincode exists in the CSV : %d', $checked));
        $this->line(sprintf('  district AGREES                          : %d (%.2f%%)', $agree, 100 * $agree / max(1, $checked)));
        $this->line(sprintf('  district DISAGREES                       : %d (%.2f%%)  → pincode suspect in one source', $disagree, 100 * $disagree / max(1, $checked)));
        $this->line(sprintf('  distinct suspect pincodes                : %d of %d', count($badPins), count($pinDistricts)));
        arsort($pairSeen);
        $this->line('  top disagreement patterns:');
        foreach (array_slice($pairSeen, 0, 8, true) as $k => $n) {
            $this->line(sprintf('    %-46s %d villages', $k, $n));
        }

        $this->json['district_crosscheck'] = [
            'checked' => $checked, 'agree' => $agree, 'disagree' => $disagree,
            'suspect_pincodes' => count($badPins), 'csv_pincodes' => count($pinDistricts),
            'patterns' => array_slice($pairSeen, 0, 15, true),
        ];

        return ['badPins' => $badPins, 'badPairs' => $badPairs];
    }

    // -------------------------------------------------------------- section 3

    private function section3CurrentError(array $csv, array $ours, array $matches, array $trust): void
    {
        // Residual error of the COARSE strategy, measured on the rows where the exact office point is
        // known: how far the whole-pincode median sits from that village's own office. This is the
        // error a pincode-only village repair would still carry.
        $pinCentre = [];
        foreach ($csv['byPin'] as $pin => $idxs) {
            $lats = array_map(fn ($i) => $csv['rows'][$i]['lat'], $idxs);
            $lngs = array_map(fn ($i) => $csv['rows'][$i]['lng'], $idxs);
            $pinCentre[$pin] = GeoCentroidBackfillService::centreOf($lats, $lngs);
        }

        $d = [];
        $dTrusted = [];
        $coarseResidual = [];
        foreach ($ours as $v) {
            $m = $matches[$v['id']] ?? null;
            if ($m === null || $m['mode'] !== 'name' || ! $v['inBox']) {
                continue;
            }
            $km = self::km((float) $v['lat'], (float) $v['lng'], $m['lat'], $m['lng']);
            $d[] = $km;
            if (! isset($trust['badPairs'][$v['pin'].'|'.$v['disNorm']])) {
                $dTrusted[] = $km;
                if (isset($pinCentre[$v['pin']])) {
                    $coarseResidual[] = self::km($pinCentre[$v['pin']]['lat'], $pinCentre[$v['pin']]['lng'], $m['lat'], $m['lng']);
                }
            }
        }

        $row = function (array $x, string $label): void {
            if ($x === []) {
                return;
            }
            $over25 = count(array_filter($x, fn ($k) => $k > 25));
            $over100 = count(array_filter($x, fn ($k) => $k > 100));
            $this->line(sprintf('  %-22s n=%-6d  p50=%.1f  p75=%.1f  p90=%.1f  p99=%.1f  max=%.0f km   >25km=%.1f%%  >100km=%.1f%%',
                $label, count($x), self::pct($x, 50), self::pct($x, 75), self::pct($x, 90), self::pct($x, 99), max($x),
                100 * $over25 / count($x), 100 * $over100 / count($x)));
        };

        $this->line('');
        $this->info('=== 3. HOW WRONG IS OUR CURRENT VILLAGE COORDINATE? (vs CSV, name+pincode) =====');
        $row($d, 'TODAY all matches');
        $row($dTrusted, 'TODAY trusted only');
        $row($coarseResidual, 'AFTER coarse repair');

        $this->json['current_error'] = [
            'n' => count($dTrusted),
            'p50' => round(self::pct($dTrusted, 50), 2),
            'p90' => round(self::pct($dTrusted, 90), 2),
            'p99' => round(self::pct($dTrusted, 99), 2),
            'max' => $dTrusted === [] ? 0 : round(max($dTrusted), 1),
            'over_25_pct' => $dTrusted === [] ? 0 : round(100 * count(array_filter($dTrusted, fn ($k) => $k > 25)) / count($dTrusted), 2),
            'over_100_pct' => $dTrusted === [] ? 0 : round(100 * count(array_filter($dTrusted, fn ($k) => $k > 100)) / count($dTrusted), 2),
            'coarse_residual_p50' => round(self::pct($coarseResidual, 50), 2),
            'coarse_residual_p90' => round(self::pct($coarseResidual, 90), 2),
            'coarse_residual_p99' => round(self::pct($coarseResidual, 99), 2),
        ];
    }

    // -------------------------------------------------------------- section 4

    /**
     * @return array<int, array{tal:string,dis:string,old:?array,new:?array,newRaw:array,reason:?string,oldPass:bool}>
     */
    private function section4Centres(array $csv, array $ours, array $matches, array $trust, array $bounds, string $stateSlug): array
    {
        $geo = Location::geoTable();

        // ---- variant A: name+pincode points only ; variant B: A + trusted pincode-area fallback
        $variants = ['A_name_only' => [], 'B_name_plus_pin' => []];
        $meta = [];

        foreach ($ours as $v) {
            $meta[$v['tid']] = ['tal' => $v['tal'], 'dis' => $v['dis'], 'did' => $v['did']];
            $m = $matches[$v['id']] ?? null;
            if ($m === null || $m['lat'] === null) {
                continue;
            }
            if (isset($trust['badPairs'][$v['pin'].'|'.$v['disNorm']])) {
                continue; // pincode suspect — excluded from the trusted set
            }

            if ($m['mode'] === 'name') {
                foreach (['A_name_only', 'B_name_plus_pin'] as $k) {
                    $variants[$k]['taluka'][$v['tid']]['lat'][] = $m['lat'];
                    $variants[$k]['taluka'][$v['tid']]['lng'][] = $m['lng'];
                    $variants[$k]['district'][$v['did']]['lat'][] = $m['lat'];
                    $variants[$k]['district'][$v['did']]['lng'][] = $m['lng'];
                }
            } else {
                $variants['B_name_plus_pin']['taluka'][$v['tid']]['lat'][] = $m['lat'];
                $variants['B_name_plus_pin']['taluka'][$v['tid']]['lng'][] = $m['lng'];
                $variants['B_name_plus_pin']['district'][$v['did']]['lat'][] = $m['lat'];
                $variants['B_name_plus_pin']['district'][$v['did']]['lng'][] = $m['lng'];
            }
        }

        // ---- today's stored centres (what production actually has)
        $stored = [];
        foreach (DB::table("{$geo} as t")->join("{$geo} as d", 'd.id', '=', 't.parent_id')
            ->where('t.hierarchy', 'taluka')->where('d.parent_id',
                (int) DB::table($geo)->where('hierarchy', 'state')->where('slug', $stateSlug)->value('id'))
            ->select('t.id', 't.lat', 't.lng')->get() as $r) {
            $stored[(int) $r->id] = $r->lat === null ? null : ['lat' => (float) $r->lat, 'lng' => (float) $r->lng];
        }

        $totalTalukas = count($stored);
        $oldPass = count(array_filter($stored, fn ($x) => $x !== null));

        $results = [];
        $summary = [];

        foreach ($variants as $vkey => $groups) {
            $districtCentres = [];
            $districtPass = 0;
            foreach ($groups['district'] ?? [] as $did => $pts) {
                $c = GeoCentroidBackfillService::centreOf($pts['lat'], $pts['lng']);
                if (GeoCentroidBackfillService::accept($c, $pts['lat'], $pts['lng'], $bounds, null) === null) {
                    $districtCentres[$did] = $c;
                    $districtPass++;
                }
            }

            $pass = 0;
            $reasons = [];
            $noSample = 0;
            $perTaluka = [];

            foreach ($stored as $tid => $old) {
                $pts = $groups['taluka'][$tid] ?? null;
                if ($pts === null) {
                    $noSample++;
                    $reasons['no_sample'] = ($reasons['no_sample'] ?? 0) + 1;
                    $perTaluka[$tid] = ['centre' => null, 'reason' => 'no_sample', 'n' => 0];

                    continue;
                }
                $c = GeoCentroidBackfillService::centreOf($pts['lat'], $pts['lng']);
                $dc = $districtCentres[$meta[$tid]['did'] ?? 0] ?? null;
                $reason = $dc === null
                    ? 'district'
                    : GeoCentroidBackfillService::accept($c, $pts['lat'], $pts['lng'], $bounds, $dc);

                if ($reason === null) {
                    $pass++;
                    $perTaluka[$tid] = ['centre' => $c, 'reason' => null, 'n' => count($pts['lat'])];
                } else {
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                    $perTaluka[$tid] = ['centre' => null, 'reason' => $reason, 'n' => count($pts['lat'])];
                }
            }

            $rescued = 0;
            $lost = 0;
            $bothPass = 0;
            $shift = [];
            foreach ($stored as $tid => $old) {
                $new = $perTaluka[$tid]['centre'] ?? null;
                if ($old === null && $new !== null) {
                    $rescued++;
                }
                if ($old !== null && $new === null) {
                    $lost++;
                }
                if ($old !== null && $new !== null) {
                    $bothPass++;
                    $shift[] = self::km($old['lat'], $old['lng'], $new['lat'], $new['lng']);
                }
            }

            $this->line('');
            $this->info("=== 4. ACCEPTANCE GATE — variant {$vkey} ======================================");
            $seenDistricts = [];
            foreach ($meta as $m) {
                $seenDistricts[$m['did']] = $m['dis'];
            }
            $missingDistricts = [];
            foreach ($seenDistricts as $did => $dname) {
                if (! isset($districtCentres[$did])) {
                    $missingDistricts[] = $dname.'(n='.count($groups['district'][$did]['lat'] ?? []).')';
                }
            }
            $this->line(sprintf('  districts accepted : %d / %d   missing: %s',
                $districtPass, count($seenDistricts), $missingDistricts ? implode(', ', $missingDistricts) : '-'));
            $this->line(sprintf('  talukas            : %d', $totalTalukas));
            $this->line(sprintf('  PASS now           : %d   (today: %d)   delta %+d', $pass, $oldPass, $pass - $oldPass));
            $this->line(sprintf('  rescued (NULL→centre): %d of %d current failures', $rescued, $totalTalukas - $oldPass));
            $this->line(sprintf('  LOST (centre→NULL)   : %d of %d currently accepted', $lost, $oldPass));
            $this->line('  reject reasons     : '.json_encode($reasons));
            if ($shift !== []) {
                $this->line(sprintf('  old vs new centre shift (both pass, n=%d): p50=%.1f p90=%.1f max=%.0f km',
                    $bothPass, self::pct($shift, 50), self::pct($shift, 90), max($shift)));
            }

            $summary[$vkey] = ['districts' => $districtPass, 'pass' => $pass, 'old_pass' => $oldPass,
                'rescued' => $rescued, 'lost' => $lost, 'reasons' => $reasons,
                'shift_p50' => $shift ? round(self::pct($shift, 50), 1) : null,
                'shift_max' => $shift ? round(max($shift), 1) : null];

            $results[$vkey] = $perTaluka;
        }

        // list the losses for the better variant
        $best = 'B_name_plus_pin';
        $lostList = [];
        foreach ($stored as $tid => $old) {
            if ($old !== null && ($results[$best][$tid]['centre'] ?? null) === null) {
                $lostList[] = sprintf('%s/%s (n=%d, %s)', $meta[$tid]['dis'] ?? '?', $meta[$tid]['tal'] ?? '?',
                    $results[$best][$tid]['n'] ?? 0, $results[$best][$tid]['reason'] ?? '?');
            }
        }
        if ($lostList !== []) {
            $this->line('');
            $this->warn('  talukas that pass TODAY but fail on CSV ('.$best.'), '.count($lostList).':');
            foreach (array_slice($lostList, 0, 40) as $l) {
                $this->line('    '.$l);
            }
        }

        // ---- the proposed merge: CSV centre where it passes, today's centre otherwise
        $mergeCovered = 0;
        $mergeNull = [];
        foreach ($stored as $tid => $old) {
            if (($results[$best][$tid]['centre'] ?? null) !== null || $old !== null) {
                $mergeCovered++;
            } else {
                $mergeNull[] = sprintf('%s/%s (%s)', $meta[$tid]['dis'] ?? '?', $meta[$tid]['tal'] ?? '?',
                    $results[$best][$tid]['reason'] ?? '?');
            }
        }
        $this->line('');
        $this->info('=== MERGE RULE — CSV centre where it passes, keep today\'s centre otherwise =====');
        $this->line(sprintf('  covered : %d / %d talukas  (today %d)  → %d stay NULL', $mergeCovered, $totalTalukas, $oldPass, count($mergeNull)));
        foreach (array_slice($mergeNull, 0, 30) as $l) {
            $this->line('    NULL: '.$l);
        }

        // ---- threshold sensitivity: CSV points are far tighter than village points, so the 25 km
        // consensus radius that was tuned for the old source may now be too generous. Mirrors accept()
        // exactly, only the radius moves.
        $this->line('');
        $this->info('=== 4b. CONSENSUS-RADIUS SENSITIVITY on variant B =============================');
        $probeNames = ['Nagpur (city)', 'Bhamragad', 'Haveli', 'Yavatmal'];
        foreach ([25.0, 20.0, 15.0, 10.0] as $radius) {
            $groups = $variants['B_name_plus_pin'];
            $dc = [];
            foreach ($groups['district'] ?? [] as $did => $pts) {
                $dc[$did] = GeoCentroidBackfillService::centreOf($pts['lat'], $pts['lng']);
            }
            $pass = 0;
            $probe = [];
            foreach ($stored as $tid => $old) {
                $pts = $groups['taluka'][$tid] ?? null;
                if ($pts === null) {
                    continue;
                }
                $c = GeoCentroidBackfillService::centreOf($pts['lat'], $pts['lng']);
                $ok = $c['lat'] >= $bounds['min_lat'] && $c['lat'] <= $bounds['max_lat']
                    && $c['lng'] >= $bounds['min_lng'] && $c['lng'] <= $bounds['max_lng'];
                $near = 0;
                foreach ($pts['lat'] as $i => $la) {
                    if (self::km($c['lat'], $c['lng'], $la, $pts['lng'][$i]) <= $radius) {
                        $near++;
                    }
                }
                $ok = $ok && ($near / count($pts['lat'])) >= GeoCentroidBackfillService::MIN_CONSENSUS;
                $d = $dc[$meta[$tid]['did'] ?? 0] ?? null;
                $ok = $ok && $d !== null
                    && self::km($c['lat'], $c['lng'], $d['lat'], $d['lng']) <= GeoCentroidBackfillService::MAX_DISTRICT_DISTANCE_KM;
                if ($ok) {
                    $pass++;
                }
                if (in_array($meta[$tid]['tal'] ?? '', $probeNames, true)) {
                    $probe[$meta[$tid]['tal']] = $ok ? 'PASS' : 'fail';
                }
            }
            $this->line(sprintf('  radius=%-5.1fkm  PASS=%-4d  probes: %s', $radius, $pass, json_encode($probe)));
            $this->json['radius_sensitivity'][(string) $radius] = ['pass' => $pass, 'probes' => $probe];
        }

        $this->json['gate'] = $summary;
        $this->json['lost_talukas'] = $lostList;
        $this->json['merge'] = ['covered' => $mergeCovered, 'total' => $totalTalukas, 'still_null' => $mergeNull];

        $out = [];
        foreach ($stored as $tid => $old) {
            $out[$tid] = [
                'tal' => $meta[$tid]['tal'] ?? '?', 'dis' => $meta[$tid]['dis'] ?? '?',
                'old' => $old,
                'new' => $results['B_name_plus_pin'][$tid]['centre'] ?? null,
                'newA' => $results['A_name_only'][$tid]['centre'] ?? null,
                'reason' => $results['B_name_plus_pin'][$tid]['reason'] ?? null,
                'oldPass' => $old !== null,
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------- section 5

    private function section5Osm(array $centres): void
    {
        $byName = [];
        foreach ($centres as $tid => $c) {
            $byName[strtolower($c['dis'].'|'.$c['tal'])] = $c;
        }

        $panel = self::OSM_PANEL;

        // Same-panel scoring: N talukas that have a centre under BOTH sources, evenly spaced through
        // the id order so the sample is deterministic and not clustered in one district.
        $extra = (int) $this->option('osm-extra');
        if ($extra > 0) {
            $both = [];
            foreach ($centres as $c) {
                if ($c['old'] !== null && $c['new'] !== null) {
                    $both[] = [$c['dis'], $c['tal']];
                }
            }
            $step = max(1, (int) floor(count($both) / $extra));
            for ($i = 0; $i < count($both) && count($panel) < count(self::OSM_PANEL) + $extra; $i += $step) {
                $panel[] = $both[$i];
            }
        }

        $this->line('');
        $this->info('=== 5. EXTERNAL TRUTH — OpenStreetMap / Nominatim =============================');
        $this->line(sprintf('  %-30s %10s %10s %10s', 'district/taluka', 'OLD km', 'NEW km', 'NEW-A km'));

        $rows = [];
        foreach ($panel as [$dis, $tal]) {
            $key = strtolower($dis.'|'.$tal);
            $c = $byName[$key] ?? null;
            if ($c === null) {
                // tolerate spelling drift between the panel and the DB
                foreach ($byName as $k => $v) {
                    if (str_starts_with($k, strtolower($dis).'|') && self::normLoose($v['tal']) === self::normLoose($tal)) {
                        $c = $v;
                        break;
                    }
                }
            }
            if ($c === null) {
                $this->line(sprintf('  %-30s %10s', $dis.'/'.$tal, 'NOT FOUND'));

                continue;
            }

            $truth = $this->nominatim("{$tal}, {$dis} district, Maharashtra, India");
            if ($truth === null) {
                $truth = $this->nominatim("{$tal} taluka, Maharashtra, India");
            }
            if ($truth === null) {
                $this->line(sprintf('  %-30s %10s', $dis.'/'.$tal, 'no OSM hit'));

                continue;
            }

            $fmt = fn (?array $p) => $p === null ? 'NULL' : sprintf('%.1f', self::km($truth[0], $truth[1], $p['lat'], $p['lng']));
            $this->line(sprintf('  %-30s %10s %10s %10s', $dis.'/'.$tal, $fmt($c['old']), $fmt($c['new']), $fmt($c['newA'])));

            $rows[] = ['taluka' => $dis.'/'.$tal, 'osm' => $truth,
                'old_km' => $c['old'] ? round(self::km($truth[0], $truth[1], $c['old']['lat'], $c['old']['lng']), 1) : null,
                'new_km' => $c['new'] ? round(self::km($truth[0], $truth[1], $c['new']['lat'], $c['new']['lng']), 1) : null,
                'newA_km' => $c['newA'] ? round(self::km($truth[0], $truth[1], $c['newA']['lat'], $c['newA']['lng']), 1) : null,
                'new_reason' => $c['reason']];
        }

        $old = array_values(array_filter(array_column($rows, 'old_km'), fn ($x) => $x !== null));
        $new = array_values(array_filter(array_column($rows, 'new_km'), fn ($x) => $x !== null));
        $both = array_values(array_filter($rows, fn ($r) => $r['old_km'] !== null && $r['new_km'] !== null));
        $bo = array_column($both, 'old_km');
        $bn = array_column($both, 'new_km');
        $better = count(array_filter($both, fn ($r) => $r['new_km'] < $r['old_km']));

        $this->line('');
        $this->line(sprintf('  OLD centres scored (n=%d): p50=%.1f  p90=%.1f  max=%.1f km', count($old),
            self::pct($old, 50), self::pct($old, 90), $old ? max($old) : 0));
        $this->line(sprintf('  NEW centres scored (n=%d): p50=%.1f  p90=%.1f  max=%.1f km', count($new),
            self::pct($new, 50), self::pct($new, 90), $new ? max($new) : 0));
        $this->line(sprintf('  HEAD-TO-HEAD, both non-null (n=%d): old p50=%.1f max=%.1f | new p50=%.1f max=%.1f | new better on %d/%d',
            count($both), self::pct($bo, 50), $bo ? max($bo) : 0, self::pct($bn, 50), $bn ? max($bn) : 0, $better, count($both)));

        $this->json['osm'] = $rows;
        $this->json['osm_summary'] = [
            'old_n' => count($old), 'old_p50' => round(self::pct($old, 50), 1), 'old_max' => $old ? max($old) : null,
            'new_n' => count($new), 'new_p50' => round(self::pct($new, 50), 1), 'new_max' => $new ? max($new) : null,
            'head_to_head_n' => count($both), 'new_better' => $better,
        ];
    }

    /** @return array{0:float,1:float}|null */
    private function nominatim(string $q): ?array
    {
        return NominatimClient::lookup($q);
    }
}
