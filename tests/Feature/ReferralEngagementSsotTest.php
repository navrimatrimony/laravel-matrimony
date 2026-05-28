<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserEngagementStats;
use App\Models\UserReferral;
use App\Services\EntitlementService;
use App\Services\ReferralService;
use App\Services\UserEngagementStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralEngagementSsotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.rewards_by_plan_slug' => ['gold_ssot' => 3],
        ]);
    }

    public function test_reward_applied_syncs_referrals_done_engagement_row(): void
    {
        $this->mock(EntitlementService::class, function ($mock): void {
            $mock->shouldReceive('resyncFromActiveSubscription')->andReturnNull();
        });

        $referrer = User::factory()->create(['referral_code' => 'SSOTREF01']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_ssot',
            'price' => 999,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'reward_status' => UserReferral::STATUS_PENDING_CLAIM,
            'pending_plan_id' => $plan->id,
            'pending_reward' => ['bonus_days' => 3, 'feature_bonus' => []],
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        Subscription::withoutEvents(function () use ($referrer, $plan): void {
            Subscription::query()->create([
                'user_id' => $referrer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        app(ReferralService::class)->claimPendingReferralRewards($referrer);

        $referralRow = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertTrue($referralRow->reward_applied);

        $engagementRow = UserEngagementStats::query()->where('user_id', $referrer->id)->first();
        $this->assertNotNull($engagementRow);
        $this->assertSame(1, (int) $engagementRow->referrals_done);

        $summary = app(ReferralService::class)->summaryForReferrer($referrer);
        $this->assertSame(1, (int) $summary['referrals_done']);
        $this->assertSame(1, (int) $summary['rewards_earned']);
    }

    public function test_engagement_service_sync_command_backfills_referrer(): void
    {
        $referrer = User::factory()->create();
        $buyer = User::factory()->create();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => true,
            'reward_status' => UserReferral::STATUS_APPLIED,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        app(UserEngagementStatsService::class)->syncReferralsDone($referrer);

        $this->assertSame(1, app(UserEngagementStatsService::class)->referralsDoneFor($referrer));
    }

    public function test_artisan_sync_referrals_command(): void
    {
        $referrer = User::factory()->create();
        $buyer = User::factory()->create();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => true,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        $this->artisan('engagement:sync-referrals', ['--user' => (string) $referrer->id])
            ->assertSuccessful();

        $this->assertSame(1, (int) UserEngagementStats::query()->where('user_id', $referrer->id)->value('referrals_done'));
    }
}
