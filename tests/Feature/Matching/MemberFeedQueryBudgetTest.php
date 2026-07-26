<?php

namespace Tests\Feature\Matching;

use App\Models\Caste;
use App\Models\Location;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\Religion;
use App\Models\User;
use App\Services\Matching\MatchingService;
use App\Services\ProfileCompletionEngine;
use App\Services\ProfilePreferenceMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Performance contract for the MEMBER matching feed (`GET /matches` → {@see MatchingService::findMatchesForTab()}).
 *
 * The member surface is the one that turns the ACTOR layer on
 * ({@see \App\Services\Matching\CandidatePoolStrategy::appliesActorAdjustments()}), so every candidate
 * additionally walks {@see \App\Services\MatchBoostService::applyBoost()} and
 * {@see \App\Services\Matching\MatchingBehaviorScoringService::scoreAdjustment()}. That path was
 * re-deriving, once per candidate, four things that are constant for the whole request:
 *
 *   - the profile-field configuration (mandatory / enabled field lists),
 *   - the whole completion sweep TWICE per profile, because the engine asked for the breakdown after
 *     already having both numbers,
 *   - the free-plan catalog row behind `SubscriptionService::getEffectivePlan()`,
 *   - one `COUNT(*)` per (candidate × behaviour action) instead of one grouped read per seeker.
 *
 * These are budget assertions, not micro-benchmarks. Each ceiling is tied to something that must not
 * scale with the pool: a regression to per-candidate work multiplies the count, it does not nudge it.
 *
 * Deliberately a separate file from {@see MatchingPipelineQueryBudgetTest}, which owns the relaxation
 * ladder / Suchak suggestions contract. This one owns the member feed.
 */
class MemberFeedQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** Big enough that per-candidate repetition is unmistakable, small enough to stay fast. */
    private const POOL_SIZE = 24;

    private int $maleGenderId;

    private int $femaleGenderId;

    private Location $village;

    private Caste $caste;

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

        $this->makeGeography();

        $religion = Religion::query()->create(['key' => 'hindu', 'label' => 'Hindu', 'is_active' => true]);
        $this->caste = Caste::query()->create([
            'religion_id' => $religion->id, 'key' => 'maratha', 'label' => 'Maratha', 'is_active' => true,
        ]);

        // Production always has a free catalog row; without one the plan resolver takes a different
        // fallback branch and this budget would be measuring the wrong thing.
        Plan::query()->firstOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free', 'price' => 0, 'duration_days' => 0,
                'is_active' => true, 'sort_order' => 0, 'highlight' => false,
            ]
        );

        ProfilePreferenceMatchService::flushRuntimeCaches();
    }

    public function test_the_member_feed_does_not_re_derive_request_constants_per_candidate(): void
    {
        $seeker = $this->buildPool();

        $service = app(MatchingService::class);
        $queries = $this->captureQueries(
            static fn () => $service->findMatchesForTab($seeker, MatchingService::TAB_PERFECT, 36)
        );

        $this->assertNotSame([], $queries, 'The feed issued no queries at all — the fixture is wrong.');

        // ---- 1. Profile field configuration -------------------------------------------------------
        // `profile_field_configs` is a ~30-row admin table read by ProfileCompletenessService. It was
        // read twice per profile (mandatory + enabled) and the whole sweep then ran twice, so a
        // 24-candidate pool paid ~100 reads for two lists that cannot change mid-request.
        $fieldConfigReads = $this->countReadsOf($queries, 'profile_field_configs');
        $this->assertLessThanOrEqual(
            4,
            $fieldConfigReads,
            'The profile field-configuration lists must be resolved once per flag for the whole request, '
            .'not once per candidate. Got '.$fieldConfigReads.' reads for a '.self::POOL_SIZE.'-candidate pool.'
        );

        // ---- 2. The completion sweep runs once per profile, not twice ------------------------------
        // ProfileCompletionEngine::computeForProfile() asked ProfileCompletenessService for the core
        // percentage, the detailed percentage, and THEN the breakdown — which is defined as exactly
        // those two calls again. Every section probe therefore fired twice per profile. These three
        // tables are probed exactly once each per detailed-percentage sweep, so they count the sweeps.
        $ceiling = self::POOL_SIZE + 1; // + the seeker's own profile
        foreach (['profile_siblings', 'profile_relatives', 'profile_alliance_networks'] as $table) {
            $reads = $this->countReadsOf($queries, $table);
            $this->assertLessThanOrEqual(
                $ceiling,
                $reads,
                'The completion sweep must run at most once per profile: `'.$table.'` was read '
                .$reads.' times for '.$ceiling.' profiles.'
            );
        }

        // ---- 3. Behaviour scoring is one grouped read per seeker, not one COUNT per candidate ------
        // The seeker's viewer→target history is a property of the SEEKER. It used to cost a
        // `COUNT(*)` per (candidate × active action).
        $behaviourReads = $this->countReadsOf($queries, 'user_match_behaviors');
        $this->assertLessThanOrEqual(
            8,
            $behaviourReads,
            'Behaviour scoring must load the seeker\'s history in one grouped read per active action, '
            .'not a COUNT per candidate. Got '.$behaviourReads.' reads for a '.self::POOL_SIZE.'-candidate pool.'
        );

        // ---- 4. The free-plan catalog row is resolved once per gender, not once per candidate ------
        // `MatchBoostService::tierPoints()` asks for every candidate's effective plan; unpaid members
        // all resolve to the same catalog row, and re-fetching it also re-loaded its `features` and
        // `quota_policies` relations every time.
        $planReads = $this->countReadsOf($queries, 'plans');
        $this->assertLessThanOrEqual(
            4,
            $planReads,
            'The default free plan is catalog data shared by every unpaid candidate and must be resolved '
            .'once per gender key. Got '.$planReads.' `plans` reads for a '.self::POOL_SIZE.'-candidate pool.'
        );

    }

    /**
     * Per-candidate ceiling for the member feed as a whole.
     *
     * Deliberately loose and deliberately separate: the four assertions above are the real contract,
     * this one only catches a broad regression that happens to miss all four tables. Measured on this
     * fixture at the time of writing: 83.9 queries per candidate before the fix (2,013 total), 59.9
     * after (1,437 total) — the ceiling sits just above the measured value so a return of ANY
     * per-candidate re-derivation of a request constant trips it, while ordinary changes do not.
     */
    public function test_the_member_feed_stays_within_its_per_candidate_query_ceiling(): void
    {
        $seeker = $this->buildPool();

        $service = app(MatchingService::class);
        $queries = $this->captureQueries(
            static fn () => $service->findMatchesForTab($seeker, MatchingService::TAB_PERFECT, 36)
        );

        $perCandidate = count($queries) / self::POOL_SIZE;
        $this->assertLessThan(
            65,
            $perCandidate,
            'Member feed query budget blown for a '.self::POOL_SIZE.'-candidate pool: '
            .number_format($perCandidate, 1).' queries per candidate ('.count($queries).' total).'
        );
    }

    /**
     * One completion computation per profile means one sweep, not two.
     *
     * {@see \App\Services\ProfileCompletionEngine} used to ask {@see ProfileCompletenessService} for
     * the core percentage, the detailed percentage, and then the breakdown — which is *defined* as
     * those same two calls. Every probe behind them therefore ran twice for every profile whose
     * completion the matching feed scores. `profile_siblings` is probed exactly once per detailed
     * sweep, so it counts the sweeps directly.
     *
     * Isolated from the feed on purpose: this is the engine's own contract, and it must hold for the
     * profile screens and the API too, not only for matching.
     */
    public function test_one_completion_read_computes_the_profile_exactly_once(): void
    {
        $seeker = $this->buildPool();
        $engine = app(ProfileCompletionEngine::class);

        // Cold: nothing memoised, nothing cached.
        $payload = null;
        $queries = $this->captureQueries(function () use ($engine, $seeker, &$payload): void {
            $payload = $engine->forProfile($seeker);
        });

        $sweeps = $this->countReadsOf($queries, 'profile_siblings');
        $this->assertSame(
            1,
            $sweeps,
            'A single completion read must run the section sweep once, not twice: `profile_siblings` '
            .'was probed '.$sweeps.' times for one profile.'
        );

        $this->assertSame(
            ['core' => $payload['mandatory_core'], 'detailed' => $payload['detailed']],
            $payload['breakdown'],
            'The breakdown must stay exactly the two percentages it has always been.'
        );

        // Warm: a repeat read in the same request must not go back to the store at all — on production
        // the shared cache is the `database` store, so every repeat used to be a real SELECT.
        $repeat = $this->captureQueries(static function () use ($engine, $seeker): void {
            for ($i = 0; $i < 5; $i++) {
                $engine->forProfile($seeker);
            }
        });
        $this->assertSame(
            0,
            count($repeat),
            'A repeat completion read for the same profile in the same request must be free; got '
            .count($repeat).' queries for 5 reads.'
        );
    }

    // -------------------------------------------------------------------------------------------
    // Fixture
    // -------------------------------------------------------------------------------------------

    private function buildPool(): MatrimonyProfile
    {
        $seeker = $this->profile([
            'gender_id' => $this->maleGenderId,
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'full_name' => 'Member Feed Seeker',
            'caste_id' => $this->caste->id,
        ]);

        for ($i = 0; $i < self::POOL_SIZE; $i++) {
            $this->profile([
                'gender_id' => $this->femaleGenderId,
                'date_of_birth' => now()->subYears(26)->toDateString(),
                'full_name' => 'Member Feed Candidate '.$i,
                'caste_id' => $this->caste->id,
            ]);
        }

        return $seeker->fresh();
    }

    /**
     * `matrimony_profiles.location_id` is validated on `saving` and flushed on `saved`, so a fixture
     * row is created as a draft and then promoted — same shape as the sibling budget test.
     *
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
        $country = Location::query()->create([
            'name' => 'India', 'slug' => 'india', 'hierarchy' => 'country', 'level' => 0, 'is_active' => true,
        ]);
        $state = Location::query()->create([
            'name' => 'Maharashtra', 'slug' => 'maharashtra', 'hierarchy' => 'state', 'level' => 1,
            'parent_id' => $country->id, 'is_active' => true,
        ]);
        $district = Location::query()->create([
            'name' => 'Pune', 'slug' => 'pune', 'hierarchy' => 'district', 'level' => 2,
            'parent_id' => $state->id, 'is_active' => true, 'lat' => 18.5204, 'lng' => 73.8567,
        ]);
        $taluka = Location::query()->create([
            'name' => 'Haveli', 'slug' => 'haveli', 'hierarchy' => 'taluka', 'level' => 3,
            'parent_id' => $district->id, 'is_active' => true, 'lat' => 18.4529, 'lng' => 73.8600,
        ]);
        $this->village = Location::query()->create([
            'name' => 'Wagholi', 'slug' => 'wagholi', 'hierarchy' => 'village', 'level' => 4,
            'parent_id' => $taluka->id, 'is_active' => true, 'lat' => 18.5800, 'lng' => 73.9800,
        ]);
    }

    // -------------------------------------------------------------------------------------------
    // Measurement
    // -------------------------------------------------------------------------------------------

    /**
     * @return list<string>  Executed SQL, in order.
     */
    private function captureQueries(callable $run): array
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $run();
        } finally {
            $log = DB::connection()->getQueryLog();
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        return array_map(static fn (array $row): string => (string) $row['query'], $log);
    }

    /**
     * SELECTs whose primary FROM table is the given one.
     *
     * Scoped to the leading table on purpose: `plans` in particular also appears inside the
     * subscription access subquery, and counting those would measure a different query entirely.
     *
     * @param  list<string>  $queries
     */
    private function countReadsOf(array $queries, string $table): int
    {
        $count = 0;

        foreach ($queries as $sql) {
            $normalised = strtolower(preg_replace('/\s+/', ' ', $sql));
            if (! str_starts_with($normalised, 'select')) {
                continue;
            }
            if (! preg_match('/ from [`"]([a-z0-9_]+)[`"]/', $normalised, $m)) {
                continue;
            }
            if ($m[1] === strtolower($table)) {
                $count++;
            }
        }

        return $count;
    }
}
