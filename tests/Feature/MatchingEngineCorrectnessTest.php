<?php

namespace Tests\Feature;

use App\Models\Caste;
use App\Models\Location;
use App\Models\MasterGender;
use App\Models\MatchingHardFilter;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\User;
use App\Services\Matching\MatchingService;
use App\Services\Matching\MatchRelaxationLadder;
use App\Services\ProfilePreferenceMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression cover for the matching-engine correctness audit (2026-07-26) and the per-seeker
 * community lock + tiered relaxation that shipped with it. One test per defect.
 */
class MatchingEngineCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private int $maleGenderId;

    private int $femaleGenderId;

    /** @var array<string, Location> */
    private array $geo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->maleGenderId = (int) MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true]
        )->id;
        $this->femaleGenderId = (int) MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true]
        )->id;

        // A non-draft profile must carry a valid residence leaf (MatrimonyProfileObserver), so every
        // fixture gets one. Tests that care about geography override it explicitly.
        $this->geo = $this->makeGeography();

        ProfilePreferenceMatchService::flushRuntimeCaches();
    }

    // -------------------------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------------------------

    /**
     * `matrimony_profiles.location_id` no longer exists — residence lives in `profile_addresses` and is
     * only flushed on `saved`, while MatrimonyProfileObserver validates it on `saving`. So the row is
     * created as a draft (residence written), then promoted to active.
     */
    private function profile(array $attributes = []): MatrimonyProfile
    {
        $lifecycle = $attributes['lifecycle_state'] ?? 'active';
        unset($attributes['lifecycle_state']);

        $profile = MatrimonyProfile::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
            'is_showcase' => false,
            'location_id' => $this->geo['villageA']->id,
        ], $attributes));

        $profile->lifecycle_state = $lifecycle;
        $profile->save();

        return $profile->refresh();
    }

    private function seeker(array $attributes = []): MatrimonyProfile
    {
        return $this->profile(array_merge([
            'gender_id' => $this->maleGenderId,
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'full_name' => 'Seeker',
        ], $attributes));
    }

    private function candidate(string $name, array $attributes = []): MatrimonyProfile
    {
        return $this->profile(array_merge([
            'gender_id' => $this->femaleGenderId,
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'full_name' => $name,
        ], $attributes));
    }

    private function setHardFilter(string $key, string $mode): void
    {
        MatchingHardFilter::query()->updateOrCreate(
            ['filter_key' => $key],
            ['mode' => $mode, 'preferred_penalty_points' => 12],
        );
    }

    private function criteria(MatrimonyProfile $profile, array $values): void
    {
        DB::table('profile_preference_criteria')->updateOrInsert(
            ['profile_id' => $profile->id],
            array_merge($values, ['created_at' => now(), 'updated_at' => now()]),
        );
        $profile->unsetRelation('preferenceCriteria');
    }

    private function pivot(string $table, int $profileId, string $column, array $ids): void
    {
        foreach ($ids as $id) {
            DB::table($table)->insert([
                'profile_id' => $profileId,
                $column => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return list<string> */
    private function matchedNames(MatrimonyProfile $seeker, int $limit = 24): array
    {
        return app(MatchingService::class)
            ->findMatches($seeker, $limit)
            ->map(fn (array $row) => (string) $row['profile']->full_name)
            ->all();
    }

    private function makeGeography(): array
    {
        $country = Location::query()->create([
            'name' => 'India', 'slug' => 'india', 'hierarchy' => 'country', 'level' => 0, 'is_active' => true,
        ]);
        $state = Location::query()->create([
            'name' => 'Maharashtra', 'slug' => 'maharashtra', 'hierarchy' => 'state', 'level' => 1,
            'parent_id' => $country->id, 'is_active' => true,
        ]);
        $districtA = Location::query()->create([
            'name' => 'Pune', 'slug' => 'pune', 'hierarchy' => 'district', 'level' => 2,
            'parent_id' => $state->id, 'is_active' => true, 'lat' => 18.5204, 'lng' => 73.8567,
        ]);
        $districtB = Location::query()->create([
            'name' => 'Satara', 'slug' => 'satara', 'hierarchy' => 'district', 'level' => 2,
            'parent_id' => $state->id, 'is_active' => true, 'lat' => 17.6805, 'lng' => 74.0183,
        ]);
        $talukaA = Location::query()->create([
            'name' => 'Haveli', 'slug' => 'haveli', 'hierarchy' => 'taluka', 'level' => 3,
            'parent_id' => $districtA->id, 'is_active' => true, 'lat' => 18.4529, 'lng' => 73.8600,
        ]);
        $talukaB = Location::query()->create([
            'name' => 'Baramati', 'slug' => 'baramati', 'hierarchy' => 'taluka', 'level' => 3,
            'parent_id' => $districtA->id, 'is_active' => true, 'lat' => 18.1514, 'lng' => 74.5815,
        ]);
        $villageA = Location::query()->create([
            'name' => 'Wagholi', 'slug' => 'wagholi', 'hierarchy' => 'village', 'level' => 4,
            'parent_id' => $talukaA->id, 'is_active' => true, 'lat' => 18.5800, 'lng' => 73.9800,
        ]);
        $villageB = Location::query()->create([
            'name' => 'Malegaon', 'slug' => 'malegaon-bk', 'hierarchy' => 'village', 'level' => 4,
            'parent_id' => $talukaB->id, 'is_active' => true, 'lat' => 18.1600, 'lng' => 74.5900,
        ]);

        return compact('country', 'state', 'districtA', 'districtB', 'talukaA', 'talukaB', 'villageA', 'villageB');
    }

    // -------------------------------------------------------------------------------------------
    // #1 — hard filters were dead for seekers with no preference row (brace-scoping bug)
    // -------------------------------------------------------------------------------------------

    public function test_hard_filters_still_apply_to_a_seeker_with_no_preference_criteria_row(): void
    {
        $this->setHardFilter('religion', MatchingHardFilter::MODE_STRICT);

        $hindu = Religion::query()->create(['key' => 'hindu', 'label' => 'Hindu', 'is_active' => true]);
        $jain = Religion::query()->create(['key' => 'jain', 'label' => 'Jain', 'is_active' => true]);

        $seeker = $this->seeker(['religion_id' => $hindu->id]);
        // Deliberately NO profile_preference_criteria row — this is the sparse profile the bug hit.
        $this->assertNull($seeker->fresh()->preferenceCriteria);

        $this->pivot('profile_preferred_religions', (int) $seeker->id, 'religion_id', [(int) $hindu->id]);

        $this->candidate('Same Religion', ['religion_id' => $hindu->id]);
        $this->candidate('Other Religion', ['religion_id' => $jain->id]);

        $names = $this->matchedNames($seeker);

        $this->assertContains('Same Religion', $names);
        $this->assertNotContains('Other Religion', $names, 'Strict religion filter must apply even without a preference-criteria row.');
    }

    // -------------------------------------------------------------------------------------------
    // #2 — age filter was wrong four ways
    // -------------------------------------------------------------------------------------------

    public function test_upper_age_bound_is_inclusive_so_a_thirty_year_old_appears_for_a_25_to_30_preference(): void
    {
        $seeker = $this->seeker();
        $this->criteria($seeker, ['preferred_age_min' => 25, 'preferred_age_max' => 30]);

        // Exactly 30 today: the old `dob >= now()->subYears(30)` excluded this whole cohort.
        $this->candidate('Exactly Thirty', ['date_of_birth' => now()->subYears(30)->toDateString()]);
        $this->candidate('Just Turned Thirty', ['date_of_birth' => now()->subYears(30)->addDays(200)->toDateString()]);
        $this->candidate('Too Old', ['date_of_birth' => now()->subYears(36)->toDateString()]);

        $names = $this->matchedNames($seeker);

        $this->assertContains('Exactly Thirty', $names);
        $this->assertContains('Just Turned Thirty', $names);
        $this->assertNotContains('Too Old', $names);
    }

    public function test_a_minimum_only_age_preference_filters_on_its_own(): void
    {
        $seeker = $this->seeker();
        // Max deliberately null: the old code required BOTH bounds and ignored this entirely.
        $this->criteria($seeker, ['preferred_age_min' => 28, 'preferred_age_max' => null]);

        $this->candidate('Old Enough', ['date_of_birth' => now()->subYears(31)->toDateString()]);
        $this->candidate('Too Young', ['date_of_birth' => now()->subYears(22)->toDateString()]);

        $names = $this->matchedNames($seeker);

        $this->assertContains('Old Enough', $names);
        $this->assertNotContains('Too Young', $names, 'A min-only age preference must filter.');
    }

    public function test_a_dob_less_candidate_survives_sql_and_is_graded_unknown(): void
    {
        $seeker = $this->seeker();
        $this->criteria($seeker, ['preferred_age_min' => 25, 'preferred_age_max' => 32]);

        $this->candidate('No Birth Date', ['date_of_birth' => null]);

        $this->assertContains('No Birth Date', $this->matchedNames($seeker));
    }

    public function test_an_under_age_profile_is_never_returned_at_any_tier(): void
    {
        // Female candidates: legal minimum 18. Seeker states no age preference at all, so nothing but
        // the unconditional MarriageAgePolicy ceiling can exclude the under-age row.
        $seeker = $this->seeker();

        $this->candidate('Under Age', ['date_of_birth' => now()->subYears(16)->toDateString()]);
        $this->candidate('Legal Adult', ['date_of_birth' => now()->subYears(24)->toDateString()]);

        $names = $this->matchedNames($seeker);

        $this->assertNotContains('Under Age', $names, 'MarriageAgePolicy floor must never be relaxed.');
        $this->assertContains('Legal Adult', $names);
    }

    public function test_male_candidates_below_twenty_one_are_excluded_for_a_female_seeker(): void
    {
        $seeker = $this->seeker([
            'gender_id' => $this->femaleGenderId,
            'date_of_birth' => now()->subYears(24)->toDateString(),
        ]);

        $this->profile([
            'gender_id' => $this->maleGenderId,
            'date_of_birth' => now()->subYears(19)->toDateString(),
            'full_name' => 'Nineteen Male',
        ]);
        $this->profile([
            'gender_id' => $this->maleGenderId,
            'date_of_birth' => now()->subYears(26)->toDateString(),
            'full_name' => 'Adult Male',
        ]);

        $names = $this->matchedNames($seeker);

        $this->assertNotContains('Nineteen Male', $names, 'Male legal minimum is 21, not 18.');
        $this->assertContains('Adult Male', $names);
    }

    public function test_suggested_partner_age_floor_uses_the_candidate_gender_not_a_flat_eighteen(): void
    {
        $female = $this->profile([
            'gender_id' => $this->femaleGenderId,
            'date_of_birth' => now()->subYears(18)->toDateString(),
        ]);
        $range = \App\Services\PartnerPreferenceSuggestionService::defaultPreferredAgeRange($female);

        // Her partner is male, so the floor is 21 — never 18.
        $this->assertNotNull($range);
        $this->assertSame(21, $range['min']);
    }

    // -------------------------------------------------------------------------------------------
    // #3 — a taluka-only location preference returned an EMPTY feed
    // -------------------------------------------------------------------------------------------

    public function test_a_taluka_only_location_preference_returns_results(): void
    {
        $geo = $this->geo;

        $seeker = $this->seeker(['location_id' => $geo['villageA']->id]);
        $this->pivot('profile_preferred_talukas', (int) $seeker->id, 'taluka_id', [(int) $geo['talukaA']->id]);

        $inTaluka = $this->candidate('Same Taluka', ['location_id' => $geo['villageA']->id]);
        $this->pivot('profile_preferred_talukas', (int) $inTaluka->id, 'taluka_id', [(int) $geo['talukaA']->id]);

        $names = $this->matchedNames($seeker);

        $this->assertNotEmpty($names, 'A taluka-only preference used to fall through to not_matched and empty the feed.');
        $this->assertContains('Same Taluka', $names);
    }

    public function test_a_taluka_preference_accepts_a_same_district_candidate_as_flexible(): void
    {
        $geo = $this->geo;

        $seeker = $this->seeker(['location_id' => $geo['villageA']->id]);
        $this->pivot('profile_preferred_talukas', (int) $seeker->id, 'taluka_id', [(int) $geo['talukaA']->id]);

        // Different taluka, same district (Pune).
        $this->candidate('Neighbouring Taluka', ['location_id' => $geo['villageB']->id]);

        $build = ProfilePreferenceMatchService::build(
            MatrimonyProfile::query()->where('full_name', 'Neighbouring Taluka')->firstOrFail(),
            $seeker->fresh(),
        );

        $location = collect($build['rows'])->firstWhere('id', 'location');
        $this->assertNotNull($location);
        $this->assertNotSame(
            ProfilePreferenceMatchService::STATUS_NOT_MATCHED,
            $location['status'],
            'District proximity must be graded flexible, not not_matched.'
        );
    }

    // -------------------------------------------------------------------------------------------
    // #4 — income and height acted as silent HARD filters
    // -------------------------------------------------------------------------------------------

    public function test_an_income_near_miss_is_admitted_with_a_warning_instead_of_vanishing(): void
    {
        $seeker = $this->seeker();
        $this->criteria($seeker, ['preferred_income_min' => 1000000]);

        // Half the requested minimum: comfortably a not_matched row, previously a symmetric deletion.
        $this->candidate('Lower Income', ['annual_income' => 500000]);

        $rows = app(MatchingService::class)->findMatches($seeker->fresh());
        $row = $rows->first(fn (array $r) => $r['profile']->full_name === 'Lower Income');

        $this->assertNotNull($row, 'A candidate below the income floor must not be deleted from the feed.');
        $this->assertNotEmpty($row['warnings'], 'The tolerated income mismatch must surface as a warning.');
    }

    public function test_a_height_near_miss_is_admitted_with_a_warning(): void
    {
        $seeker = $this->seeker(['height_cm' => 175]);
        $this->criteria($seeker, ['preferred_height_min_cm' => 160, 'preferred_height_max_cm' => 170]);

        $this->candidate('Slightly Short', ['height_cm' => 150]);

        $rows = app(MatchingService::class)->findMatches($seeker->fresh());
        $row = $rows->first(fn (array $r) => $r['profile']->full_name === 'Slightly Short');

        $this->assertNotNull($row, 'A 10cm height miss must not delete the candidate.');
        $this->assertNotEmpty($row['warnings']);
    }

    public function test_income_still_excludes_when_the_seeker_declared_it_must_match(): void
    {
        $seeker = $this->seeker();
        $this->criteria($seeker, ['preferred_income_min' => 1000000]);

        DB::table('partner_preference_metadata')->insert([
            'matrimony_profile_id' => $seeker->id,
            'strictness_json' => json_encode(['income' => 'required']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->candidate('Far Below Income', ['annual_income' => 200000]);
        // A second, compliant candidate keeps the relaxation ladder from widening past tier 0.
        for ($i = 0; $i < MatchRelaxationLadder::floor() + 2; $i++) {
            $this->candidate('Rich Candidate '.$i, ['annual_income' => 2000000]);
        }

        $names = $this->matchedNames($seeker->fresh(), 50);

        $this->assertNotContains('Far Below Income', $names, 'An explicitly declared must-match income still excludes at the strict tier.');
    }

    // -------------------------------------------------------------------------------------------
    // #5 — intercaste refusal must lock the community, per seeker
    // -------------------------------------------------------------------------------------------

    public function test_an_explicitly_intercaste_refusing_seeker_only_sees_same_caste_and_religion(): void
    {
        // A same-caste match exists, so the ladder is satisfied at tier 0 and the lock holds.
        // (Tier 3 deliberately relaxes caste when the floor is otherwise unreachable — covered below.)
        config(['matching.relaxation.floor' => 1]);

        $hindu = Religion::query()->create(['key' => 'hindu', 'label' => 'Hindu', 'is_active' => true]);
        $jain = Religion::query()->create(['key' => 'jain', 'label' => 'Jain', 'is_active' => true]);
        $maratha = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'maratha', 'label' => 'Maratha', 'is_active' => true]);
        $brahmin = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'brahmin', 'label' => 'Brahmin', 'is_active' => true]);

        $seeker = $this->seeker(['religion_id' => $hindu->id, 'caste_id' => $maratha->id]);

        // The seeker was asked and said no.
        DB::table('profile_partner_community_flags')->insert([
            'profile_id' => $seeker->id,
            'interested_in_intercaste' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->candidate('Same Community', ['religion_id' => $hindu->id, 'caste_id' => $maratha->id]);
        $this->candidate('Other Caste', ['religion_id' => $hindu->id, 'caste_id' => $brahmin->id]);
        $this->candidate('Other Religion', ['religion_id' => $jain->id, 'caste_id' => $brahmin->id]);

        $names = $this->matchedNames($seeker->fresh(), 50);

        $this->assertContains('Same Community', $names);
        $this->assertNotContains('Other Caste', $names, 'An explicit intercaste refusal must lock caste.');
        $this->assertNotContains('Other Religion', $names, 'A caste lock implies its religion.');
    }

    public function test_a_seeker_with_no_community_flag_row_is_not_caste_locked(): void
    {
        $hindu = Religion::query()->create(['key' => 'hindu', 'label' => 'Hindu', 'is_active' => true]);
        $maratha = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'maratha', 'label' => 'Maratha', 'is_active' => true]);
        $brahmin = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'brahmin', 'label' => 'Brahmin', 'is_active' => true]);

        $seeker = $this->seeker(['religion_id' => $hindu->id, 'caste_id' => $maratha->id]);
        $this->assertDatabaseMissing('profile_partner_community_flags', ['profile_id' => $seeker->id]);

        $this->candidate('Other Caste', ['religion_id' => $hindu->id, 'caste_id' => $brahmin->id]);

        $names = $this->matchedNames($seeker->fresh(), 50);

        $this->assertContains('Other Caste', $names, 'An absent flag row is silence, never a refusal — the base must not be caste-locked.');
    }

    public function test_the_auto_seeded_own_caste_pivot_at_preferred_strictness_does_not_lock(): void
    {
        $hindu = Religion::query()->create(['key' => 'hindu', 'label' => 'Hindu', 'is_active' => true]);
        $maratha = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'maratha', 'label' => 'Maratha', 'is_active' => true]);
        $brahmin = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'brahmin', 'label' => 'Brahmin', 'is_active' => true]);

        $seeker = $this->seeker(['religion_id' => $hindu->id, 'caste_id' => $maratha->id]);

        // Exactly what registration auto-seeds: own caste in the pivot, strictness "preferred".
        $this->pivot('profile_preferred_castes', (int) $seeker->id, 'caste_id', [(int) $maratha->id]);
        DB::table('partner_preference_metadata')->insert([
            'matrimony_profile_id' => $seeker->id,
            'strictness_json' => json_encode(['caste' => 'preferred', 'religion' => 'preferred']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->candidate('Other Caste', ['religion_id' => $hindu->id, 'caste_id' => $brahmin->id]);

        $names = $this->matchedNames($seeker->fresh(), 50);

        $this->assertContains('Other Caste', $names, 'The auto-seeded pivot must not caste-lock the entire base.');
    }

    public function test_declared_caste_strictness_locks_the_seeker(): void
    {
        config(['matching.relaxation.floor' => 1]);

        $hindu = Religion::query()->create(['key' => 'hindu', 'label' => 'Hindu', 'is_active' => true]);
        $maratha = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'maratha', 'label' => 'Maratha', 'is_active' => true]);
        $brahmin = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'brahmin', 'label' => 'Brahmin', 'is_active' => true]);

        $seeker = $this->seeker(['religion_id' => $hindu->id, 'caste_id' => $maratha->id]);
        DB::table('partner_preference_metadata')->insert([
            'matrimony_profile_id' => $seeker->id,
            'strictness_json' => json_encode(['same_caste_expected' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->candidate('Same Caste', ['religion_id' => $hindu->id, 'caste_id' => $maratha->id]);
        $this->candidate('Other Caste', ['religion_id' => $hindu->id, 'caste_id' => $brahmin->id]);

        $names = $this->matchedNames($seeker->fresh(), 50);

        $this->assertContains('Same Caste', $names);
        $this->assertNotContains('Other Caste', $names);
    }

    // -------------------------------------------------------------------------------------------
    // #6 — auto-derived preferences when the seeker stated nothing
    // -------------------------------------------------------------------------------------------

    public function test_an_empty_age_preference_is_derived_from_the_seekers_own_profile_and_marked_assumed(): void
    {
        $seeker = $this->seeker(['date_of_birth' => now()->subYears(30)->toDateString()]);
        // No preference criteria at all.

        // Male seeker aged 30 → assumed 25..31.
        $wellInside = $this->candidate('Assumed Fit', ['date_of_birth' => now()->subYears(27)->toDateString()]);

        $build = ProfilePreferenceMatchService::build($wellInside->fresh(), $seeker->fresh());
        $age = collect($build['rows'])->firstWhere('id', 'age');

        $this->assertNotNull($age);
        $this->assertTrue($age['derived'], 'An unstated age preference must be flagged as assumed.');
        $this->assertSame(ProfilePreferenceMatchService::STATUS_MATCH, $age['status']);
        $this->assertContains('age', $build['assumed_fields']);
    }

    public function test_a_derived_preference_never_excludes_a_candidate(): void
    {
        $seeker = $this->seeker(['date_of_birth' => now()->subYears(30)->toDateString()]);

        // Far outside the assumed 25..31 band, but assumptions may never exclude.
        $this->candidate('Far Outside Assumption', ['date_of_birth' => now()->subYears(45)->toDateString()]);

        $this->assertContains('Far Outside Assumption', $this->matchedNames($seeker->fresh(), 50));
    }

    public function test_an_explicit_preference_is_never_overwritten_by_a_derived_one(): void
    {
        $seeker = $this->seeker(['date_of_birth' => now()->subYears(30)->toDateString()]);
        $this->criteria($seeker, ['preferred_age_min' => 34, 'preferred_age_max' => 40]);

        $older = $this->candidate('Older Partner', ['date_of_birth' => now()->subYears(36)->toDateString()]);

        $build = ProfilePreferenceMatchService::build($older->fresh(), $seeker->fresh());
        $age = collect($build['rows'])->firstWhere('id', 'age');

        $this->assertFalse($age['derived'], 'A stated preference must win over the derived ladder.');
        $this->assertSame(ProfilePreferenceMatchService::STATUS_MATCH, $age['status']);
    }

    // -------------------------------------------------------------------------------------------
    // #7 — tiered relaxation
    // -------------------------------------------------------------------------------------------

    public function test_a_run_that_meets_the_floor_at_tier_zero_reports_no_relaxation(): void
    {
        config(['matching.relaxation.floor' => 2]);

        $seeker = $this->seeker();
        $this->candidate('First');
        $this->candidate('Second');
        $this->candidate('Third');

        $service = app(MatchingService::class);
        $rows = $service->findMatches($seeker->fresh(), 24);

        $summary = $service->lastRelaxationSummary();

        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertSame(MatchRelaxationLadder::TIER_STRICT, $summary['tier']);
        $this->assertSame([], $summary['relaxed_fields']);
        $this->assertTrue($summary['floor_reached']);
        $this->assertSame(MatchRelaxationLadder::TIER_STRICT, $rows->first()['tier']);
    }

    public function test_the_ladder_climbs_in_order_and_reports_the_tier_and_relaxed_fields(): void
    {
        config(['matching.relaxation.floor' => 12]);

        $hindu = Religion::query()->create(['key' => 'hindu', 'label' => 'Hindu', 'is_active' => true]);
        $maratha = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'maratha', 'label' => 'Maratha', 'is_active' => true]);
        $brahmin = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'brahmin', 'label' => 'Brahmin', 'is_active' => true]);

        $seeker = $this->seeker(['religion_id' => $hindu->id, 'caste_id' => $maratha->id]);
        DB::table('profile_partner_community_flags')->insert([
            'profile_id' => $seeker->id,
            'interested_in_intercaste' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Only one same-caste candidate exists, so the floor of 12 can never be met at tier 0.
        $this->candidate('Same Caste', ['religion_id' => $hindu->id, 'caste_id' => $maratha->id]);
        for ($i = 0; $i < 5; $i++) {
            $this->candidate('Other Caste '.$i, ['religion_id' => $hindu->id, 'caste_id' => $brahmin->id]);
        }

        $service = app(MatchingService::class);
        $rows = $service->findMatches($seeker->fresh(), 50);
        $summary = $service->lastRelaxationSummary();

        $this->assertSame(MatchRelaxationLadder::TIER_RELAXED_CASTE, $summary['tier'], 'The ladder must climb to the caste tier when the floor is unreachable below it.');
        $this->assertContains('caste', $summary['relaxed_fields']);
        $this->assertContains('income', $summary['relaxed_fields'], 'Tiers are cumulative.');
        $this->assertContains('height', $summary['relaxed_fields']);
        $this->assertContains('location', $summary['relaxed_fields']);
        $this->assertFalse($summary['floor_reached'], 'Only 6 candidates exist, so the floor of 12 stays unmet.');

        $names = $rows->map(fn (array $r) => (string) $r['profile']->full_name)->all();
        $this->assertContains('Same Caste', $names);
        $this->assertContains('Other Caste 0', $names, 'Caste relaxes at the top tier.');

        // Rows carry the tier they were ADMITTED at, not the tier the run ended on.
        $strictRow = $rows->first(fn (array $r) => $r['profile']->full_name === 'Same Caste');
        $relaxedRow = $rows->first(fn (array $r) => $r['profile']->full_name === 'Other Caste 0');
        $this->assertSame(MatchRelaxationLadder::TIER_STRICT, $strictRow['tier']);
        $this->assertSame(MatchRelaxationLadder::TIER_RELAXED_CASTE, $relaxedRow['tier']);
    }

    public function test_religion_never_relaxes_even_at_the_top_tier(): void
    {
        config(['matching.relaxation.floor' => 12]);

        $hindu = Religion::query()->create(['key' => 'hindu', 'label' => 'Hindu', 'is_active' => true]);
        $jain = Religion::query()->create(['key' => 'jain', 'label' => 'Jain', 'is_active' => true]);
        $maratha = Caste::query()->create(['religion_id' => $hindu->id, 'key' => 'maratha', 'label' => 'Maratha', 'is_active' => true]);
        $jainCaste = Caste::query()->create(['religion_id' => $jain->id, 'key' => 'jain-caste', 'label' => 'Jain Caste', 'is_active' => true]);

        $seeker = $this->seeker(['religion_id' => $hindu->id, 'caste_id' => $maratha->id]);
        DB::table('profile_partner_community_flags')->insert([
            'profile_id' => $seeker->id,
            'interested_in_intercaste' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->candidate('Cross Religion', ['religion_id' => $jain->id, 'caste_id' => $jainCaste->id]);

        $service = app(MatchingService::class);
        $names = $service->findMatches($seeker->fresh(), 50)->map(fn (array $r) => (string) $r['profile']->full_name)->all();

        $this->assertSame(MatchRelaxationLadder::TIER_RELAXED_CASTE, $service->lastRelaxationSummary()['tier']);
        $this->assertNotContains('Cross Religion', $names, 'Religion stays locked at every tier.');
    }

    public function test_the_ladder_never_relaxes_the_legal_minimum_age(): void
    {
        config(['matching.relaxation.floor' => 12]);

        $seeker = $this->seeker();
        $this->candidate('Under Age', ['date_of_birth' => now()->subYears(15)->toDateString()]);
        $this->candidate('Adult', ['date_of_birth' => now()->subYears(25)->toDateString()]);

        $service = app(MatchingService::class);
        $names = $service->findMatches($seeker->fresh(), 50)->map(fn (array $r) => (string) $r['profile']->full_name)->all();

        // Floor unreachable, so the ladder ran to the top — and still refused the under-age row.
        $this->assertSame(MatchRelaxationLadder::TIER_RELAXED_CASTE, $service->lastRelaxationSummary()['tier']);
        $this->assertNotContains('Under Age', $names);
        $this->assertContains('Adult', $names);
    }

    public function test_the_relaxation_floor_is_read_from_config(): void
    {
        config(['matching.relaxation.floor' => 3]);
        $this->assertSame(3, MatchRelaxationLadder::floor());

        config(['matching.relaxation.floor' => 25]);
        $this->assertSame(25, MatchRelaxationLadder::floor());
    }
}
