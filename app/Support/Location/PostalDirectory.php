<?php

namespace App\Support\Location;

use App\Services\Location\GeoCentroidBackfillService;

/**
 * An indexed, in-memory view of the India Post office directory CSV for ONE state.
 *
 * WHY THIS SOURCE EXISTS AT ALL
 * -----------------------------
 * `addresses` village lat/lng were never surveyed. LGD publishes no coordinates, so the village rows
 * were geocoded BY NAME, and same-named villages across India were handed each other's points. The
 * India Post directory is the opposite: the coordinate arrived attached to the postal record itself,
 * so a village's point is not a lookup of its name. Measured on Maharashtra, that shows up directly in
 * the coordinate-reuse rate — ours 77.2%, the CSV's 23.2%.
 *
 * WHAT IT IS NOT
 * --------------
 * The CSV has NO taluka column. `divisionname` is a POSTAL division and does not follow revenue taluka
 * boundaries — it must never be read as a taluka. Only `district`, `pincode` and the office coordinate
 * are used here.
 *
 * This class is the single home for the postal-name normalisation and the LGD-vs-India-Post district
 * alias table. Both {@see \App\Console\Commands\AnalyzePincodeGeoSourceCommand} (read-only scoring) and
 * {@see \App\Console\Commands\RepairVillageCoordinatesCommand} (the writer) index through it, so the
 * scoring run and the write can never drift apart on what "the same place" means.
 */
final class PostalDirectory
{
    /**
     * District naming differs between LGD (ours) and India Post (the CSV). Left side is either side's
     * normalised spelling, right side is the shared key both collapse to.
     */
    public const DISTRICT_ALIASES = [
        'ahilyanagar' => 'ahmednagar',
        'ahmadnagar' => 'ahmednagar',
        'chhatrapatisambhajinagar' => 'aurangabad',
        'sambhajinagar' => 'aurangabad',
        'dharashiv' => 'osmanabad',
        'mumbaisuburban' => 'mumbai',
        'mumbaicity' => 'mumbai',
        'greatermumbai' => 'mumbai',
        'raigarh' => 'raigad',
        'buldhana' => 'buldana',
        'gondiya' => 'gondia',
    ];

    /**
     * @param  list<array{name:string,norm:string,loose:string,pin:string,district:string,lat:float,lng:float}>  $rows
     * @param  array<string, list<int>>  $byPin
     * @param  array<string, array<string, list<int>>>  $byPinStrict
     * @param  array<string, array<string, list<int>>>  $byPinLoose
     * @param  array<string, array{lat:float,lng:float}>  $pinCentre
     * @param  array<string, float>  $pinSpreadP90  km; 0.0 for single-office pincodes
     * @param  array<string, array<string, true>>  $pinDistricts
     * @param  array<string, int>  $pointPincodes  "lat,lng" => how many DISTINCT pincodes sit on it
     * @param  array<string, int>  $stats
     */
    private function __construct(
        public readonly array $rows,
        public readonly array $byPin,
        public readonly array $byPinStrict,
        public readonly array $byPinLoose,
        public readonly array $pinCentre,
        public readonly array $pinSpreadP90,
        public readonly array $pinDistricts,
        public readonly array $pointPincodes,
        public readonly array $stats,
    ) {}

    /**
     * Read the CSV, keep only `$stateSlug` rows that carry a usable in-box coordinate and a 6-digit
     * pincode, and build every index the callers need in one pass.
     *
     * @param  array{min_lat:float,max_lat:float,min_lng:float,max_lng:float}  $bounds
     */
    public static function load(string $path, string $stateSlug, array $bounds): self
    {
        $target = strtoupper(str_replace('-', ' ', $stateSlug));
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open postal directory CSV: {$path}");
        }
        fgetcsv($fh);

        $rows = [];
        $byPin = [];
        $stats = ['state_rows' => 0, 'na_coord' => 0, 'out_of_box' => 0, 'bad_pin' => 0, 'usable' => 0];

