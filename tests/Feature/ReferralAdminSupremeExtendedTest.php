<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\ReferralRewardLedger;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserEngagementStats;
use App\Models\UserReferral;
use App\Services\EntitlementService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralAdminSupremeExtendedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.rewards_by_plan_slug' => ['gold_supreme_ext' => 5],
        ]);
    }

    public function test_admin_reassignes_referral_to_new_referrer(): void
    {
        $oldReferrer = User::factory()->create(['referral_code' => 'OLDREF01']);
        $newReferrer = User::factory()->create(['referral_code' => 'NEWREF01']);
        $buyer = User::factory()->create();

        $row = UserReferral::query()->create([
            'referrer_id' => $oldReferrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        $ok = app(ReferralService::class)->adminReassignReferral(
            $row,
            $newReferrer,
            User::factory()->create(['is_admin' => true]),
            'Moving invite to correct referrer account',
        );

        $this->assertTrue($ok);
        $row->refresh();
        $this->assertSame((int) $newReferrer->id, (int) $row->referrer_id);
        $this->assertDatabaseHas('referral_reward_ledgers', [
            'user_referral_id' => $row->id,
            'action_type' => 'admin_reassign',
        ]);
    }

    public function test_admin_partial_grant_extends_subscription_without_marking_applied(): void
    {
        $this->mock(EntitlementService::class, function ($mock): void {
            $mock->shouldReceive('resyncFromActiveSubscription')->andReturnNull();
        });

        $admin = User::factory()->create(['is_admin' => true]);
        $referrer = User::factory()->create(['referral_code' => 'PART01']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_supreme_ext',
            'price' => 999,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $endsAt = now()->addDays(10);
        Subscription::withoutEvents(function () use ($referrer, $plan, $endsAt): void {
            Subscription::query()->create([
                'user_id' => $referrer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        $row = UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        $ok = app(ReferralService::class)->adminApplyPartialReward(
            $row,
            $admin,
            2,
            ['chat_send_limit' => 3],
            false,
            'Goodwill partial grant for support case',
        );

        $this->assertTrue($ok);
        $row->refresh();
        $this->assertFalse($row->reward_applied);

        $sub = Subscription::query()->where('user_id', $referrer->id)->first();
        $this->assertTrue($sub->ends_at->greaterThan($endsAt));

        $this->assertDatabaseHas('referral_reward_ledgers', [
            'user_referral_id' => $row->id,
            'action_type' => 'admin_partial_grant',
        ]);
    }

    public function test_admin_revoke_reverses_applied_reward_and_engagement_count(): void
    {
        $this->mock(EntitlementService::class, function ($mock): void {
            $mock->shouldReceive('resyncFromActiveSubscription')->andReturnNull();
        });

        $admin = User::factory()->create(['is_admin' => true]);
        $referrer = User::factory()->create(['referral_code' => 'REVOKE01']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_supreme_ext',
            'price' => 999,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $endsAt = now()->addDays(20);
        Subscription::withoutEvents(function () use ($referrer, $plan, $endsAt): void {
            Subscription::query()->create([
                'user_id' => $referrer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        $row = UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'reward_status' => UserReferral::STATUS_PENDING_CLAIM,
            'pending_plan_id' => $plan->id,
            'pending_reward' => ['bonus_days' => 3, 'feature_bonus' => []],
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        app(ReferralService::class)->adminForceApplyPendingClaim($row, $admin);
        $row->refresh();
        $this->assertTrue($row->reward_applied);

        UserEngagementStats::query()->updateOrCreate(
            ['user_id' => $referrer->id],
            ['referrals_done' => 1],
        );

        $ok = app(ReferralService::class)->adminRevokeAppliedReward(
            $row,
            $admin,
            'Reversal required after fraud investigation completed',
        );

        $this->assertTrue($ok);
        $row->refresh();
        $this->assertFalse($row->reward_applied);
        $this->assertSame(UserReferral::STATUS_REWARD_REVOKED, $row->reward_status);

        $this->assertSame(0, (int) UserEngagementStats::query()->where('user_id', $referrer->id)->value('referrals_done'));

        $this->assertTrue(
            ReferralRewardLedger::query()
                ->where('user_referral_id', $row->id)
                ->where('action_type', 'admin_reward_revoked')
                ->exists()
        );
    }
}
