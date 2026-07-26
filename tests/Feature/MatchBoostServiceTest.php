<?php

namespace Tests\Feature;

use App\Models\MatchingBoostRule;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\ProfileKycSubmission;
use App\Models\User;
use App\Services\MatchBoostService;
use App\Services\Matching\MatchBoostSettingDefaults;
use App\Services\Matching\MatchingConfigService;
use App\Services\ProfileCompletionEngine;
use App\Services\SubscriptionService;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Trust/quality ranking contract for {@see MatchBoostService}.
 *
 * The product is matchmaker-driven: a reachable, verified, complete, photo-bearing, recently-active
 * candidate must rank above an empty one, and a paid plan must never buy its way past that.
 */
class MatchBoostServiceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_SCORE = 60;

    /** @var list<string> */
    private array $photoFiles = [];

    private User $seeker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlansSeeder::class);
        $this->seedBoostRules();

        $this->seeker = User::factory()->create();
        MatrimonyProfile::factory()->for($this->seeker)->create();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->photoFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->photoFiles = [];

        parent::tearDown();
    }

    public function test_candidate_with_a_photo_outranks_an_identical_candidate_without_one(): void
    {
        $withPhoto = $this->candidate(photo: true);
        $withoutPhoto = $this->candidate(photo: false);

        $this->assertGreaterThan(
            $this->score($withoutPhoto),
            $this->score($withPhoto),
            'A profile with an approved photo must rank above an otherwise identical photoless profile.',
        );
    }

    public function test_higher_profile_completeness_outranks_lower_completeness(): void
    {
        $complete = $this->candidate();
        $sparse = $this->candidate();

        $this->fakeCompleteness([
            (int) $complete->matrimonyProfile->id => 100,
            (int) $sparse->matrimonyProfile->id => 30,
        ]);

        $this->assertGreaterThan($this->score($sparse), $this->score($complete));
    }

    public function test_verified_candidate_outranks_unverified_candidate(): void
    {
        $verified = $this->candidate(mobileVerified: true, kycApproved: true);
        $unverified = $this->candidate(mobileVerified: false, kycApproved: false);

        $this->assertGreaterThan($this->score($unverified), $this->score($verified));
    }

    public function test_recently_active_candidate_outranks_a_long_abandoned_one(): void
    {
        $fresh = $this->candidate(lastActiveDaysAgo: 1);
        $abandoned = $this->candidate(lastActiveDaysAgo: 400);

        $this->assertGreaterThan($this->score($abandoned), $this->score($fresh));
        $this->assertSame(
            self::BASE_SCORE,
            $this->score($abandoned),
            'A profile abandoned past the stale horizon must earn no recency points at all.',
        );
    }

    public function test_paid_but_incomplete_profile_does_not_outrank_a_complete_verified_free_profile(): void
    {
        $paidEmpty = $this->candidate(photo: false, mobileVerified: false, kycApproved: false);
        $this->subscribeToGold($paidEmpty);

        $freeQuality = $this->candidate(photo: true, mobileVerified: true, kycApproved: true);

        $this->fakeCompleteness([
            (int) $paidEmpty->matrimonyProfile->id => 20,
            (int) $freeQuality->matrimonyProfile->id => 100,
        ]);

        $paidScore = $this->score($paidEmpty);
        $freeScore = $this->score($freeQuality);

        $this->assertGreaterThan(
            $paidScore,
            $freeScore,
            "A paid but empty profile (score {$paidScore}) must not outrank a complete, verified free one (score {$freeScore}).",
        );
    }

    public function test_boost_is_bounded_by_the_aggregate_cap_and_reasons_are_truthful(): void
    {
        $best = $this->candidate(photo: true, mobileVerified: true, kycApproved: true);
        $this->subscribeToGold($best);
        $this->fakeCompleteness([(int) $best->matrimonyProfile->id => 100]);

        $cap = (int) MatchingBoostRule::query()->where('boost_type', 'aggregate_cap')->value('max_cap');
        $svc = app(MatchBoostService::class);

        $delta = $svc->boostDelta($this->seeker, $best);
        $this->assertGreaterThan(0, $delta);
        $this->assertLessThanOrEqual($cap, $delta, 'Every adjustment must stay inside the admin aggregate cap.');

        // Explainability: the listed reasons must add up to exactly the delta that was applied.
        $signals = $svc->explainBoost($this->seeker, $best);
        $this->assertNotEmpty($signals);
        $this->assertSame($delta, array_sum(array_column($signals, 'points')));
        foreach ($signals as $signal) {
            $this->assertNotSame('', trim($signal['reason']));
            $this->assertGreaterThan(0, $signal['points']);
        }

        // Never past 100, whatever the boost.
        $this->assertSame(100, $svc->applyBoost($this->seeker, $best, 100));
    }

    public function test_zero_aggregate_cap_disables_the_whole_layer(): void
    {
        $best = $this->candidate(photo: true, mobileVerified: true, kycApproved: true);

        MatchingBoostRule::query()->where('boost_type', 'aggregate_cap')->update(['max_cap' => 0]);
        Cache::flush();

        $this->assertSame(self::BASE_SCORE, $this->score($best));
    }

    public function test_rebalance_migration_upgrades_an_already_seeded_install(): void
    {
        // Reproduce the pre-rebalance rule set an existing install would be carrying.
        MatchingBoostRule::query()->delete();
        foreach ([
            ['boost_type' => 'active', 'value' => 3, 'max_cap' => 100, 'is_active' => true, 'meta' => ['active_within_days' => 7]],
            ['boost_type' => 'premium', 'value' => 2, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'gold_extra', 'value' => 10, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'silver_extra', 'value' => 5, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'similarity', 'value' => 3, 'max_cap' => 100, 'is_active' => true, 'meta' => []],
            ['boost_type' => 'ai', 'value' => 0, 'max_cap' => 20, 'is_active' => false, 'meta' => ['ai_provider' => 'sarvam']],
            ['boost_type' => 'aggregate_cap', 'value' => 0, 'max_cap' => 20, 'is_active' => true, 'meta' => []],
        ] as $row) {
            MatchingBoostRule::query()->create($row);
        }

        $migration = require database_path('migrations/2026_07_26_120000_rebalance_matching_boost_rules_for_quality_signals.php');
        $migration->up();

        $rules = MatchingBoostRule::query()->pluck('value', 'boost_type');
        $this->assertSame(7, (int) $rules['verified_kyc']);
        $this->assertSame(7, (int) $rules['photo']);
        $this->assertSame(6, (int) $rules['completeness']);
        $this->assertSame(5, (int) $rules['verified_mobile']);
        $this->assertSame(5, (int) $rules['active'], 'recency weight 3 → 5');
        $this->assertSame(2, (int) $rules['gold_extra'], 'gold extra 10 → 2');
        $this->assertSame(1, (int) $rules['silver_extra'], 'silver extra 5 → 1');

        $active = MatchingBoostRule::query()->where('boost_type', 'active')->firstOrFail();
        $this->assertSame(180, (int) $active->meta['stale_after_days']);
        $this->assertSame(7, (int) $active->meta['active_within_days']);

        $cap = MatchingBoostRule::query()->where('boost_type', 'aggregate_cap')->firstOrFail();
        $this->assertSame(25, (int) $cap->max_cap);

        // Idempotent: a second run must not duplicate or shift anything further.
        $migration->up();
        $this->assertSame(11, MatchingBoostRule::query()->count());
        $this->assertSame(2, (int) MatchingBoostRule::query()->where('boost_type', 'gold_extra')->value('value'));
    }

    public function test_admin_tuned_tier_weight_is_not_silently_overwritten_by_the_migration(): void
    {
        MatchingBoostRule::query()->where('boost_type', 'gold_extra')->update(['value' => 8]);

        $migration = require database_path('migrations/2026_07_26_120000_rebalance_matching_boost_rules_for_quality_signals.php');
        $migration->up();

        $this->assertSame(8, (int) MatchingBoostRule::query()->where('boost_type', 'gold_extra')->value('value'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function score(User $candidate): int
    {
        return app(MatchBoostService::class)->applyBoost($this->seeker, $candidate, self::BASE_SCORE);
    }

    private function candidate(
        bool $photo = false,
        bool $mobileVerified = false,
        bool $kycApproved = false,
        int $lastActiveDaysAgo = 1,
    ): User {
        $user = User::factory()->create([
            'mobile_verified_at' => $mobileVerified ? now()->subDay() : null,
            'last_seen_at' => now()->subDays($lastActiveDaysAgo),
        ]);

        $profile = MatrimonyProfile::factory()->for($user)->create();

        if ($photo) {
            $file = 'boost-test-'.$profile->id.'.jpg';
            $dir = storage_path('app/public/matrimony_photos');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir.DIRECTORY_SEPARATOR.$file, 'x');
            $this->photoFiles[] = $dir.DIRECTORY_SEPARATOR.$file;

            DB::table('matrimony_profiles')
                ->where('id', $profile->id)
                ->update(['profile_photo' => $file, 'photo_approved' => true]);
        }

        if ($kycApproved) {
            ProfileKycSubmission::query()->create([
                'matrimony_profile_id' => $profile->id,
                'id_document_path' => 'kyc/'.$profile->id.'.pdf',
                'status' => ProfileKycSubmission::STATUS_APPROVED,
                'reviewed_at' => now()->subDay(),
            ]);
        }

        // The engine's recency signal falls back to the profile's own updated_at for dormant
        // (Suchak-created) accounts, so an "abandoned" candidate must be stale on both.
        DB::table('matrimony_profiles')
            ->where('id', $profile->id)
            ->update(['updated_at' => now()->subDays($lastActiveDaysAgo)]);

        Cache::flush();

        return $user->fresh(['matrimonyProfile']);
    }

    private function subscribeToGold(User $user): void
    {
        $gold = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $term = PlanTerm::query()
            ->where('plan_id', $gold->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->firstOrFail();

        app(SubscriptionService::class)->subscribe($user, $gold, (int) $term->id, null);
        Cache::flush();
    }

    /**
     * Pins the shared completion SSOT so this test asserts ranking, not the completeness calculator.
     *
     * @param  array<int, int>  $scoreByProfileId
     */
    private function fakeCompleteness(array $scoreByProfileId): void
    {
        $stub = new class($scoreByProfileId) extends ProfileCompletionEngine
        {
            /** @param array<int, int> $scores */
            public function __construct(private array $scores) {}

            public function forProfile(MatrimonyProfile $profile): array
            {
                $score = (int) ($this->scores[(int) $profile->id] ?? 0);

                return [
                    'mandatory_core' => $score,
                    'detailed' => $score,
                    'score' => $score,
                    'is_mandatory_complete' => $score >= 100,
                    'is_detailed_complete' => $score >= 100,
                    'breakdown' => ['core' => $score, 'detailed' => $score],
                ];
            }
        };

        $this->instance(ProfileCompletionEngine::class, $stub);
        Cache::flush();
    }

    private function seedBoostRules(): void
    {
        // Let the engine seed its own defaults first — it creates (never upserts) boost rows, so
        // pre-inserting them here would collide on the unique boost_type index.
        app(MatchingConfigService::class)->ensureDefaults();

        foreach (MatchBoostSettingDefaults::snapshot() as $row) {
            MatchingBoostRule::query()->updateOrCreate(
                ['boost_type' => $row['boost_type']],
                [
                    'value' => $row['value'],
                    'max_cap' => $row['max_cap'],
                    'is_active' => $row['is_active'],
                    'meta' => $row['meta'] ?? [],
                ],
            );
        }
    }
}
