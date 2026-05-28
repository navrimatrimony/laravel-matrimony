<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\ReferralRewardRule;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\ReferralService;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralReferredCheckoutAdminTest extends TestCase
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
                'extra_days' => 0,
            ],
        ]);
    }

    private function seedPaidPlan(string $slug = 'gold_admin_checkout'): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Gold',
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
                'limit_value' => 10,
            ]);
        }

        return $plan;
    }

    private function referredBuyerWithReferral(): array
    {
        $referrer = User::factory()->create(['referral_code' => 'ADMINCHK01']);
        $buyer = User::factory()->create();
        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        return [$buyer];
    }

    public function test_admin_disabled_referred_checkout_blocks_discount(): void
    {
        AdminSetting::setValue('referral_referred_checkout_enabled', '0');

        [$buyer] = $this->referredBuyerWithReferral();
        $plan = $this->seedPaidPlan();

        $discount = app(ReferralService::class)->computeReferredCheckoutDiscount($buyer, $plan, 1000.0);

        $this->assertNull($discount);
    }

    public function test_admin_percent_override_applies_at_checkout(): void
    {
        AdminSetting::setValue('referral_referred_checkout_enabled', '1');
        AdminSetting::setValue('referral_referred_checkout_percent', '25');
        AdminSetting::setValue('referral_referred_checkout_extra_days', '0');

        [$buyer] = $this->referredBuyerWithReferral();
        $plan = $this->seedPaidPlan('gold_admin_checkout_pct');

        $discount = app(ReferralService::class)->computeReferredCheckoutDiscount($buyer, $plan, 1000.0);

        $this->assertNotNull($discount);
        $this->assertSame(250.0, $discount['discount_amount']);
    }

    public function test_plan_rule_exclude_blocks_invite_checkout_for_that_plan(): void
    {
        AdminSetting::setValue('referral_referred_checkout_enabled', '1');
        AdminSetting::setValue('referral_referred_checkout_percent', '10');

        [$buyer] = $this->referredBuyerWithReferral();
        $plan = $this->seedPaidPlan('gold_admin_checkout_excl');

        ReferralRewardRule::query()->create([
            'plan_slug' => $plan->slug,
            'is_active' => true,
            'referred_checkout_excluded' => true,
        ]);

        $discount = app(ReferralService::class)->computeReferredCheckoutDiscount($buyer, $plan, 1000.0);

        $this->assertNull($discount);
    }

    public function test_plan_rule_percent_override_overrides_global(): void
    {
        AdminSetting::setValue('referral_referred_checkout_enabled', '1');
        AdminSetting::setValue('referral_referred_checkout_percent', '10');

        [$buyer] = $this->referredBuyerWithReferral();
        $plan = $this->seedPaidPlan('gold_admin_checkout_plan');

        ReferralRewardRule::query()->create([
            'plan_slug' => $plan->slug,
            'is_active' => true,
            'referred_checkout_excluded' => false,
            'referred_checkout_percent_off' => 40,
        ]);

        $discount = app(ReferralService::class)->computeReferredCheckoutDiscount($buyer, $plan, 1000.0);

        $this->assertNotNull($discount);
        $this->assertSame(400.0, $discount['discount_amount']);
    }
}
