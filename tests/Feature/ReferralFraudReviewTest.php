<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\ReferralService;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralFraudReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.fraud.auto_hold_on_flags' => true,
            'referral.fraud.rapid_invites_per_day' => 5,
            'referral.rewards_by_plan_slug' => ['gold_fraud' => 3],
        ]);
    }

    private function seedPaidPlan(): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_fraud',
            'price' => 500,
            'final_price' => 500,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        foreach (PlanQuotaPolicyKeys::ordered() as $fk) {
            PlanQuotaPolicy::query()->create([
                'plan_id' => $plan->id,
                'feature_key' => $fk,
                'is_enabled' => false,
                'refresh_type' => PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST,
                'limit_value' => 0,
                'daily_sub_cap' => null,
                'per_day_usage_limit_enabled' => false,
                'overuse_mode' => PlanQuotaPolicy::OVERUSE_BLOCK,
                'pack_price_paise' => null,
                'pack_message_count' => null,
                'pack_validity_days' => null,
                'policy_meta' => null,
            ]);
        }

        return $plan;
    }

    public function test_assess_fraud_flags_detects_same_mobile(): void
    {
        $referrer = User::factory()->create(['mobile' => '9876543210']);
        $referred = User::factory()->create(['mobile' => '9876543299']);
        $referred->mobile = '9876543210';

        $flags = app(ReferralService::class)->assessFraudFlags($referrer, $referred, '10.0.0.1');

        $this->assertContains(ReferralService::FRAUD_SAME_MOBILE, $flags);
    }

    public function test_assess_fraud_flags_detects_circular_referral(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'ALICE01']);
        $referred = User::factory()->create();

        UserReferral::query()->create([
            'referrer_id' => $referred->id,
            'referred_user_id' => $referrer->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        $flags = app(ReferralService::class)->assessFraudFlags($referrer, $referred, null);

        $this->assertContains(ReferralService::FRAUD_CIRCULAR, $flags);
    }

    public function test_reward_blocked_until_admin_approves(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'FRAUDOK1']);
        $referred = User::factory()->create();
        $admin = User::factory()->create();
        $plan = $this->seedPaidPlan();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_PENDING,
            'fraud_flags' => [ReferralService::FRAUD_RAPID_INVITES],
        ]);

        Subscription::withoutEvents(function () use ($referrer, $referred, $plan): void {
            Subscription::query()->create([
                'user_id' => $referrer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
            Subscription::query()->create([
                'user_id' => $referred->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        app(ReferralService::class)->applyPurchaseRewardIfEligible($referred, $plan);
        $row = UserReferral::query()->where('referred_user_id', $referred->id)->first();
        $this->assertFalse($row->reward_applied);

        app(ReferralService::class)->adminApproveReferralReview($row, $admin);
        $row->refresh();
        $this->assertSame(UserReferral::REVIEW_APPROVED, $row->review_status);
        $this->assertTrue($row->reward_applied);
    }

    public function test_reject_blocks_referrer_reward(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'FRAUDOK2']);
        $referred = User::factory()->create();
        $admin = User::factory()->create();
        $plan = $this->seedPaidPlan();

        $row = UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_PENDING,
            'fraud_flags' => [ReferralService::FRAUD_SAME_MOBILE],
        ]);

        app(ReferralService::class)->adminRejectReferralReview($row, $admin, 'Same mobile fraud pattern detected');

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

        app(ReferralService::class)->applyPurchaseRewardIfEligible($referred, $plan);
        $row->refresh();
        $this->assertSame(UserReferral::REVIEW_REJECTED, $row->review_status);
        $this->assertFalse($row->reward_applied);
        $this->assertNull(app(ReferralService::class)->referredCheckoutOfferFor($referred));
    }
}