        while (($r = fgetcsv($fh)) !== false) {
            if (strtoupper(trim((string) ($r[8] ?? ''))) !== $target) {
                continue;
            }
            $stats['state_rows']++;

            $lat = trim((string) ($r[9] ?? ''));
            $lng = trim((string) ($r[10] ?? ''));
            if ($lat === '' || $lng === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
                $stats['na_coord']++;

                continue;
            }
            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < $bounds['min_lat'] || $lat > $bounds['max_lat']
                || $lng < $bounds['min_lng'] || $lng > $bounds['max_lng']) {
                $stats['out_of_box']++;

                continue;
            }

            $pin = preg_replace('/\D/', '', (string) ($r[4] ?? ''));
            if (strlen($pin) !== 6) {
                $stats['bad_pin']++;

                continue;
            }

            $name = trim((string) ($r[3] ?? ''));
            $i = count($rows);
            $rows[] = [
                'name' => $name,
                'norm' => self::normStrict($name),
                'loose' => self::normLoose($name),
                'pin' => $pin,
                'district' => self::normDistrict((string) ($r[7] ?? '')),
                'lat' => $lat,
                'lng' => $lng,
            ];
            $byPin[$pin][] = $i;
            $stats['usable']++;
        }
        fclose($fh);

        $byPinStrict = [];
        $byPinLoose = [];
        $pinCentre = [];
        $pinSpread = [];
        $pinDistricts = [];

        foreach ($byPin as $pin => $idxs) {
            $lats = [];
            $lngs = [];
            foreach ($idxs as $i) {
                $byPinStrict[$pin][$rows[$i]['norm']][] = $i;
                $byPinLoose[$pin][$rows[$i]['loose']][] = $i;
                $pinDistricts[$pin][$rows[$i]['district']] = true;
                $lats[] = $rows[$i]['lat'];
                $lngs[] = $rows[$i]['lng'];
            }
            $c = GeoCentroidBackfillService::centreOf($lats, $lngs);
            $pinCentre[$pin] = $c;

            $ds = [];
            foreach ($lats as $k => $la) {
                $ds[] = GeoCentroidBackfillService::km($c['lat'], $c['lng'], $la, $lngs[$k]);
            }
            $pinSpread[$pin] = self::percentile($ds, 90);
        }

        // How many DISTINCT pincodes sit on each exact coordinate. The CSV bulk-filled whole blocks of
        // sub-offices with a single district-level point — "Vadali Bhoi S.O" shares its coordinate with
        // 35 offices spread over 30+ Nashik pincodes. A point like that is not a place, it is a filler.
        $pointPins = [];
        foreach ($rows as $r) {
            $pointPins[$r['lat'].','.$r['lng']][$r['pin']] = true;
        }
        $pointPincodes = array_map('count', $pointPins);

        return new self($rows, $byPin, $byPinStrict, $byPinLoose, $pinCentre, $pinSpread,
            $pinDistricts, $pointPincodes, $stats);
    }

    /** How many distinct pincodes share the exact coordinate of CSV row `$i`. 1 = the point is its own. */
    public function pincodesOnPointOf(int $i): int
    {
        return $this->pointPincodes[$this->rows[$i]['lat'].','.$this->rows[$i]['lng']] ?? 1;
    }

    /**
     * Distance in km from office `$i` to the median of the OTHER offices in its own pincode, or null
     * when the pincode has fewer than `$minPeers` peers to form an opinion.
     *
     * A postal record whose coordinate sits far outside its own pincode's cloud is the CSV contradicting
     * itself. Pincode 442606 carries an office plotted in Punjab and another in Sangli; "Kosami B.O" in
     * that same pincode is 65 km from the real Kosami. The peers, not the office, are the majority view.
     */
    public function kmFromPincodePeers(int $i, int $minPeers = 2): ?float
    {
        $pin = $this->rows[$i]['pin'];
        $peers = array_values(array_filter($this->byPin[$pin] ?? [], fn ($j) => $j !== $i));
        if (count($peers) < $minPeers) {
            return null;
        }
        $c = $this->centreOfRows($peers);

        return GeoCentroidBackfillService::km($this->rows[$i]['lat'], $this->rows[$i]['lng'], $c['lat'], $c['lng']);
    }

    /**
     * Offices in `$pin` whose name normalises to the same token as `$name`, strict first then the loose
     * transliteration fold. Returns the matched row indexes plus which tier hit, or null.
     *
     * @return array{tier:'strict'|'loose', idx:list<int>}|null
     */
    public function officesNamed(string $pin, string $normStrict, string $normLoose): ?array
    {
        $hit = $this->byPinStrict[$pin][$normStrict] ?? null;
        if ($hit !== null) {
            return ['tier' => 'strict', 'idx' => $hit];
        }
        $hit = $this->byPinLoose[$pin][$normLoose] ?? null;

        return $hit === null ? null : ['tier' => 'loose', 'idx' => $hit];
    }

    /**
     * Marginal median of the given row indexes — the same centre rule the taluka/district backfill uses,
     * so a village point and a centre computed from village points are never derived two different ways.
     *
     * @param  list<int>  $idx
     * @return array{lat:float,lng:float}
     */
    public function centreOfRows(array $idx): array
    {
        return GeoCentroidBackfillService::centreOf(
            array_map(fn ($i) => $this->rows[$i]['lat'], $idx),
            array_map(fn ($i) => $this->rows[$i]['lng'], $idx),
        );
    }

    // ------------------------------------------------------------------ normalisation

    /**
     * Strict: drop the postal office-type suffix and any parenthetical, then keep letters+digits only.
     * "Belawale BK B.O" -> "belawalebk", "Pali(CPN) B.O" -> "pali".
     */
    public static function normStrict(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\([^)]*\)/', ' ', $s);                       // (CPN), (Bk)
        $s = preg_replace('/\b(b\.?\s?o|s\.?\s?o|h\.?\s?o)\.?\s*$/i', ' ', $s);
        $s = preg_replace('/\b(branch|sub|head)\s+office\s*$/i', ' ', $s);

        return preg_replace('/[^a-z0-9]+/', '', $s);
    }

    /**
     * Loose: strict, plus the transliteration folds that actually collide in Marathi romanisation —
     * budruk/khurd abbreviations, doubled vowels, v/w, j/z, y/i, and collapsed doubled letters.
     * Deliberately conservative: no soundex, no edit distance, because a wrong village coordinate is
     * worse than no coordinate.
     */
    public static function normLoose(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\([^)]*\)/', ' ', $s);
        $s = preg_replace('/\b(b\.?\s?o|s\.?\s?o|h\.?\s?o)\.?\s*$/i', ' ', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $s = ' '.trim($s).' ';

        $s = str_replace(
            [' budruk ', ' budrukh ', ' bu ', ' bk ', ' khurd ', ' kh ', ' kd ', ' tarf ', ' tarfe ', ' tf '],
            [' bk ', ' bk ', ' bk ', ' bk ', ' kh ', ' kh ', ' kh ', ' ', ' ', ' '],
            $s
        );
        $s = preg_replace('/\s+/', '', $s);

        $s = strtr($s, ['aa' => 'a', 'ee' => 'i', 'ii' => 'i', 'oo' => 'u', 'uu' => 'u']);
        $s = strtr($s, ['w' => 'v', 'z' => 'j', 'y' => 'i']);

        return preg_replace('/(.)\1+/', '$1', $s);
    }

    public static function normDistrict(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\([^)]*\)/', ' ', $s);
        $s = preg_replace('/[^a-z0-9]+/', '', $s);

        return self::DISTRICT_ALIASES[$s] ?? $s;
    }

    /** @param list<float> $v */
    public static function percentile(array $v, float $p): float
    {
        if ($v === []) {
            return 0.0;
        }
        sort($v, SORT_NUMERIC);
        $i = (int) round(($p / 100) * (count($v) - 1));

        return $v[max(0, min(count($v) - 1, $i))];
    }
}
