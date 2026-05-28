<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\EntitlementService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralAdminSupremeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.rewards_by_plan_slug' => [
                'gold_supreme' => 3,
            ],
        ]);
    }

    public function test_disabled_referral_code_blocks_new_registration(): void
    {
        $referrer = User::factory()->create([
            'referral_code' => 'DISABLE01',
            'referral_code_disabled_at' => now(),
        ]);

        $this->post(route('register'), [
            'name' => 'Blocked Invite',
            'mobile' => '9876500001',
            'password' => 'password',
            'password_confirmation' => 'password',
            'registering_for' => 'self',
            'invite_code' => 'DISABLE01',
        ])->assertRedirect();

        $this->assertDatabaseMissing('user_referrals', [
            'referrer_id' => $referrer->id,
        ]);
    }

    public function test_frozen_referrer_skips_purchase_reward(): void
    {
        $referrer = User::factory()->create([
            'referral_code' => 'FROZEN01',
            'referral_rewards_frozen_at' => now(),
        ]);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_supreme',
            'price' => 999,
            'is_active' => true,
            'sort_order' => 1,
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

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        app(ReferralService::class)->applyPurchaseRewardIfEligible($buyer, $plan);

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertFalse($row->reward_applied);
    }

    public function test_admin_force_applies_pending_claim(): void
    {
        $this->mock(EntitlementService::class, function ($mock): void {
            $mock->shouldReceive('resyncFromActiveSubscription')->andReturnNull();
        });

        $admin = User::factory()->create(['is_admin' => true]);
        $referrer = User::factory()->create(['referral_code' => 'FORCE01']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_supreme',
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

        $applied = app(ReferralService::class)->adminForceApplyPendingClaim($row, $admin);
        $this->assertTrue($applied);

        $row->refresh();
        $this->assertTrue($row->reward_applied);
        $this->assertSame(UserReferral::STATUS_APPLIED, $row->reward_status);
    }

    public function test_monthly_cap_override_unlimited_for_referrer(): void
    {
        $referrer = User::factory()->create([
            'referral_monthly_cap_override' => 0,
        ]);

        $progress = app(ReferralService::class)->monthlyCapProgressForReferrer($referrer);
        $this->assertNull($progress);
    }
}
