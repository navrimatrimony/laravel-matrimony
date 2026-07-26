<?php

namespace Tests\Unit\Gunamilan;

use App\Models\MatrimonyProfile;
use App\Models\ProfileHoroscopeData;
use App\Models\User;
use App\Services\Gunamilan\GunamilanKootaKey;
use App\Services\Gunamilan\GunamilanMasterData;
use App\Services\Gunamilan\GunamilanService;
use App\Services\Gunamilan\MangalCompatibility;
use Database\Seeders\AshtakootaMasterSeeder;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\NakshatraAttributesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards for the four verified Gunamilan defects and for the performance
 * ceiling that makes the engine safe to call from the matching feed.
 */
class GunamilanEngineTest extends TestCase
{
    use RefreshDatabase;

    private GunamilanService $gunamilan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterLookupSeeder::class);
        $this->seed(AshtakootaMasterSeeder::class);
        $this->seed(NakshatraAttributesSeeder::class);

        // The master snapshot is a container singleton; drop whatever was
        // memoised before the seeders ran.
        app(GunamilanMasterData::class)->forget();

        $this->gunamilan = app(GunamilanService::class);
    }

    // ---------- defect 1: yoni duplicates ----------

    public function test_yoni_enemy_pair_scores_zero(): void
    {
        // Ashwa (horse) vs Mahish (buffalo) is one of the seven enemy pairs.
        $result = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha')],
            ['nakshatra_id' => $this->nakshatraId('hasta'), 'rashi_id' => $this->rashiId('kanya')],
        );

        $yoni = $this->section($result, 'yoni');

        $this->assertSame('ashwa', $this->kootaKey(['nakshatra_id' => $this->nakshatraId('ashwini')])->yoniKey);
        $this->assertSame('mahish', $this->kootaKey(['nakshatra_id' => $this->nakshatraId('hasta')])->yoniKey);
        $this->assertSame(0.0, $yoni['points'], 'An enemy yoni pair must score 0 of 4.');
    }

    public function test_same_yoni_scores_four_even_when_one_side_stores_a_retired_english_key(): void
    {
        // Production drift: a profile saved against the old English duplicate
        // row. It must still compare equal to the canonical Sanskrit value.
        $legacyHorseId = DB::table('master_yonis')->insertGetId([
            'key' => 'horse',
            'label' => 'Horse',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(GunamilanMasterData::class)->forget();

        $canonicalAshwaId = DB::table('master_yonis')->where('key', 'ashwa')->value('id');

        $result = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha'), 'yoni_id' => $canonicalAshwaId],
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha'), 'yoni_id' => $legacyHorseId],
        );

        $this->assertSame(4.0, $this->section($result, 'yoni')['points'], 'The same animal under two spellings must score the full 4.');
    }

    public function test_master_yonis_holds_one_active_row_per_canonical_key(): void
    {
        $activeKeys = DB::table('master_yonis')->where('is_active', true)->pluck('key')->all();
        $canonical = array_values(array_filter(
            $activeKeys,
            fn (string $key): bool => GunamilanMasterData::canonicalYoniKeyFor($key) !== null
        ));

        $this->assertCount(14, $canonical, 'Exactly the 14 canonical yonis may be active.');
        $this->assertSame(
            GunamilanMasterData::CANONICAL_YONI_KEYS,
            array_values(array_intersect(GunamilanMasterData::CANONICAL_YONI_KEYS, $canonical)),
        );
        $this->assertSame($canonical, array_unique($canonical), 'No canonical yoni key may appear twice.');
    }

    public function test_every_nakshatra_attribute_row_resolves_to_a_canonical_yoni(): void
    {
        $masters = app(GunamilanMasterData::class);

        $unresolved = DB::table('master_nakshatra_attributes')
            ->pluck('yoni_id')
            ->filter()
            ->reject(fn ($id): bool => $masters->canonicalYoniKey((int) $id) !== null)
            ->all();

        $this->assertSame([], array_values($unresolved));
    }

    public function test_vashya_matrix_covers_the_keet_key_that_used_to_fall_through(): void
    {
        // Vrishchika is the only `keet` rashi. It used to hit an undocumented
        // 0.5 fallback because `keet` was absent from the pair table.
        $result = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('vishakha'), 'rashi_id' => $this->rashiId('vrishchika')],
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha')],
        );

        $this->assertSame(1.0, $this->section($result, 'vashya')['points']);
    }

    public function test_other_master_rows_are_treated_as_missing_not_as_a_value(): void
    {
        // Two identical `other` nadis must NOT be scored as a Nadi dosha, and
        // two `other` yonis must NOT collect the full 4 points.
        $otherRashi = DB::table('master_rashis')->where('key', 'other')->value('id');
        $otherNakshatra = DB::table('master_nakshatras')->where('key', 'other')->value('id');
        $otherNadi = DB::table('master_nadis')->where('key', 'other')->value('id');
        $otherYoni = DB::table('master_yonis')->where('key', 'other')->value('id');

        $row = [
            'rashi_id' => $otherRashi,
            'nakshatra_id' => $otherNakshatra,
            'nadi_id' => $otherNadi,
            'yoni_id' => $otherYoni,
        ];

        $result = $this->compareHoroscopes($row, $row);

        $this->assertFalse($result['computable']);
        $this->assertSame(0.0, $this->section($result, 'nadi')['points']);
        $this->assertNull($result['nadi_dosha'], '"other" nadi on both sides is unknown, not a dosha.');
        $this->assertSame(0.0, $this->section($result, 'yoni')['points']);
        $this->assertSame('missing', $this->section($result, 'yoni')['status']);
    }

    // ---------- defect 2: no data must never read as incompatible ----------

    public function test_all_null_horoscope_row_is_not_computable_and_not_incompatible(): void
    {
        [$male, $female] = $this->profilePair();

        ProfileHoroscopeData::create(['profile_id' => $male->id]);
        ProfileHoroscopeData::create(['profile_id' => $female->id]);

        $result = $this->gunamilan->calculate($male, $female);

        $this->assertTrue($result['available'], 'Both horoscope rows exist, so the older `available` flag stays true.');
        $this->assertFalse($result['computable']);
        $this->assertSame('not_computable', $result['state']);
        $this->assertNull($result['is_compatible'], 'An empty horoscope is UNKNOWN, never incompatible.');
        $this->assertNotSame(false, $result['is_compatible']);
        $this->assertNotEmpty($result['missing_fields']);
        $this->assertSame(0.0, $result['total_points']);
    }

    public function test_missing_horoscope_row_is_not_computable(): void
    {
        [$male, $female] = $this->profilePair();
        ProfileHoroscopeData::create([
            'profile_id' => $male->id,
            'rashi_id' => $this->rashiId('mesha'),
            'nakshatra_id' => $this->nakshatraId('ashwini'),
        ]);

        $result = $this->gunamilan->calculate($male, $female);

        $this->assertFalse($result['available']);
        $this->assertFalse($result['computable']);
        $this->assertNull($result['is_compatible']);
    }

    public function test_a_complete_pair_is_computable(): void
    {
        $result = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha')],
            ['nakshatra_id' => $this->nakshatraId('rohini'), 'rashi_id' => $this->rashiId('vrishabha')],
        );

        $this->assertTrue($result['computable']);
        $this->assertSame([], $result['missing_fields']);
        $this->assertIsBool($result['is_compatible']);
    }

    // ---------- defect 3: 18 of 36 is compatible, inclusive ----------

    public function test_threshold_is_eighteen_inclusive(): void
    {
        $this->assertSame(18.0, GunamilanService::COMPATIBLE_THRESHOLD);

        $pair = $this->findPairScoringExactly(18.0);
        $this->assertNotNull($pair, 'Expected at least one rashi/nakshatra pair scoring exactly 18.0 of 36.');

        $this->assertSame(18.0, $pair['total_points']);
        $this->assertTrue($pair['is_compatible'], 'Exactly 18.0 must count as compatible.');
        $this->assertSame(18.0, $pair['threshold']);
    }

    public function test_a_score_just_below_the_threshold_is_not_compatible(): void
    {
        $pair = $this->findPairScoringExactly(17.5) ?? $this->findPairScoringExactly(17.0);
        $this->assertNotNull($pair);

        $this->assertFalse($pair['is_compatible']);
    }

    // ---------- defect 4: expose the doshas ----------

    public function test_nadi_dosha_flag_is_explicit(): void
    {
        // Ashwini and Shatabhisha are both Adi nadi -> Nadi dosha.
        $sameNadi = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha')],
            ['nakshatra_id' => $this->nakshatraId('shatabhisha'), 'rashi_id' => $this->rashiId('kumbha')],
        );

        $this->assertTrue($sameNadi['nadi_dosha']);
        $this->assertTrue($this->section($sameNadi, 'nadi')['is_dosha']);
        $this->assertSame(0.0, $this->section($sameNadi, 'nadi')['points']);

        // Ashwini (Adi) vs Bharani (Madhya) -> no Nadi dosha.
        $differentNadi = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha')],
            ['nakshatra_id' => $this->nakshatraId('bharani'), 'rashi_id' => $this->rashiId('mesha')],
        );

        $this->assertFalse($differentNadi['nadi_dosha']);
        $this->assertSame(8.0, $this->section($differentNadi, 'nadi')['points']);
    }

    public function test_bhakoot_dosha_flag_is_explicit(): void
    {
        // Mesha (1) vs Vrishabha (2) is the 2/12 relation -> Bhakoot dosha.
        $dosha = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha')],
            ['nakshatra_id' => $this->nakshatraId('rohini'), 'rashi_id' => $this->rashiId('vrishabha')],
        );

        $this->assertTrue($dosha['bhakoot_dosha']);
        $this->assertTrue($this->section($dosha, 'bhakoot')['is_dosha']);
        $this->assertSame(0.0, $this->section($dosha, 'bhakoot')['points']);

        // Same rashi -> no Bhakoot dosha, full 7.
        $clean = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha')],
            ['nakshatra_id' => $this->nakshatraId('bharani'), 'rashi_id' => $this->rashiId('mesha')],
        );

        $this->assertFalse($clean['bhakoot_dosha']);
        $this->assertSame(7.0, $this->section($clean, 'bhakoot')['points']);
    }

    public function test_dosha_flags_are_null_when_not_computable(): void
    {
        $result = $this->compareHoroscopes([], []);

        $this->assertNull($result['nadi_dosha']);
        $this->assertNull($result['bhakoot_dosha']);
    }

    // ---------- mangal ----------

    public function test_mangal_both_non_manglik_is_compatible(): void
    {
        $mangal = $this->mangalFor('none', 'none');

        $this->assertSame(MangalCompatibility::STATUS_COMPATIBLE, $mangal['status']);
        $this->assertTrue($mangal['is_compatible']);
        $this->assertSame(1.0, $mangal['score']);
    }

    public function test_mangal_both_manglik_is_compatible(): void
    {
        $mangal = $this->mangalFor('bhumangal', 'anshik_mangal');

        $this->assertSame(MangalCompatibility::STATUS_COMPATIBLE, $mangal['status']);
        $this->assertTrue($mangal['is_compatible']);
    }

    public function test_mangal_one_sided_is_not_compatible(): void
    {
        $mangal = $this->mangalFor('bhumangal', 'none');

        $this->assertSame(MangalCompatibility::STATUS_NOT_COMPATIBLE, $mangal['status']);
        $this->assertFalse($mangal['is_compatible']);
        $this->assertSame(0.0, $mangal['score']);
    }

    public function test_mangal_unknown_is_never_a_rejection(): void
    {
        foreach ([['don_t_know', 'none'], ['none', 'don_t_know'], ['other', 'bhumangal']] as [$brideKey, $groomKey]) {
            $mangal = $this->mangalFor($brideKey, $groomKey);

            $this->assertSame(MangalCompatibility::STATUS_NOT_COMPUTABLE, $mangal['status'], "$brideKey vs $groomKey");
            $this->assertNull($mangal['is_compatible']);
            $this->assertNull($mangal['score']);
            $this->assertNotSame(false, $mangal['is_compatible']);
        }
    }

    public function test_mangal_not_filled_at_all_is_not_computable(): void
    {
        $mangal = $this->compareHoroscopes([], [])['mangal'];

        $this->assertSame(MangalCompatibility::STATUS_NOT_COMPUTABLE, $mangal['status']);
        $this->assertNull($mangal['is_compatible']);
    }

    public function test_mangal_weight_is_low_relative_to_the_thirty_six_guna_score(): void
    {
        $this->assertLessThanOrEqual(0.1, MangalCompatibility::WEIGHT);
        $this->assertGreaterThan(0.0, MangalCompatibility::WEIGHT);
    }

    public function test_mangal_is_reported_separately_from_the_thirty_six_points(): void
    {
        $result = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha'), 'mangal_dosh_type_id' => $this->mangalId('bhumangal')],
            ['nakshatra_id' => $this->nakshatraId('bharani'), 'rashi_id' => $this->rashiId('mesha'), 'mangal_dosh_type_id' => $this->mangalId('none')],
        );

        $withoutMangal = $this->compareHoroscopes(
            ['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha')],
            ['nakshatra_id' => $this->nakshatraId('bharani'), 'rashi_id' => $this->rashiId('mesha')],
        );

        $this->assertSame($withoutMangal['total_points'], $result['total_points'], 'Mangal must not move the 36-guna total.');
        $this->assertSame(MangalCompatibility::STATUS_NOT_COMPATIBLE, $result['mangal']['status']);
        $this->assertSame(36.0, $result['max_points']);
    }

    // ---------- performance ----------

    public function test_a_pair_comparison_issues_no_queries_after_warm_up(): void
    {
        $bride = $this->kootaKey(['nakshatra_id' => $this->nakshatraId('ashwini'), 'rashi_id' => $this->rashiId('mesha')]);
        $groom = $this->kootaKey(['nakshatra_id' => $this->nakshatraId('rohini'), 'rashi_id' => $this->rashiId('vrishabha')]);
        $this->gunamilan->compare($bride, $groom);

        $queries = $this->countQueries(function () use ($bride, $groom): void {
            for ($i = 0; $i < 50; $i++) {
                $this->gunamilan->compare($bride, $groom);
            }
        });

        // Hard ceiling: the N+1 that cost 24 queries per pair must not return.
        $this->assertSame(0, $queries, '50 warm pair comparisons must issue zero queries.');
    }

    public function test_calculate_on_loaded_profiles_issues_no_queries_after_warm_up(): void
    {
        [$male, $female] = $this->profilePair();
        ProfileHoroscopeData::create([
            'profile_id' => $male->id,
            'rashi_id' => $this->rashiId('mesha'),
            'nakshatra_id' => $this->nakshatraId('ashwini'),
        ]);
        ProfileHoroscopeData::create([
            'profile_id' => $female->id,
            'rashi_id' => $this->rashiId('vrishabha'),
            'nakshatra_id' => $this->nakshatraId('rohini'),
        ]);

        $male->load(['gender', 'horoscope']);
        $female->load(['gender', 'horoscope']);
        $this->gunamilan->calculate($male, $female);

        $queries = $this->countQueries(function () use ($male, $female): void {
            for ($i = 0; $i < 20; $i++) {
                $this->gunamilan->calculate($male, $female);
            }
        });

        $this->assertSame(0, $queries, 'calculate() on eager-loaded profiles must not hit the database.');
    }

    public function test_building_a_koota_key_does_not_query_after_warm_up(): void
    {
        app(GunamilanMasterData::class)->all();
        $rashiId = $this->rashiId('mesha');

        $queries = $this->countQueries(function () use ($rashiId): void {
            for ($i = 1; $i <= 27; $i++) {
                $this->kootaKey(['nakshatra_id' => $i, 'rashi_id' => $rashiId]);
            }
        });

        $this->assertSame(0, $queries);
    }

    // ---------- helpers ----------

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function kootaKey(array $attributes): GunamilanKootaKey
    {
        return $this->gunamilan->kootaKeyForHoroscope(new ProfileHoroscopeData($attributes));
    }

    /**
     * @param  array<string, mixed>  $bride
     * @param  array<string, mixed>  $groom
     * @return array<string, mixed>
     */
    private function compareHoroscopes(array $bride, array $groom): array
    {
        return $this->gunamilan->compare($this->kootaKey($bride), $this->kootaKey($groom));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function section(array $result, string $key): array
    {
        foreach ($result['sections'] as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        $this->fail("Section [$key] is missing from the result.");
    }

    /**
     * @return array<string, mixed>
     */
    private function mangalFor(string $brideKey, string $groomKey): array
    {
        return $this->compareHoroscopes(
            ['mangal_dosh_type_id' => $this->mangalId($brideKey)],
            ['mangal_dosh_type_id' => $this->mangalId($groomKey)],
        )['mangal'];
    }

    /**
     * Walk real rashi/nakshatra combinations until one totals exactly $target.
     * Pure array math once the masters are warm, so this is cheap.
     *
     * @return array<string, mixed>|null
     */
    private function findPairScoringExactly(float $target): ?array
    {
        $rashiIds = DB::table('master_rashis')->where('key', '!=', 'other')->pluck('id')->all();
        $nakshatraIds = DB::table('master_nakshatras')->whereNotNull('nakshatra_number')->pluck('id')->all();

        foreach ($nakshatraIds as $brideNakshatra) {
            foreach ($nakshatraIds as $groomNakshatra) {
                foreach ($rashiIds as $brideRashi) {
                    foreach ($rashiIds as $groomRashi) {
                        $result = $this->compareHoroscopes(
                            ['nakshatra_id' => $brideNakshatra, 'rashi_id' => $brideRashi],
                            ['nakshatra_id' => $groomNakshatra, 'rashi_id' => $groomRashi],
                        );
                        if ($result['computable'] && $result['total_points'] === $target) {
                            return $result;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: MatrimonyProfile, 1: MatrimonyProfile}
     */
    private function profilePair(): array
    {
        $male = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'gender_id' => DB::table('master_genders')->where('key', 'male')->value('id'),
            'lifecycle_state' => 'draft',
        ]);
        $female = MatrimonyProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
            'gender_id' => DB::table('master_genders')->where('key', 'female')->value('id'),
            'lifecycle_state' => 'draft',
        ]);

        return [$male, $female];
    }

    private function rashiId(string $key): int
    {
        return (int) DB::table('master_rashis')->where('key', $key)->value('id');
    }

    private function nakshatraId(string $key): int
    {
        return (int) DB::table('master_nakshatras')->where('key', $key)->value('id');
    }

    private function mangalId(string $key): int
    {
        return (int) DB::table('master_mangal_dosh_types')->where('key', $key)->value('id');
    }
}
