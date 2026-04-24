<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RevenueSummaryService;
use App\Services\SubscriptionService;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_summary_matches_subscription_service_resolution(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $plan = Plan::query()->where('slug', 'silver_male')->firstOrFail();
        $termId = (int) $plan->terms()->where('is_visible', true)->value('id');
        $this->assertGreaterThan(0, $termId);

        $resolved = app(SubscriptionService::class)->resolvePaidPlanCheckout($user, $plan, $termId, null, null);
        $out = app(RevenueSummaryService::class)->forSubscriptionResolvedCheckout($user, $plan, $resolved);

        $this->assertSame(round((float) $resolved['base_amount'], 2), $out['base_plan_price']);
        $this->assertSame(round((float) $resolved['final_amount'], 2), $out['final_price']);
        $this->assertStringContainsString('₹', (string) $out['base_plan_price_display']);
        $this->assertFalse($out['subscription_checkout_uses_wallet']);
    }

    public function test_completed_receipt_skips_carry_when_model_was_not_just_created(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $plan = Plan::query()->where('slug', 'silver_male')->firstOrFail();

        $id = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'meta' => ['carry_quota' => ['interest_send_limit' => 5]],
        ])->id;

        $sub = Subscription::query()->findOrFail($id);

        $pending = [
            'user_id' => (int) $user->id,
            'base_amount' => 500.0,
            'final_amount' => 500.0,
            'final_amount_after_coupon' => 500.0,
            'coupon_discount' => 0.0,
            'coupon_code' => null,
            'extra_duration_days' => 0,
            'subscription_meta_preview' => [],
        ];

        $out = app(RevenueSummaryService::class)->forCompletedSubscriptionPayu($sub, $pending);

        $this->assertSame([], $out['bonus_quota_added']);
    }
}
