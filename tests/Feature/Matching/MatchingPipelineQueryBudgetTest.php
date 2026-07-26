<?php

namespace Tests\Feature\Matching;

use App\Models\Caste;
use App\Models\Location;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\User;
use App\Services\Matching\CandidatePoolStrategy;
use App\Services\Matching\MatchingService;
use App\Services\Matching\MatchRelaxationLadder;
use App\Services\ProfilePreferenceMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Performance contract for the relaxation ladder (regression cover for the 2026-07-26 HTTP 524s on
 * `GET /api/v1/suchak/representations/{rep}/suggestions`).
 *
 * The ladder walks up to four tiers. Tiers are cumulative supersets, and everything that decides a
 * candidate's SCORE is tier-independent — so a tier must only re-FILTER an already-evaluated pool, it
 * must never re-run the pipeline. The original implementation restarted from scratch on every tier
 * (candidate SQL + bulk preference load + a preference build per candidate per direction + scoring),
 * and the Suchak layer then evaluated every surviving candidate twice more. On production that reached
 * the Cloudflare 100 s edge timeout.
 *
 * These are budget assertions, not micro-benchmarks: the ceilings are deliberately far above the
 * measured cost, so ordinary changes never trip them but a return of the per-tier (or per-candidate)
 * re-evaluation does — that failure mode multiplies the counts, it does not nudge them.
 */
class MatchingPipelineQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Candidates in the fixture pool. Large enough that per-candidate repetition is unmistakable in the
     * query count, small enough to keep the test fast.
     */
    private const POOL_SIZE = 30;

    private int $maleGenderId;

    private int $femaleGenderId;

    private Location $village;

    private Caste $seekerCaste;

    private Caste $otherCaste;

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
        $this->seekerCaste = Caste::query()->create([
            'religion_id' => $religion->id, 'key' => 'maratha', 'label' => 'Maratha', 'is_active' => true,
        ]);
        $this->otherCaste = Caste::query()->create([
            'religion_id' => $religion->id, 'key' => 'other', 'label' => 'Other', 'is_active' => true,
        ]);

        ProfilePreferenceMatchService::flushRuntimeCaches();
    }

    public function test_the_relaxation_ladder_evaluates_the_candidate_pool_once_per_request(): void
    {
        // Floor unreachable on purpose: the ladder is forced to walk every tier, which is the exact
        // shape that used to multiply the whole pipeline by four.
        config(['matching.relaxation.floor' => 500]);

        $seeker = $this->buildPool();

        $service = app(MatchingService::class);
        $queries = $this->captureQueries(static fn () => $service->findMatches($seeker, 12));

        $this->assertSame(
            MatchRelaxationLadder::TIER_RELAXED_CASTE,
            $service->lastRelaxationSummary()['tier'],
            'Fixture must force the ladder to the top tier, otherwise this budget proves nothing.'
        );

        // Assertion 1 — the pool itself. Only two SQL shapes exist across the four tiers (caste-locked
        // for tiers 0-2, caste-open once caste relaxes), so two fetches is the honest maximum. The
        // pre-fix implementation issued one per tier; this assertion is what catches that directly.
        $poolQueries = $this->countCandidatePoolQueries($queries);
        $this->assertLessThanOrEqual(
            2,
            $poolQueries,
            'The candidate pool must be fetched once per distinct SQL shape (caste-locked / caste-open), '
            .'not once per tier. Got '.$poolQueries.' pool queries across '
            .count(MatchRelaxationLadder::tiers()).' tiers.'
        );

        // Assertion 2 — the per-candidate preference payload. This is the expensive half of a tier pass
        // (~14 queries covering the criteria row and every preference pivot). It is keyed by profile id
        // and is tier-independent, so the second pool shape may top it up with the newly admitted ids
        // and nothing else should re-load it.
        $bulkLoads = $this->countBulkPreferenceLoads($queries);
        $this->assertLessThanOrEqual(
            3,
            $bulkLoads,
            'Bulk partner-preference loads must be shared across tiers, not repeated per tier or per '
            .'candidate. Got '.$bulkLoads.'.'
        );

        // Assertion 3 — a backstop, deliberately loose. The two assertions above are the real contract;
        // this only catches a broad regression that does not happen to touch either query shape.
        // Measured at the time of writing: ~152 queries per candidate on this fixture.
        $perCandidate = count($queries) / self::POOL_SIZE;
        $this->assertLessThan(
            250,
            $perCandidate,
            'Query budget blown for a '.self::POOL_SIZE.'-candidate pool: '
            .number_format($perCandidate, 1).' queries per candidate ('.count($queries).' total).'
        );
    }

    public function test_the_suchak_fit_pass_reuses_the_engine_run_instead_of_restarting_it(): void
    {
        config(['matching.relaxation.floor' => 500]);

        $seeker = $this->buildPool();
        $service = app(MatchingService::class);

        // Warm the engine exactly as a suggestions request does.
        $rows = $service->findMatchesForPool($seeker, CandidatePoolStrategy::members(), MatchingService::TAB_PERFECT, 12);
        $this->assertGreaterThan(0, $rows->count());

        // SuchakMatchFitService asks these two questions for every surviving candidate. Both used to
        // wipe the run caches and re-load the seeker's whole preference context per candidate, which
        // also flushed the shared geography memo behind the (expensive) nearby-taluka scan.
        $candidates = $rows->map(static fn (array $r): MatrimonyProfile => $r['profile'])->all();

        $queries = $this->captureQueries(static function () use ($service, $seeker, $candidates): void {
            foreach ($candidates as $candidate) {
                $service->isEligiblePair($seeker, $candidate);
                $service->computeMatchBreakdown($seeker, $candidate, false);
            }
        });

        $perCandidate = count($queries) / max(1, count($candidates));
        $this->assertLessThan(
            12,
            $perCandidate,
            'The Suchak fit pass is re-deriving the seeker context per candidate instead of reusing the '
            .'engine run. '.number_format($perCandidate, 1).' queries per candidate over '
            .count($candidates).' candidates.'
        );
    }

    // -------------------------------------------------------------------------------------------
    // Fixture
    // -------------------------------------------------------------------------------------------

    /**
     * A seeker plus {@see self::POOL_SIZE} candidates with deliberately sparse preferences, so nothing
     * is excluded early and the ladder has to climb. Half sit outside the seeker's caste so tier 3
     * genuinely re-admits rows the strict tier rejected.
     */
    private function buildPool(): MatrimonyProfile
    {
        $seeker = $this->profile([
            'gender_id' => $this->maleGenderId,
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'full_name' => 'Budget Seeker',
            'caste_id' => $this->seekerCaste->id,
        ]);

        // Explicit intercaste refusal → caste lock, so the tier-3 pool really is a different SQL shape
        // from the tier-0 pool. Without it every tier would trivially share one query.
        DB::table('profile_partner_community_flags')->insert([
            'profile_id' => $seeker->id,
            'interested_in_intercaste' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 0; $i < self::POOL_SIZE; $i++) {
            $this->profile([
                'gender_id' => $this->femaleGenderId,
                'date_of_birth' => now()->subYears(26)->toDateString(),
                'full_name' => 'Budget Candidate '.$i,
                'caste_id' => ($i % 2 === 0 ? $this->seekerCaste : $this->otherCaste)->id,
            ]);
        }

        return $seeker->fresh();
    }

    /**
     * `matrimony_profiles.location_id` lives in `profile_addresses` and is only flushed on `saved`,
     * while the observer validates it on `saving` — so a fixture row is created as a draft and then
     * promoted, exactly as {@see \Tests\Feature\MatchingEngineCorrectnessTest} does.
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
     * The ladder's candidate fetch: the only `matrimony_profiles` SELECT that carries the pool cap.
     *
     * @param  list<string>  $queries
     */
    private function countCandidatePoolQueries(array $queries): int
    {
        $poolLimit = (int) config('matching.candidate_pool_limit', 200);
        $count = 0;

        foreach ($queries as $sql) {
            $normalised = strtolower(preg_replace('/\s+/', ' ', $sql));
            if (! str_starts_with($normalised, 'select * from "matrimony_profiles"')) {
                continue;
            }
            if (str_contains($normalised, 'limit '.$poolLimit)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The head of {@see \App\Services\Matching\MatchingService::bulkLoadTargetPreferences()} — one
     * batched read of every candidate's preference criteria row.
     *
     * @param  list<string>  $queries
     */
    private function countBulkPreferenceLoads(array $queries): int
    {
        $count = 0;

        foreach ($queries as $sql) {
            $normalised = strtolower(preg_replace('/\s+/', ' ', $sql));
            // The bulk loader uses the query builder, so the column is unqualified. Eloquent's
            // `preferenceCriteria` eager load hits the same table but qualifies it
            // (`"profile_preference_criteria"."profile_id"`) — that one is a legitimate per-pool read
            // and must not be counted here.
            if (str_contains($normalised, 'from "profile_preference_criteria"')
                && str_contains($normalised, 'where "profile_id" in (')) {
                $count++;
            }
        }

        return $count;
    }
}
