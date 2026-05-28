<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use App\Support\PlanQuotaPolicyKeys;
use App\Services\ReferralService;
use App\Services\RevenueOrchestratorService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralReferredCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.referred_checkout' => [
                'enabled' => true,
                'percent_off' => 10,
                'extra_days' => 3,
            ],
        ]);
    }

    private function seedPaidPlan(string $slug = 'gold_referred_checkout'): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Test Gold',
            'slug' => $slug,
            'price' => 1000,
            'final_price' => 1000,
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

    public function test_referred_buyer_gets_percent_discount_on_first_checkout_without_coupon(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'REFCHK01']);
        $buyer = User::factory()->create();
        $plan = $this->seedPaidPlan();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
        ]);

        $resolved = app(SubscriptionService::class)->resolvePaidPlanCheckout($buyer, $plan, null, null);

        $this->assertSame(100.0, (float) ($resolved['referral_checkout_discount'] ?? 0));
        $this->assertSame(900.0, (float) $resolved['final_amount']);
        $this->assertSame(3, (int) ($resolved['referral_extra_duration_days'] ?? 0));
    }

    public function test_coupon_code_skips_referred_checkout_discount(): void
    {
        config(['monetization.coupons.enabled' => true]);

        Coupon::query()->create([
            'code' => 'SAVE10',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 10,
            'max_redemptions' => null,
            'redemptions_count' => 0,
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => true,
            'min_purchase_amount' => null,
            'applicable_plan_ids' => null,
            'applicable_duration_types' => null,
            'description' => null,
        ]);

        $referrer = User::factory()->create(['referral_code' => 'REFCHK02']);
        $buyer = User::factory()->create();
        $plan = $this->seedPaidPlan();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
        ]);

        $resolved = app(SubscriptionService::class)->resolvePaidPlanCheckout($buyer, $plan, null, 'SAVE10');

        $this->assertSame(0.0, (float) ($resolved['referral_checkout_discount'] ?? 0));
        $this->assertSame(900.0, (float) $resolved['final_amount']);
    }

    public function test_bonus_marked_consumed_after_finalize_purchase(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'REFCHK03']);
        $buyer = User::factory()->create();
        $plan = $this->seedPaidPlan();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
        ]);

        $sub = Subscription::withoutEvents(function () use ($buyer, $plan) {
            return Subscription::query()->create([
                'user_id' => $buyer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        app(RevenueOrchestratorService::class)->finalizePurchase($buyer, $plan, $sub);

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertNotNull($row->referred_checkout_bonus_used_at);

        $offer = app(ReferralService::class)->referredCheckoutOfferFor($buyer);
        $this->assertNull($offer);
    }

    public function test_prior_paid_subscription_disables_invite_offer(): void
    {
        $buyer = User::factory()->create();
        $plan = $this->seedPaidPlan('gold_referred_checkout_2');
        $other = $this->seedPaidPlan('silver_prior_checkout');

        UserReferral::query()->create([
            'referrer_id' => User::factory()->create()->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
        ]);

        Subscription::withoutEvents(function () use ($buyer, $other): void {
            Subscription::query()->create([
                'user_id' => $buyer->id,
                'plan_id' => $other->id,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(10),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        $resolved = app(SubscriptionService::class)->resolvePaidPlanCheckout($buyer, $plan, null, null);
        $this->assertSame(0.0, (float) ($resolved['referral_checkout_discount'] ?? 0));
        $this->assertSame(1000.0, (float) $resolved['final_amount']);
    }
}
