<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Location\GeoCentroidBackfillService;
use App\Support\Location\NominatimClient;
use App\Support\Location\PostalDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Repairs `addresses` VILLAGE lat/lng for one state from the India Post office directory.
 *
 * ---------------------------------------------------------------------------------------------------
 * THE DEFECT
 * ---------------------------------------------------------------------------------------------------
 * LGD publishes no coordinates, so the village rows in `addresses` were geocoded BY NAME. Villages
 * sharing a name across India were handed each other's points. Measured on Maharashtra: 44,853
 * villages hold only 10,220 distinct coordinates (77.2% carry some other village's point), 9.1% sit
 * more than 100 km from their own taluka, and Kolhapur/Gaganbawada's taluka median lands in Akola,
 * 635 km away. Every bad point is INSIDE the state box, so bounds checks catch none of them.
 *
 * ---------------------------------------------------------------------------------------------------
 * THE REPLACEMENT SOURCE, AND WHY IT IS BELIEVED
 * ---------------------------------------------------------------------------------------------------
 * The India Post directory ({@see PostalDirectory}) carries a coordinate on the postal record itself,
 * not looked up from a name. Coordinate reuse is 23.2% against our 77.2%. The two datasets validate
 * each other through the pincode: for 97.92% of Maharashtra villages our district and the CSV district
 * for the same pincode agree. The 2.08% that disagree mean the pincode is wrong in one source or the
 * other, and every one of those rows is EXCLUDED here rather than guessed at.
 *
 * ---------------------------------------------------------------------------------------------------
 * THE TWO MATCH TIERS
 * ---------------------------------------------------------------------------------------------------
 *   india_post_name_pincode  — the normalised office name equals the normalised village name AND the
 *                              pincode matches. This is a real point for that specific village.
 *   india_post_pincode_area  — only the pincode matches, so the whole pincode area shares one point:
 *                              the marginal median of that pincode's offices. Applied ONLY where the
 *                              pincode's own offices are tight (p90 spread <= PIN_SPREAD_MAX_KM);
 *                              25.8% of pincodes scatter more than that and 5.1% scatter over 100 km,
 *                              which is the CSV telling us the pincode itself is unreliable there.
 *
 * A pincode-area point is deliberately COARSE and many villages will share it. That is honest: we know
 * the postal area, not the hamlet. It is a large net improvement anyway — measured on the rows where
 * the true office IS known, our current coordinate is p50 24.8 km / p90 108.3 km off, while the
 * whole-pincode median is p50 5.8 km / p90 28.0 km off. `geo_source` records which tier a row got so
 * no consumer has to guess how precise its point is.
 *
 * ---------------------------------------------------------------------------------------------------
 * NEVER MAKE A ROW WORSE
 * ---------------------------------------------------------------------------------------------------
 * A village whose taluka already has an ACCEPTED stored centre (non-null in `addresses` means it
 * passed {@see GeoCentroidBackfillService::accept()} — bounds + 70% consensus + district distance) has
 * a usable referee. If that village's CURRENT point sits inside the taluka's consensus radius and the
 * CSV candidate would throw it well outside, the CSV is the odd one out and the current point is KEPT
 * and journalled as `kept_current_closer_to_taluka`. The referee is only trusted where it was accepted;
 * for the 118 rejected talukas there is no referee and the CSV is taken on its own merits.
 *
 * ---------------------------------------------------------------------------------------------------
 * SAFETY
 * ---------------------------------------------------------------------------------------------------
 *   * Dry run by default. `--apply` writes.
 *   * One state, resolved through `addresses.slug` and the district->state parent chain. Rows are
 *     collected as explicit ids and updated by id, so nothing outside the state can be touched.
 *   * Village rows only. Taluka and district rows are NEVER written here — those are derived and owned
 *     by `locations:backfill-geo-centroids`, which must be re-run after this command.
 *   * Every assessed row gets a journal entry in `address_geo_repairs` holding its coordinate BEFORE
 *     the write. `--rollback=<batch>` restores that batch exactly.
 *   * Idempotent: a row already stamped with a `geo_source` from this state's run is left alone, so a
 *     second run writes nothing. `--force` re-derives from the journalled original.
 *
 *   php artisan geo:repair-village-coordinates                      # dry run + full report
 *   php artisan geo:repair-village-coordinates --apply
 *   php artisan geo:repair-village-coordinates --rollback=<batch>
 */
class RepairVillageCoordinatesCommand extends Command
{
    protected $signature = 'geo:repair-village-coordinates
                            {--state=maharashtra}
                            {--csv= : Path to the all-india pincode CSV}
                            {--apply : Actually write (default is a dry run)}
                            {--force : Re-assess rows already stamped, restoring their journalled original first}
                            {--rollback= : Undo a batch uuid and exit}
                            {--osm : Score the repair against OpenStreetMap on a fixed village panel (~25 calls, slow)}
                            {--dump= : Write the run report JSON here}';

    protected $description = 'Repair village lat/lng for one state from the India Post office directory (dry run by default)';

    /** A pincode whose own offices scatter wider than this at p90 is not trusted as an area point. */
    public const PIN_SPREAD_MAX_KM = 25.0;

    /**
     * How much further from its taluka's accepted centre the CSV candidate must be before we refuse to
     * move a village that already sits inside that centre's consensus radius.
     */
    public const KEEP_CURRENT_MARGIN_KM = 25.0;

    /**
     * A village whose CURRENT point already lies this close to its own pincode's office cloud is
     * INSIDE its postal area, and the whole-pincode median cannot improve on it — it can only blur a
     * specific point into an area average.
     *
     * This is not a theoretical guard. The OSM panel caught it: Satara/Karad sits 0.4 km from Karad
     * H.O today, but the office is filed under pincode 415110 while our village row says 415103, so
     * the name+pincode join misses and the coarse tier would have moved a correct point 15.9 km away.
     * Nashik/Malegaon was the same story at 14.6 km. The floor is deliberately generous: the coarse
     * tier's own residual is p50 4.8 / p90 15.2 km, so there is nothing to win by nudging a village
     * that is already within the area, and a great deal to lose.
     */
    public const PIN_AREA_KEEP_CURRENT_KM = 25.0;

    /**
     * A CSV coordinate carried by offices in this many DISTINCT pincodes was bulk-filled, not surveyed,
     * and is rejected as a village point. "Vadali Bhoi S.O" shares its point with 35 offices across 30+
     * Nashik pincodes; taking it moved a village 40 km to a generic district point.
     */
    public const MAX_PINCODES_PER_POINT = 1;

    /**
     * How far a matched office may sit from the median of the OTHER offices in its own pincode before
     * the CSV is judged to be contradicting itself. Pincode 442606 plots one of its offices in Punjab;
     * "Kosami B.O" in the same pincode is 65 km from the real Kosami and its peers say so.
     */
    public const MAX_OFFICE_PEER_DISTANCE_KM = 25.0;

    public const SOURCE_NAME_PIN = 'india_post_name_pincode';

    public const SOURCE_PIN_AREA = 'india_post_pincode_area';

    public const SOURCE_LEGACY = 'legacy_name_geocode';

    private const WRITE_CHUNK = 500;

    /**
     * The OSM spot-check panel: [district, taluka, village, osm-query]. The first three are the cases
     * the 2026-07 audit named as catastrophic and are mandatory — Kolhapur/Gaganbawada's village points
     * put its taluka median 635 km away in Akola, and Sangli/Khanapur borrowed Belgaum's Khanapur in
     * Karnataka. The rest spread one village across every remaining district.
     *
     * READ THIS PANEL'S BIAS BEFORE READING ITS RESULT. These are mostly taluka-headquarters villages,
     * chosen because Nominatim can actually resolve them — a nameless hamlet usually returns no hit or
     * the wrong district, which would measure Nominatim rather than us. A town is also the case the old
     * name-geocoder handled BEST, so this panel systematically flatters the BEFORE column. It is
     * therefore a regression test ("does the repair break the rows that were already right?") far more
     * than a proof of the average gain. The average gain is measured on all 44,853 rows against the
     * postal source instead.
     *
     * The village name must be the exact `addresses.name`; the fourth element is the OSM query, which
     * often differs (LGD's "Gagan Bavda" is OSM's "Gaganbawada").
     *
     * @var list<array{0:string,1:string,2:string,3:string}>
     */
    private const OSM_PANEL = [
        ['Kolhapur', 'Gaganbawada', 'Gagan Bavda', 'Gaganbawada, Kolhapur, Maharashtra, India'],
        ['Sangli', 'Khanapur', 'Khanapur', 'Khanapur, Sangli district, Maharashtra, India'],
        ['Sangli', 'Atpadi', 'Atpadi', 'Atpadi, Sangli district, Maharashtra, India'],
        ['Kolhapur', 'Gaganbawada', 'Asalaj', 'Asalaj, Kolhapur, Maharashtra, India'],
        ['Pune', 'Baramati', 'Baramati', 'Baramati, Pune district, Maharashtra, India'],
        ['Pune', 'Junnar', 'Junnar', 'Junnar, Pune district, Maharashtra, India'],
        ['Satara', 'Karad', 'Karad', 'Karad, Satara district, Maharashtra, India'],
        ['Solapur', 'Pandharpur', 'Pandharpur', 'Pandharpur, Solapur district, Maharashtra, India'],
        ['Nashik', 'Malegaon', 'Malegaon', 'Malegaon, Nashik district, Maharashtra, India'],
        ['Ahilyanagar', 'Akole', 'Akole', 'Akole, Ahmednagar district, Maharashtra, India'],
        // "Paithan, Aurangabad district" resolves to a ROAD in Sambhajinagar city, 42 km from the town.
        ['Chhatrapati Sambhajinagar', 'Paithan', 'Paithan', 'Paithan town, Maharashtra, India'],
        ['Beed', 'Ambejogai', 'Ambajogai (Rural)', 'Ambajogai, Beed district, Maharashtra, India'],
        ['Latur', 'Ausa', 'Ausa', 'Ausa, Latur district, Maharashtra, India'],
        ['Dharashiv', 'Paranda', 'Paranda', 'Paranda, Osmanabad district, Maharashtra, India'],
        ['Nanded', 'Kinwat', 'Kinwat', 'Kinwat, Nanded district, Maharashtra, India'],
        ['Parbhani', 'Sonpeth', 'Sonpeth', 'Sonpeth, Parbhani district, Maharashtra, India'],
        ['Jalgaon', 'Bhusawal', 'Bhusawal', 'Bhusawal, Jalgaon district, Maharashtra, India'],
        ['Dhule', 'Shirpur', 'Shirpur Budruk', 'Shirpur, Dhule district, Maharashtra, India'],
        ['Amravati', 'Achalpur', 'Paratwada', 'Paratwada, Amravati district, Maharashtra, India'],
        ['Yavatmal', 'Pusad', 'Pusad Khand 1', 'Pusad, Yavatmal district, Maharashtra, India'],
        ['Nagpur', 'Katol', 'Katol', 'Katol, Nagpur district, Maharashtra, India'],
        ['Wardha', 'Hinganghat', 'Hinganghat', 'Hinganghat, Wardha district, Maharashtra, India'],
        ['Chandrapur', 'Warora', 'Warora', 'Warora, Chandrapur district, Maharashtra, India'],
        ['Gadchiroli', 'Aheri', 'Aheri', 'Aheri, Gadchiroli district, Maharashtra, India'],
        ['Ratnagiri', 'Chiplun', 'Chiplun', 'Chiplun, Ratnagiri district, Maharashtra, India'],
        ['Raigad', 'Alibag', 'Alibag', 'Alibag, Raigad district, Maharashtra, India'],
        ['Palghar', 'Talasari', 'Talasari', 'Talasari, Palghar district, Maharashtra, India'],
        ['Thane', 'Shahapur', 'Shahapur', 'Shahapur, Thane district, Maharashtra, India'],
    ];

    /** @var array<string, mixed> */
    private array $json = [];

    public function handle(): int
    {
        if (! $this->schemaReady()) {
            $this->error('Run `php artisan migrate` first — addresses.geo_source / address_geo_repairs are missing.');

            return self::FAILURE;
        }

        $rollback = trim((string) $this->option('rollback'));
        if ($rollback !== '') {
            return $this->rollback($rollback);
        }

        $stateSlug = strtolower(trim((string) $this->option('state')));
        $bounds = GeoCentroidBackfillService::STATE_BOUNDS[$stateSlug] ?? null;
        if ($bounds === null) {
            $this->error("No bounds configured for [{$stateSlug}]. Refusing to run on an unbounded state.");

            return self::FAILURE;
        }

        $csvPath = (string) ($this->option('csv')
            ?: 'E:/laravel backup/country,state,district,taluka,village,pincode/all india pincode/all india.csv');
        if (! is_file($csvPath)) {
            $this->error("CSV not found: {$csvPath}");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        $this->line('');
        $this->info(sprintf('India Post village-coordinate repair — state=%s  mode=%s',
            $stateSlug, $apply ? 'APPLY' : 'DRY RUN'));

        $dir = PostalDirectory::load($csvPath, $stateSlug, $bounds);
        $villages = $this->loadVillages($stateSlug, $bounds);
        $referees = $this->loadTalukaReferees($stateSlug);

        if ($force && $apply) {
            $this->restoreOriginals(array_column($villages, 'id'));
            $villages = $this->loadVillages($stateSlug, $bounds);
        }

        $decisions = $this->decide($dir, $villages, $referees, $force);

        $this->reportSources($dir, $villages);
        $this->reportDecisions($decisions, count($villages));
        $this->reportMovement($decisions);
        $this->reportPincodeAreaResidual($dir, $villages);

        if ($this->option('osm')) {
            $this->reportOsm($decisions);
        }

        $batch = null;
        if ($apply) {
            $batch = $this->write($decisions);
            $this->line('');
            $this->info("APPLIED. batch = {$batch}");
            $this->line("  undo with:  php artisan geo:repair-village-coordinates --rollback={$batch}");
            $this->warn('  Taluka and district centres are now STALE. Re-derive them:');
            $this->line('    php artisan locations:backfill-geo-centroids --force --state='.$stateSlug);
            $this->line('    php artisan locations:audit-geo-centroids --state='.$stateSlug);
        } else {
            $this->line('');
            $this->comment('DRY RUN — nothing written. Re-run with --apply to commit.');
        }

        $this->json['batch'] = $batch;
        $dump = (string) ($this->option('dump') ?: '');
        if ($dump !== '') {
            file_put_contents($dump, json_encode($this->json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("\nJSON dump → {$dump}");
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------------ loading

    private function schemaReady(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('addresses', 'geo_source')
            && \Illuminate\Support\Facades\Schema::hasTable('address_geo_repairs');
    }

    private function stateId(string $stateSlug): int
    {
        return (int) DB::table(Location::geoTable())
            ->where('hierarchy', 'state')->where('slug', $stateSlug)->value('id');
    }

    /**
     * @return list<array{id:int,name:string,norm:string,loose:string,pin:string,lat:?float,lng:?float,
     *                    tid:int,did:int,tal:string,dis:string,disNorm:string,src:?string}>
     */
    private function loadVillages(string $stateSlug, array $bounds): array
    {
        $geo = Location::geoTable();
        $stateId = $this->stateId($stateSlug);
        if ($stateId === 0) {
            throw new \RuntimeException("No state row with slug [{$stateSlug}].");
        }

        $out = [];
        DB::table("{$geo} as v")
            ->join("{$geo} as t", 't.id', '=', 'v.parent_id')
            ->join("{$geo} as d", 'd.id', '=', 't.parent_id')
            ->where('v.hierarchy', 'village')
            ->where('t.hierarchy', 'taluka')
            ->where('d.hierarchy', 'district')
            ->where('d.parent_id', $stateId)
            ->select('v.id', 'v.name', 'v.pincode', 'v.lat', 'v.lng', 'v.geo_source as src',
                't.id as tid', 't.name as tal', 'd.id as did', 'd.name as dis')
            ->orderBy('v.id')
            ->chunk(20000, function ($rows) use (&$out): void {
                foreach ($rows as $r) {
                    $out[] = [
                        'id' => (int) $r->id,
                        'name' => (string) $r->name,
                        'norm' => PostalDirectory::normStrict((string) $r->name),
                        'loose' => PostalDirectory::normLoose((string) $r->name),
                        'pin' => preg_replace('/\D/', '', (string) $r->pincode),
                        'lat' => $r->lat === null ? null : (float) $r->lat,
                        'lng' => $r->lng === null ? null : (float) $r->lng,
                        'src' => $r->src === null ? null : (string) $r->src,
                        'tid' => (int) $r->tid, 'did' => (int) $r->did,
                        'tal' => (string) $r->tal, 'dis' => (string) $r->dis,
                        'disNorm' => PostalDirectory::normDistrict((string) $r->dis),
                    ];
                }
            });

        return $out;
    }

    /**
     * Taluka centres that are ALREADY STORED. A stored centre exists only because it passed
     * {@see GeoCentroidBackfillService::accept()}, so a non-null value here is an accepted referee.
     *
     * @return array<int, array{lat:float,lng:float}>
     */
    private function loadTalukaReferees(string $stateSlug): array
    {
        $geo = Location::geoTable();
        $out = [];
        foreach (DB::table("{$geo} as t")
            ->join("{$geo} as d", 'd.id', '=', 't.parent_id')
            ->where('t.hierarchy', 'taluka')
            ->where('d.parent_id', $this->stateId($stateSlug))
            ->whereNotNull('t.lat')->whereNotNull('t.lng')
            ->select('t.id', 't.lat', 't.lng')->get() as $r) {
            $out[(int) $r->id] = ['lat' => (float) $r->lat, 'lng' => (float) $r->lng];
        }

        return $out;
    }

    // ------------------------------------------------------------------------ decision

    /**
     * @param  list<array<string,mixed>>  $villages
     * @param  array<int, array{lat:float,lng:float}>  $referees
     * @return list<array<string,mixed>>
     */
    private function decide(PostalDirectory $dir, array $villages, array $referees, bool $force): array
    {
        $out = [];

        foreach ($villages as $v) {
            $row = [
                'id' => $v['id'], 'name' => $v['name'], 'pin' => $v['pin'],
                'tal' => $v['tal'], 'dis' => $v['dis'],
                'old_lat' => $v['lat'], 'old_lng' => $v['lng'], 'old_src' => $v['src'],
                'new_lat' => null, 'new_lng' => null,
                'decision' => 'declined', 'match_type' => self::SOURCE_LEGACY,
                'reason' => null, 'moved' => null,
            ];

            // Idempotence: a row this command already stamped is final unless --force.
            if (! $force && $v['src'] !== null) {
                $row['reason'] = 'already_stamped';
                $row['match_type'] = $v['src'];
                $row['decision'] = 'skipped';
                $out[] = $row;

                continue;
            }

            $pin = $v['pin'];
            if ($pin === '' || strlen($pin) !== 6 || ! isset($dir->byPin[$pin])) {
                $row['reason'] = 'no_postal_pincode';
                $out[] = $row;

                continue;
            }

            // Cross-validation: the pincode must place the village in the district we already believe
            // it is in. A disagreement means the pincode is wrong in one source — never guess.
            if (! isset($dir->pinDistricts[$pin][$v['disNorm']])) {
                $row['reason'] = 'pincode_district_conflict';
                $out[] = $row;

                continue;
            }

            // The name+pincode tier is only as good as the office record behind it. Two CSV defects are
            // measurable and are screened out here; a rejected office falls THROUGH to the coarse tier,
            // which applies its own scatter gate, rather than being taken on trust or dropped outright.
            $named = $dir->officesNamed($pin, $v['norm'], $v['loose']);
            $namedRejected = null;
            if ($named !== null) {
                foreach ($named['idx'] as $i) {
                    if ($dir->pincodesOnPointOf($i) > self::MAX_PINCODES_PER_POINT) {
                        $namedRejected = 'office_point_bulk_filled';
                        break;
                    }
                    $peer = $dir->kmFromPincodePeers($i);
                    if ($peer !== null && $peer > self::MAX_OFFICE_PEER_DISTANCE_KM) {
                        $namedRejected = 'office_contradicts_its_pincode';
                        break;
                    }
                }
                if ($namedRejected !== null) {
                    $named = null;
                }
            }

            if ($named !== null) {
                $c = $dir->centreOfRows($named['idx']);
                $type = self::SOURCE_NAME_PIN;
                $reason = 'name_'.$named['tier'];
            } elseif ($dir->pinSpreadP90[$pin] <= self::PIN_SPREAD_MAX_KM) {
                $c = $dir->pinCentre[$pin];
                $type = self::SOURCE_PIN_AREA;
                $reason = 'pincode_area';

                // The coarse tier exists to rescue villages that are in the WRONG PLACE, not to blur
                // ones that are already in the right one. If the current point is inside the pincode's
                // own footprint, it is at least as good as the area median and more specific.
                if ($v['lat'] !== null && $v['lng'] !== null) {
                    $inside = max($dir->pinSpreadP90[$pin], self::PIN_AREA_KEEP_CURRENT_KM);
                    $dCur = GeoCentroidBackfillService::km($v['lat'], $v['lng'], $c['lat'], $c['lng']);
                    if ($dCur <= $inside) {
                        $row['reason'] = 'kept_current_inside_pincode_area';
                        $row['moved'] = round($dCur, 2);
                        $out[] = $row;

                        continue;
                    }
                }
            } else {
                $row['reason'] = $namedRejected ?? 'pincode_area_too_scattered';
                $out[] = $row;

                continue;
            }

            // Never make a row worse: the taluka's accepted centre gets the casting vote.
            $ref = $referees[$v['tid']] ?? null;
            if ($ref !== null && $v['lat'] !== null && $v['lng'] !== null) {
                $dOld = GeoCentroidBackfillService::km($v['lat'], $v['lng'], $ref['lat'], $ref['lng']);
                $dNew = GeoCentroidBackfillService::km($c['lat'], $c['lng'], $ref['lat'], $ref['lng']);
                if ($dOld <= GeoCentroidBackfillService::CONSENSUS_RADIUS_KM
                    && $dNew > $dOld + self::KEEP_CURRENT_MARGIN_KM) {
                    $row['reason'] = 'kept_current_closer_to_taluka';
                    $row['moved'] = round($dNew - $dOld, 2);
                    $out[] = $row;

                    continue;
                }
            }

            $row['new_lat'] = round($c['lat'], 7);
            $row['new_lng'] = round($c['lng'], 7);
            $row['match_type'] = $type;
            $row['reason'] = $reason;
            $row['decision'] = 'applied';
            $row['moved'] = ($v['lat'] === null || $v['lng'] === null) ? null
                : round(GeoCentroidBackfillService::km($v['lat'], $v['lng'], $c['lat'], $c['lng']), 2);
            $out[] = $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------------ writing

    /** @param list<array<string,mixed>> $decisions */
    private function write(array $decisions): string
    {
        $batch = (string) Str::uuid();
        $now = now();

        foreach (array_chunk($decisions, self::WRITE_CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, $batch, $now): void {
                $journal = [];
                foreach ($chunk as $d) {
                    if ($d['decision'] === 'skipped') {
                        continue;
                    }

                    if ($d['decision'] === 'applied') {
                        // Scoped by primary key, and the hierarchy guard is restated so a mistake in
                        // the id list still cannot write a taluka or district row.
                        DB::table(Location::geoTable())
                            ->where('id', $d['id'])
                            ->where('hierarchy', 'village')
                            ->update([
                                'lat' => $d['new_lat'],
                                'lng' => $d['new_lng'],
                                'geo_source' => $d['match_type'],
                                'updated_at' => $now,
                            ]);
                    } else {
                        // Declined rows are still stamped, so the next run knows they were assessed.
                        DB::table(Location::geoTable())
                            ->where('id', $d['id'])
                            ->where('hierarchy', 'village')
                            ->update(['geo_source' => self::SOURCE_LEGACY]);
                    }

                    $journal[] = [
                        'batch' => $batch,
                        'address_id' => $d['id'],
                        'old_lat' => $d['old_lat'],
                        'old_lng' => $d['old_lng'],
                        'old_geo_source' => $d['old_src'],
                        'new_lat' => $d['new_lat'],
                        'new_lng' => $d['new_lng'],
                        'decision' => $d['decision'],
                        'match_type' => $d['match_type'],
                        'reason' => $d['reason'],
                        'pincode' => $d['pin'] === '' ? null : $d['pin'],
                        'moved_km' => $d['moved'],
                        'created_at' => $now,
                    ];
                }
                if ($journal !== []) {
                    DB::table('address_geo_repairs')->insert($journal);
                }
            });
        }

        return $batch;
    }

    /**
     * `--force` path: put the journalled ORIGINAL coordinate back before re-assessing, so a re-run
     * derives from the same starting point the first run saw rather than from its own output.
     *
     * @param  list<int>  $ids
     */
    private function restoreOriginals(array $ids): void
    {
        $restored = 0;
        foreach (array_chunk($ids, 2000) as $chunk) {
            $earliest = DB::table('address_geo_repairs')
                ->selectRaw('address_id, MIN(id) as first_id')
                ->whereIn('address_id', $chunk)
                ->groupBy('address_id')
                ->pluck('first_id', 'address_id');

            if ($earliest->isEmpty()) {
                continue;
            }

            foreach (DB::table('address_geo_repairs')->whereIn('id', $earliest->values())->get() as $j) {
                DB::table(Location::geoTable())
                    ->where('id', $j->address_id)
                    ->where('hierarchy', 'village')
                    ->update(['lat' => $j->old_lat, 'lng' => $j->old_lng, 'geo_source' => $j->old_geo_source]);
                $restored++;
            }
        }
        $this->warn("  --force: restored {$restored} village rows to their first journalled coordinate.");
    }

    private function rollback(string $batch): int
    {
        $rows = DB::table('address_geo_repairs')->where('batch', $batch)->get();
        if ($rows->isEmpty()) {
            $this->error("No journal rows for batch [{$batch}].");

            return self::FAILURE;
        }

        $n = 0;
        foreach ($rows->chunk(self::WRITE_CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, &$n): void {
                foreach ($chunk as $j) {
                    DB::table(Location::geoTable())
                        ->where('id', $j->address_id)
                        ->where('hierarchy', 'village')
                        ->update([
                            'lat' => $j->old_lat,
                            'lng' => $j->old_lng,
                            'geo_source' => $j->old_geo_source,
                        ]);
                    $n++;
                }
            });
        }
        DB::table('address_geo_repairs')->where('batch', $batch)->delete();

        $this->info("Rolled back {$n} village rows from batch {$batch}; journal entries removed.");
        $this->warn('Taluka/district centres are stale again — re-run locations:backfill-geo-centroids --force.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------------ reporting

    private function reportSources(PostalDirectory $dir, array $villages): void
    {
        $csvPts = [];
        foreach ($dir->rows as $r) {
            $csvPts[$r['lat'].','.$r['lng']] = true;
        }
        $ourPts = [];
        foreach ($villages as $v) {
            if ($v['lat'] !== null) {
                $ourPts[$v['lat'].','.$v['lng']] = true;
            }
        }

        $this->line('');
        $this->info('--- sources -------------------------------------------------------------------');
        $this->line(sprintf('  CSV   usable offices=%d  (state rows=%d, NA coord=%d, out-of-box=%d)  distinct points=%d (%.1f%% reuse)  pincodes=%d',
            $dir->stats['usable'], $dir->stats['state_rows'], $dir->stats['na_coord'], $dir->stats['out_of_box'],
            count($csvPts), 100 - 100 * count($csvPts) / max(1, $dir->stats['usable']), count($dir->byPin)));
        $this->line(sprintf('  OURS  villages=%d  distinct points=%d (%.1f%% reuse)',
            count($villages), count($ourPts), 100 - 100 * count($ourPts) / max(1, count($villages))));

        $this->json['sources'] = [
            'csv' => $dir->stats + ['distinct_points' => count($csvPts), 'pincodes' => count($dir->byPin)],
            'ours' => ['villages' => count($villages), 'distinct_points' => count($ourPts)],
        ];
    }

    /** @param list<array<string,mixed>> $decisions */
    private function reportDecisions(array $decisions, int $total): void
    {
        $byType = [];
        $byReason = [];
        foreach ($decisions as $d) {
            $key = $d['decision'] === 'applied' ? $d['match_type'] : 'UNTOUCHED';
            $byType[$key] = ($byType[$key] ?? 0) + 1;
            $byReason[$d['reason'] ?? 'n/a'] = ($byReason[$d['reason'] ?? 'n/a'] ?? 0) + 1;
        }
        arsort($byReason);

        $this->line('');
        $this->info('--- decisions -----------------------------------------------------------------');
        foreach ($byType as $k => $n) {
            $this->line(sprintf('  %-26s %6d  (%.1f%%)', $k, $n, 100 * $n / max(1, $total)));
        }
        $this->line('  reasons:');
        foreach ($byReason as $k => $n) {
            $this->line(sprintf('    %-32s %6d', $k, $n));
        }

        $this->json['decisions'] = ['by_type' => $byType, 'by_reason' => $byReason, 'total' => $total];
    }

    /** @param list<array<string,mixed>> $decisions */
    private function reportMovement(array $decisions): void
    {
        $all = [];
        $byType = [];
        foreach ($decisions as $d) {
            if ($d['decision'] !== 'applied' || $d['moved'] === null) {
                continue;
            }
            $all[] = $d['moved'];
            $byType[$d['match_type']][] = $d['moved'];
        }

        $this->line('');
        $this->info('--- how far each repaired village moves (old point -> new point) --------------');
        $emit = function (array $x, string $label): void {
            if ($x === []) {
                return;
            }
            $this->line(sprintf('  %-26s n=%-6d p50=%.1f  p90=%.1f  p99=%.1f  max=%.0f km   >25km=%.1f%%  >100km=%.1f%%',
                $label, count($x),
                PostalDirectory::percentile($x, 50), PostalDirectory::percentile($x, 90),
                PostalDirectory::percentile($x, 99), max($x),
                100 * count(array_filter($x, fn ($k) => $k > 25)) / count($x),
                100 * count(array_filter($x, fn ($k) => $k > 100)) / count($x)));
        };
        $emit($all, 'ALL repaired');
        foreach ($byType as $t => $x) {
            $emit($x, $t);
        }

        $this->json['movement'] = array_map(
            fn (array $x) => [
                'n' => count($x),
                'p50' => round(PostalDirectory::percentile($x, 50), 2),
                'p90' => round(PostalDirectory::percentile($x, 90), 2),
                'p99' => round(PostalDirectory::percentile($x, 99), 2),
                'max' => round(max($x), 1),
            ],
            array_filter(['all' => $all] + $byType, fn ($x) => $x !== [])
        );
    }

    /**
     * OUTSIDE TRUTH. Everything above compares two of our own datasets to each other, which can only
     * show that they disagree — not which one is right. This asks a third, independent source.
     *
     * Runs on the DECISIONS, so it scores the repair before it is committed: BEFORE is the coordinate
     * currently in `addresses`, AFTER is the coordinate this run would write (or the same value again
     * where the row was left untouched).
     *
     * @param  list<array<string,mixed>>  $decisions
     */
    private function reportOsm(array $decisions): void
    {
        $byPlace = [];
        foreach ($decisions as $d) {
            $byPlace[strtolower($d['dis'].'|'.$d['tal'].'|'.PostalDirectory::normLoose($d['name']))] = $d;
        }

        $this->line('');
        $this->info('--- OUTSIDE CHECK — OpenStreetMap / Nominatim ---------------------------------');
        $this->line(sprintf('  %-42s %9s %9s %9s  %s', 'district / taluka / village', 'BEFORE', 'AFTER', 'GAIN', 'match type'));

        $rows = [];
        foreach (self::OSM_PANEL as [$dis, $tal, $vil, $query]) {
            $d = $byPlace[strtolower($dis.'|'.$tal.'|'.PostalDirectory::normLoose($vil))] ?? null;
            $label = "{$dis}/{$tal}/{$vil}";
            if ($d === null) {
                $this->line(sprintf('  %-42s %9s', $label, 'no such village row'));

                continue;
            }

            $truth = NominatimClient::first([$query]);
            if ($truth === null) {
                $this->line(sprintf('  %-42s %9s', $label, 'no OSM hit'));

                continue;
            }

            $before = ($d['old_lat'] === null) ? null
                : GeoCentroidBackfillService::km($truth[0], $truth[1], $d['old_lat'], $d['old_lng']);
            $after = ($d['new_lat'] === null) ? $before
                : GeoCentroidBackfillService::km($truth[0], $truth[1], $d['new_lat'], $d['new_lng']);

            $fmt = fn (?float $x) => $x === null ? '—' : sprintf('%.1f', $x);
            $gain = ($before === null || $after === null) ? null : $before - $after;
            $this->line(sprintf('  %-42s %9s %9s %9s  %s', $label, $fmt($before), $fmt($after),
                $gain === null ? '—' : sprintf('%+.1f', $gain),
                $d['new_lat'] === null ? 'UNTOUCHED ('.$d['reason'].')' : $d['match_type']));

            $rows[] = [
                'place' => $label,
                'osm' => ['lat' => $truth[0], 'lng' => $truth[1]],
                'before_km' => $before === null ? null : round($before, 1),
                'after_km' => $after === null ? null : round($after, 1),
                'match_type' => $d['new_lat'] === null ? 'untouched' : $d['match_type'],
                'reason' => $d['reason'],
            ];
        }

        $b = array_values(array_filter(array_column($rows, 'before_km'), fn ($x) => $x !== null));
        $a = array_values(array_filter(array_column($rows, 'after_km'), fn ($x) => $x !== null));
        if ($b !== [] && $a !== []) {
            $this->line('');
            $this->line(sprintf('  BEFORE  n=%d  median=%.1f km  p90=%.1f km  max=%.0f km',
                count($b), PostalDirectory::percentile($b, 50), PostalDirectory::percentile($b, 90), max($b)));
            $this->line(sprintf('  AFTER   n=%d  median=%.1f km  p90=%.1f km  max=%.0f km',
                count($a), PostalDirectory::percentile($a, 50), PostalDirectory::percentile($a, 90), max($a)));
        }

        $this->json['osm'] = $rows;
    }

    /**
     * Honest cost of the coarse tier, measured where the truth IS known: for every village that got an
     * exact name+pincode office, how far would the whole-pincode median have put it instead? That is
     * the residual error a `india_post_pincode_area` row still carries — reported with and without the
     * scatter gate so the gate can be judged rather than assumed.
     */
    private function reportPincodeAreaResidual(PostalDirectory $dir, array $villages): void
    {
        $gated = [];
        $ungated = [];
        foreach ($villages as $v) {
            $pin = $v['pin'];
            if ($pin === '' || ! isset($dir->byPin[$pin]) || ! isset($dir->pinDistricts[$pin][$v['disNorm']])) {
                continue;
            }
            $named = $dir->officesNamed($pin, $v['norm'], $v['loose']);
            if ($named === null) {
                continue;
            }
            $truth = $dir->centreOfRows($named['idx']);
            $area = $dir->pinCentre[$pin];
            $km = GeoCentroidBackfillService::km($truth['lat'], $truth['lng'], $area['lat'], $area['lng']);
            $ungated[] = $km;
            if ($dir->pinSpreadP90[$pin] <= self::PIN_SPREAD_MAX_KM) {
                $gated[] = $km;
            }
        }

        $this->line('');
        $this->info('--- residual error of the pincode-area tier (measured on known-truth rows) -----');
        foreach (['no scatter gate' => $ungated, 'scatter gate <= '.self::PIN_SPREAD_MAX_KM.' km' => $gated] as $label => $x) {
            if ($x === []) {
                continue;
            }
            $this->line(sprintf('  %-28s n=%-6d p50=%.1f  p90=%.1f  p99=%.1f  max=%.0f km   >25km=%.1f%%',
                $label, count($x),
                PostalDirectory::percentile($x, 50), PostalDirectory::percentile($x, 90),
                PostalDirectory::percentile($x, 99), max($x),
                100 * count(array_filter($x, fn ($k) => $k > 25)) / count($x)));
        }

        $this->json['pincode_area_residual'] = [
            'ungated' => ['n' => count($ungated), 'p50' => round(PostalDirectory::percentile($ungated, 50), 2),
                'p90' => round(PostalDirectory::percentile($ungated, 90), 2), 'p99' => round(PostalDirectory::percentile($ungated, 99), 2)],
            'gated' => ['n' => count($gated), 'p50' => round(PostalDirectory::percentile($gated, 50), 2),
                'p90' => round(PostalDirectory::percentile($gated, 90), 2), 'p99' => round(PostalDirectory::percentile($gated, 99), 2)],
        ];
    }
}
