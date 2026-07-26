<?php

namespace Tests\Feature\Matching;

use App\Models\Location;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\ProfileHoroscopeData;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakMatchFitService;
use App\Services\Gunamilan\GunamilanPairEvaluator;
use App\Services\Gunamilan\GunamilanService;
use App\Services\Gunamilan\MangalCompatibility;
use App\Services\Matching\MatchingService;
use App\Services\Matching\MatchRelaxationLadder;
use App\Services\ProfilePreferenceMatchService;
use Database\Seeders\AshtakootaMasterSeeder;
use Database\Seeders\MasterLookupSeeder;
use Database\Seeders\NakshatraAttributesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * गुणमिलन wired into the matching pipeline.
 *
 * The single most important guarantee here is the NEGATIVE one: only ~13% of profiles carry
 * nakshatra + rashi, so "we could not compute a score" must never behave, read or score like "these
 * two do not match". Every path below is written to fail loudly if that ever inverts.
 */
class GunamilanMatchingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $maleGenderId;

    private int $femaleGenderId;

    private Location $village;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterLookupSeeder::class);
        $this->seed(AshtakootaMasterSeeder::class);
        $this->seed(NakshatraAttributesSeeder::class);
        app(\App\Services\Gunamilan\GunamilanMasterData::class)->forget();

        $this->maleGenderId = (int) MasterGender::query()->firstOrCreate(['key' => 'male'], ['label' => 'Male', 'is_active' => true])->id;
        $this->femaleGenderId = (int) MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true])->id;

        $this->makeGeography();

        ProfilePreferenceMatchService::flushRuntimeCaches();
    }

    // -------------------------------------------------------------------------------------------
    // The preference row truth table
    // -------------------------------------------------------------------------------------------

    public function test_required_and_compatible_is_a_match_row_that_never_excludes(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable'] && $r['total_points'] >= GunamilanService::COMPATIBLE_THRESHOLD);
        [$seeker, $candidate] = $this->pairWithHoroscopes($combo);
        $this->requireGunamilan($seeker);

        $row = $this->gunamilanRow($candidate, $seeker);

        $this->assertSame(ProfilePreferenceMatchService::STATUS_MATCH, $row['status']);
        $this->assertSame(ProfilePreferenceMatchService::STRICT_MUST_MATCH, $row['strictness']);
        $this->assertTrue($row['declared_must_match']);
        $this->assertTrue(app(MatchingService::class)->isEligiblePair($seeker, $candidate));
    }

    public function test_required_and_incompatible_is_fatal_at_strict_and_relaxes_at_tier_four(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable'] && $r['total_points'] < GunamilanService::COMPATIBLE_THRESHOLD);
        [$seeker, $candidate] = $this->pairWithHoroscopes($combo);
        $this->requireGunamilan($seeker);

        $row = $this->gunamilanRow($candidate, $seeker);
        $this->assertSame(ProfilePreferenceMatchService::STATUS_NOT_MATCHED, $row['status']);
        $this->assertTrue($row['declared_must_match']);

        $service = app(MatchingService::class);
        $build = ProfilePreferenceMatchService::build($candidate, $seeker);

        $this->assertTrue(
            $service->evaluatePreferenceBuild($build, MatchRelaxationLadder::TIER_STRICT)['fatal'],
            'A stated Gunamilan requirement that a COMPUTED score fails must exclude at the strict tier.'
        );
        $this->assertTrue($service->evaluatePreferenceBuild($build, MatchRelaxationLadder::TIER_RELAXED_CASTE)['fatal']);
        $this->assertFalse(
            $service->evaluatePreferenceBuild($build, MatchRelaxationLadder::TIER_RELAXED_GUNAMILAN)['fatal'],
            'Gunamilan is the tier-4 rung: at the top tier the mismatch is tolerated, not fatal.'
        );
        $this->assertContains(
            'gunamilan',
            MatchRelaxationLadder::relaxedFieldsUpTo(MatchRelaxationLadder::TIER_RELAXED_GUNAMILAN),
            'Tier 4 must be picked up straight from config/matching.php with no code change.'
        );

        // ...and the whole feed behaves the same way: excluded strictly, re-admitted at tier 4.
        $this->assertFalse($service->isEligiblePair($seeker, $candidate));

        config(['matching.relaxation.floor' => 500]);
        ProfilePreferenceMatchService::flushRuntimeCaches();
        $rows = $service->findMatches($seeker->fresh(), 24);

        $admitted = $rows->first(fn (array $r): bool => (int) $r['profile']->getKey() === (int) $candidate->getKey());
        $this->assertNotNull($admitted, 'Tier 4 must re-admit the candidate the strict tier refused.');
        $this->assertSame(MatchRelaxationLadder::TIER_RELAXED_GUNAMILAN, $admitted['tier']);
        $this->assertSame(MatchRelaxationLadder::maxTier(), $service->lastRelaxationSummary()['tier']);
        $this->assertContains('gunamilan', $service->lastRelaxationSummary()['relaxed_fields']);
    }

    /**
     * THE line. Required, but one side has no patrika on file: `unknown`, `open`, and never excluded
     * at any tier — including the strict one.
     */
    public function test_required_but_missing_data_on_either_side_is_unknown_and_never_excludes(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable']);

        foreach (['candidate', 'seeker'] as $blankSide) {
            ProfilePreferenceMatchService::flushRuntimeCaches();

            [$seeker, $candidate] = $this->pairWithHoroscopes(
                $combo,
                withSeekerHoroscope: $blankSide !== 'seeker',
                withCandidateHoroscope: $blankSide !== 'candidate',
            );
            $this->requireGunamilan($seeker);

            $row = $this->gunamilanRow($candidate, $seeker);

            $this->assertSame(
                ProfilePreferenceMatchService::STATUS_UNKNOWN,
                $row['status'],
                "Missing patrika data on the {$blankSide} side must be UNKNOWN, never not_matched."
            );
            $this->assertNotSame(ProfilePreferenceMatchService::STATUS_NOT_MATCHED, $row['status']);
            $this->assertSame(
                ProfilePreferenceMatchService::STRICT_OPEN,
                $row['strictness'],
                'An unknown verdict must stay `open` so nothing downstream can promote it to a filter.'
            );

            $service = app(MatchingService::class);
            $this->assertFalse(
                $service->evaluatePreferenceBuild(
                    ProfilePreferenceMatchService::build($candidate, $seeker),
                    MatchRelaxationLadder::TIER_STRICT
                )['fatal'],
                "Missing patrika data on the {$blankSide} side must not exclude, even at the strict tier."
            );
            $this->assertTrue($service->isEligiblePair($seeker, $candidate));

            // And the wording must not read as a rejection.
            $this->assertSame(__('preference_match.reason_gunamilan_unknown'), $row['reason']);
            $this->assertNotSame(__('preference_match.reason_gunamilan_not_matched', ['points' => '0', 'max' => '36', 'min' => '18']), $row['reason']);
        }
    }

    public function test_not_requiring_gunamilan_affects_nothing(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable'] && $r['total_points'] < GunamilanService::COMPATIBLE_THRESHOLD);
        [$seeker, $candidate] = $this->pairWithHoroscopes($combo);
        // Deliberately NOT calling requireGunamilan(): the column defaults to false.

        $build = ProfilePreferenceMatchService::build($candidate, $seeker);
        $row = $this->rowById($build, 'gunamilan');

        $this->assertSame(ProfilePreferenceMatchService::STATUS_FLEXIBLE, $row['status']);
        $this->assertSame(ProfilePreferenceMatchService::STRICT_OPEN, $row['strictness']);
        $this->assertFalse($row['declared_must_match']);

        // The aggregate counts (fit badge + the `preferences` score component) must not move: this row
        // has its own weighted component, and a row nobody asked for may not rescore the whole product.
        $counted = array_sum($build['counts']);
        $this->assertSame(
            count($build['rows']) - 1,
            $counted,
            'The gunamilan row must be excluded from the aggregate counts.'
        );

        $this->assertTrue(app(MatchingService::class)->isEligiblePair($seeker, $candidate));
    }

    // -------------------------------------------------------------------------------------------
    // Scoring
    // -------------------------------------------------------------------------------------------

    public function test_missing_data_scores_zero_with_no_penalty(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable']);

        [$blankSeeker, $blankCandidate] = $this->pairWithHoroscopes($combo, withSeekerHoroscope: false, withCandidateHoroscope: false);
        $blank = app(MatchingService::class)->computeMatchBreakdown($blankSeeker, $blankCandidate, false);

        $this->assertSame(0, $blank['field_points']['gunamilan'], 'Not computable must contribute 0 points.');

        $others = 0;
        foreach ($blank['field_points'] as $key => $points) {
            if ($key !== 'gunamilan') {
                $others += (int) $points;
            }
        }
        $this->assertSame(
            min(100, $others),
            (int) $blank['before_boost'],
            'A pair with no patrika data must score exactly what the other nine components give it — '
            .'0 here is the absence of a bonus, never a deduction.'
        );
    }

    public function test_a_computable_gunamilan_adds_points_without_touching_the_other_components(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable'] && $r['total_points'] >= GunamilanService::COMPATIBLE_THRESHOLD);

        [$seeker, $candidate] = $this->pairWithHoroscopes($combo);
        $withData = app(MatchingService::class)->computeMatchBreakdown($seeker, $candidate, false);

        $this->assertGreaterThan(0, $withData['field_points']['gunamilan']);
        $this->assertLessThanOrEqual(
            \App\Services\Matching\MatchingConfigService::GUNAMILAN_WEIGHT,
            $withData['field_points']['gunamilan'],
            'The component may never exceed its configured weight.'
        );
    }

    public function test_an_unknown_mangal_never_rejects_and_never_shaves_the_score(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable'] && $r['total_points'] >= GunamilanService::COMPATIBLE_THRESHOLD);

        // Both sides `don_t_know` — an explicit, frequently chosen dropdown option, not a defect.
        $unknownMangal = (int) DB::table('master_mangal_dosh_types')->where('key', 'don_t_know')->value('id');
        [$seeker, $candidate] = $this->pairWithHoroscopes($combo, mangalDoshTypeId: $unknownMangal);
        $this->requireGunamilan($seeker);

        $verdict = GunamilanPairEvaluator::verdictFor($seeker, $candidate);
        $this->assertSame(MangalCompatibility::STATUS_NOT_COMPUTABLE, $verdict['mangal']['status']);
        $this->assertNull($verdict['mangal']['is_compatible']);

        $this->assertSame(
            ProfilePreferenceMatchService::STATUS_MATCH,
            $this->gunamilanRow($candidate, $seeker)['status'],
            'An unknown Mangal must not turn a compatible 36-guna score into a rejection.'
        );
        $this->assertTrue(app(MatchingService::class)->isEligiblePair($seeker, $candidate));

        // With Mangal dropped the remaining term is renormalised to 1.0, so the points are exactly
        // `weight * total/36` — an unknown Mangal must not silently cost 5% of the component.
        $breakdown = app(MatchingService::class)->computeMatchBreakdown($seeker, $candidate, false);
        $expected = (int) round(
            \App\Services\Matching\MatchingConfigService::GUNAMILAN_WEIGHT
            * ((float) $verdict['total_points'] / (float) $verdict['max_points'])
        );
        $this->assertSame($expected, $breakdown['field_points']['gunamilan']);
    }

    public function test_exactly_eighteen_of_thirty_six_counts_as_compatible(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable'] && $r['total_points'] === 18.0);

        [$seeker, $candidate] = $this->pairWithHoroscopes($combo);
        $this->requireGunamilan($seeker);

        $verdict = GunamilanPairEvaluator::verdictFor($seeker, $candidate);
        $this->assertSame(18.0, $verdict['total_points']);
        $this->assertTrue($verdict['is_compatible'], 'The threshold is INCLUSIVE — exactly 18.0 passes.');

        $this->assertSame(ProfilePreferenceMatchService::STATUS_MATCH, $this->gunamilanRow($candidate, $seeker)['status']);
        $this->assertTrue(app(MatchingService::class)->isEligiblePair($seeker, $candidate));
    }

    // -------------------------------------------------------------------------------------------
    // Suchak payload
    // -------------------------------------------------------------------------------------------

    public function test_the_suchak_payload_carries_the_full_gunamilan_breakdown(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable'] && $r['total_points'] >= GunamilanService::COMPATIBLE_THRESHOLD);
        [$seeker, $candidate] = $this->pairWithHoroscopes($combo);
        $this->requireGunamilan($seeker);

        $fit = app(SuchakMatchFitService::class)->fit($seeker, $candidate);
        $this->assertNotNull($fit);

        // Existing keys are untouched — two Flutter apps consume this shape.
        foreach (['reasons', 'warnings', 'fit_label', 'fit_summary', 'reason', 'match_score', 'match_base_score', 'match_field_points'] as $key) {
            $this->assertArrayHasKey($key, $fit);
        }

        $g = $fit['gunamilan'];
        $this->assertSame('compatible', $g['state']);
        $this->assertTrue($g['computable']);
        $this->assertTrue($g['is_compatible']);
        $this->assertTrue($g['required_by_seeker']);
        $this->assertSame(36.0, $g['max_points']);
        $this->assertSame(18.0, $g['threshold']);
        $this->assertSame(__('matching.gunamilan_verdict_compatible'), $g['verdict_label']);

        // Latin digits only, "26/36" shape.
        $this->assertMatchesRegularExpression('/^[0-9]+(\.[0-9])?\/36$/', (string) $g['points_label']);

        // All eight kootas, each explainable on its own.
        $this->assertCount(8, $g['sections']);
        $this->assertSame(
            ['varna', 'vashya', 'tara', 'yoni', 'graha_maitri', 'gana', 'bhakoot', 'nadi'],
            array_column($g['sections'], 'key')
        );
        foreach ($g['sections'] as $section) {
            foreach (['label', 'points', 'max_points', 'bride_value', 'groom_value', 'note', 'is_dosha'] as $key) {
                $this->assertArrayHasKey($key, $section);
            }
        }

        $this->assertArrayHasKey('nadi_dosha', $g);
        $this->assertArrayHasKey('bhakoot_dosha', $g);
        $this->assertArrayHasKey('status', $g['mangal']);
        $this->assertArrayHasKey('state', $g['mangal']);
        $this->assertArrayHasKey('verdict_label', $g['mangal']);
        $this->assertArrayHasKey('gunamilan', $fit['match_field_points']);
    }

    public function test_the_suchak_payload_reports_missing_data_as_unknown_not_as_a_review_note(): void
    {
        $combo = $this->comboWhere(fn (array $r): bool => $r['computable']);
        [$seeker, $candidate] = $this->pairWithHoroscopes($combo, withCandidateHoroscope: false);
        $this->requireGunamilan($seeker);

        $fit = app(SuchakMatchFitService::class)->fit($seeker, $candidate);
        $this->assertNotNull($fit);

        $this->assertSame('unknown', $fit['gunamilan']['state']);
        $this->assertFalse($fit['gunamilan']['computable']);
        $this->assertNull($fit['gunamilan']['is_compatible'], 'Unknown must be null, never false.');
        $this->assertSame(__('matching.gunamilan_verdict_unknown'), $fit['gunamilan']['verdict_label']);

        // A Suchak must never see missing data phrased as a failed check.
        $this->assertNotContains(__('matching.gunamilan_review_note', ['points' => '']), $fit['warnings']);
        foreach ($fit['warnings'] as $warning) {
            $this->assertStringNotContainsString(__('matching.field_gunamilan'), $warning);
        }
    }

    public function test_the_marathi_wording_separates_not_matched_from_no_data(): void
    {
        $this->app->setLocale('mr');

        $notMatched = __('preference_match.reason_gunamilan_not_matched', ['points' => '14', 'max' => '36', 'min' => '18']);
        $unknown = __('preference_match.reason_gunamilan_unknown');

        $this->assertStringContainsString('जुळत नाही', $notMatched);
        $this->assertStringContainsString('14/36', $notMatched);
        $this->assertStringContainsString('पत्रिकेची माहिती', $unknown);
        $this->assertStringNotContainsString('जुळत नाही', $unknown, 'Missing data must never be worded as a mismatch.');
        $this->assertSame('गुणमिलन जुळते', __('matching.gunamilan_verdict_compatible'));
        $this->assertSame('गुणमिलन जुळत नाही', __('matching.gunamilan_verdict_not_compatible'));
        $this->assertSame('पत्रिका माहिती उपलब्ध नाही', __('matching.gunamilan_verdict_unknown'));

        // Latin digits only, everywhere, in every language (frozen rule).
        $this->assertDoesNotMatchRegularExpression('/[०-९]/u', $notMatched.$unknown.__('matching.gunamilan_review_note', ['points' => '14/36']));
    }

    // -------------------------------------------------------------------------------------------
    // Fixture
    // -------------------------------------------------------------------------------------------

    /**
     * @param  array{bride: array<string, mixed>, groom: array<string, mixed>}  $combo
     * @return array{0: MatrimonyProfile, 1: MatrimonyProfile}  [seeker (male/groom), candidate (female/bride)]
     */
    private function pairWithHoroscopes(
        array $combo,
        bool $withSeekerHoroscope = true,
        bool $withCandidateHoroscope = true,
        ?int $mangalDoshTypeId = null,
    ): array {
        $seeker = $this->profile([
            'gender_id' => $this->maleGenderId,
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'full_name' => 'Gunamilan Seeker '.uniqid(),
        ]);
        $candidate = $this->profile([
            'gender_id' => $this->femaleGenderId,
            'date_of_birth' => now()->subYears(26)->toDateString(),
            'full_name' => 'Gunamilan Candidate '.uniqid(),
        ]);

        if ($withSeekerHoroscope) {
            ProfileHoroscopeData::create(array_merge(
                ['profile_id' => $seeker->id, 'mangal_dosh_type_id' => $mangalDoshTypeId],
                $combo['groom'],
            ));
        }
        if ($withCandidateHoroscope) {
            ProfileHoroscopeData::create(array_merge(
                ['profile_id' => $candidate->id, 'mangal_dosh_type_id' => $mangalDoshTypeId],
                $combo['bride'],
            ));
        }

        ProfilePreferenceMatchService::flushRuntimeCaches();

        return [$seeker->fresh(), $candidate->fresh()];
    }

    private function requireGunamilan(MatrimonyProfile $seeker): void
    {
        DB::table('profile_preference_criteria')->updateOrInsert(
            ['profile_id' => $seeker->id],
            ['gunamilan_required' => true, 'updated_at' => now(), 'created_at' => now()],
        );

        $seeker->unsetRelation('preferenceCriteria');
        ProfilePreferenceMatchService::flushRuntimeCaches();
    }

    /**
     * The `gunamilan` row of the build that measures the CANDIDATE against the SEEKER's preferences.
     *
     * @return array<string, mixed>
     */
    private function gunamilanRow(MatrimonyProfile $candidate, MatrimonyProfile $seeker): array
    {
        return $this->rowById(ProfilePreferenceMatchService::build($candidate, $seeker), 'gunamilan');
    }

    /**
     * @param  array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function rowById(array $build, string $id): array
    {
        foreach ($build['rows'] as $row) {
            if (($row['id'] ?? '') === $id) {
                return $row;
            }
        }

        $this->fail("Preference row [$id] is missing from the build.");
    }

    /**
     * First real rashi/nakshatra combination whose verdict satisfies `$accept`.
     *
     * Pure array maths once the master snapshot is warm, and it stops at the first hit.
     *
     * @param  callable(array<string, mixed>): bool  $accept
     * @return array{bride: array<string, mixed>, groom: array<string, mixed>}
     */
    private function comboWhere(callable $accept): array
    {
        $gunamilan = app(GunamilanService::class);
        $rashiIds = DB::table('master_rashis')->where('key', '!=', 'other')->pluck('id')->all();
        $nakshatraIds = DB::table('master_nakshatras')->whereNotNull('nakshatra_number')->pluck('id')->all();

        foreach ($nakshatraIds as $brideNakshatra) {
            foreach ($nakshatraIds as $groomNakshatra) {
                foreach ($rashiIds as $brideRashi) {
                    foreach ($rashiIds as $groomRashi) {
                        $bride = ['nakshatra_id' => $brideNakshatra, 'rashi_id' => $brideRashi];
                        $groom = ['nakshatra_id' => $groomNakshatra, 'rashi_id' => $groomRashi];

                        $result = $gunamilan->compare(
                            $gunamilan->kootaKeyForHoroscope(new ProfileHoroscopeData($bride), 'female'),
                            $gunamilan->kootaKeyForHoroscope(new ProfileHoroscopeData($groom), 'male'),
                        );

                        if ($accept($result)) {
                            return ['bride' => $bride, 'groom' => $groom];
                        }
                    }
                }
            }
        }

        $this->fail('No rashi/nakshatra combination satisfied the requested condition.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function profile(array $attributes): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
            'is_showcase' => false,
            'location_id' => $this->village->id,
        ], $attributes));

        $profile->lifecycle_state = 'active';
        $profile->save();

        return $profile->refresh();
    }

    private function makeGeography(): void
    {
        $country = Location::query()->create(['name' => 'India', 'slug' => 'india-g', 'hierarchy' => 'country', 'level' => 0, 'is_active' => true]);
        $state = Location::query()->create(['name' => 'Maharashtra', 'slug' => 'maharashtra-g', 'hierarchy' => 'state', 'level' => 1, 'parent_id' => $country->id, 'is_active' => true]);
        $district = Location::query()->create(['name' => 'Pune', 'slug' => 'pune-g', 'hierarchy' => 'district', 'level' => 2, 'parent_id' => $state->id, 'is_active' => true, 'lat' => 18.5204, 'lng' => 73.8567]);
        $taluka = Location::query()->create(['name' => 'Haveli', 'slug' => 'haveli-g', 'hierarchy' => 'taluka', 'level' => 3, 'parent_id' => $district->id, 'is_active' => true, 'lat' => 18.4529, 'lng' => 73.8600]);
        $this->village = Location::query()->create(['name' => 'Wagholi', 'slug' => 'wagholi-g', 'hierarchy' => 'village', 'level' => 4, 'parent_id' => $taluka->id, 'is_active' => true, 'lat' => 18.5800, 'lng' => 73.9800]);
    }
}
