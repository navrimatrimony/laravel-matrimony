<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanQuotaCheckoutSnapshot;
use App\Services\SubscriptionService;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 3A: quota_bonus_percent / quota_duration_multiplier from checkout_snapshot only.
 */
class CheckoutSnapshotQuotaTimingContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_writes_missing_quota_timing_from_plan_term(): void
    {
        $plan = $this->makePaidPlan(bonus: 20);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        // Complete quota_policies (created hook / entitlements) but omit quota timing keys.
        $sub = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $term->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'grace_period_days' => 0,
            'meta' => [
                'checkout_snapshot' => array_merge(
                    PlanQuotaCheckoutSnapshot::forPlan($plan),
                    [
                        'plan_term_id' => (int) $term->id,
                        'plan_name' => $plan->name,
                    ],
                ),
            ],
        ]);

        $this->assertArrayNotHasKey('quota_bonus_percent', $sub->checkoutSnapshot());
        $this->assertArrayNotHasKey('quota_duration_multiplier', $sub->checkoutSnapshot());

        $changed = app(SubscriptionService::class)->backfillCheckoutSnapshotQuotaTiming($sub->fresh());
        $this->assertTrue($changed);

        $snap = $sub->fresh()->checkoutSnapshot();
        $this->assertSame(20, (int) $snap['quota_bonus_percent']);
        $this->assertSame(1.0, (float) $snap['quota_duration_multiplier']);
    }

    public function test_complete_snapshot_is_unchanged_by_backfill(): void
    {
        $plan = $this->makePaidPlan(bonus: 50);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $before = $sub->checkoutSnapshot();
        $this->assertArrayHasKey('quota_bonus_percent', $before);
        $this->assertArrayHasKey('quota_duration_multiplier', $before);

        $changed = app(SubscriptionService::class)->backfillCheckoutSnapshotQuotaTiming($sub->fresh());
        $this->assertFalse($changed);
        $this->assertSame($before['quota_bonus_percent'], $sub->fresh()->checkoutSnapshot()['quota_bonus_percent']);
    }

    public function test_plan_term_edit_does_not_change_existing_subscription_limits(): void
    {
        $plan = $this->makePaidPlan(bonus: 10);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $this->assertSame(10, $this->bonusViaReflection($sub));

        $term->update(['quota_bonus_percent' => 90]);
        $this->assertSame(10, $this->bonusViaReflection($sub->fresh()));
        $this->assertSame(90, (int) $term->fresh()->quota_bonus_percent);
    }

    public function test_new_purchase_gets_current_plan_term_bonus(): void
    {
        $plan = $this->makePaidPlan(bonus: 5);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();
        $term->update(['quota_bonus_percent' => 25]);

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term->fresh());
        $this->assertSame(25, (int) $sub->checkoutSnapshot()['quota_bonus_percent']);
        $this->assertSame(25, $this->bonusViaReflection($sub));
    }

    public function test_missing_snapshot_keys_use_safe_defaults_not_live_plan_term(): void
    {
        Log::spy();
        $this->clearMissingQuotaTimingLogCache();

        $plan = $this->makePaidPlan(bonus: 80);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        // Complete quota_policies (required for paid contract) but omit quota timing keys.
        $sub = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $term->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'grace_period_days' => 0,
            'meta' => [
                'checkout_snapshot' => array_merge(
                    PlanQuotaCheckoutSnapshot::forPlan($plan),
                    [
                        'plan_term_id' => (int) $term->id,
                    ],
                ),
            ],
        ]);

        $this->assertSame(0, $this->bonusViaReflection($sub));
        $this->assertSame(1.0, $this->multViaReflection($sub));

        // Laravel 12 has no Log::fake/assertLogged — mirror MobileAuthRegistrationTest pattern.
        Log::shouldHaveReceived('warning')
            ->twice()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'subscription_checkout_snapshot_missing_quota_timing'
                    && in_array($context['missing_key'] ?? null, ['quota_bonus_percent', 'quota_duration_multiplier'], true);
            });
    }

    private function clearMissingQuotaTimingLogCache(): void
    {
        $prop = new \ReflectionProperty(SubscriptionService::class, 'loggedMissingCheckoutQuotaTiming');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    private function bonusViaReflection(Subscription $sub): int
    {
        $m = new ReflectionMethod(SubscriptionService::class, 'quotaBonusPercentForSubscription');
        $m->setAccessible(true);

        return (int) $m->invoke(app(SubscriptionService::class), $sub);
    }

    private function multViaReflection(Subscription $sub): float
    {
        $m = new ReflectionMethod(SubscriptionService::class, 'quotaDurationMultiplierForSubscription');
        $m->setAccessible(true);

        return (float) $m->invoke(app(SubscriptionService::class), $sub);
    }

    private function makePaidPlan(int $bonus): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Quota Timing Plan',
            'slug' => 'quota_timing_'.uniqid(),
            'price' => 999,
            'selling_price' => 999,
            'duration_days' => 30,
            'is_active' => true,
            'is_visible' => true,
            'sort_order' => 10,
            'highlight' => false,
            'applies_to_gender' => 'all',
            'gst_inclusive' => true,
            'grace_period_days' => 0,
            'leftover_quota_carry_window_days' => null,
            'default_billing_key' => PlanTerm::BILLING_MONTHLY,
        ]);

        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            PlanQuotaPolicy::query()->create(array_merge(
                [
                    'plan_id' => $plan->id,
                    'feature_key' => $featureKey,
                ],
                PlanQuotaPolicy::defaultsForNewPlan($featureKey),
            ));
        }

        PlanTerm::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'billing_key' => PlanTerm::BILLING_MONTHLY],
            [
                'duration_days' => 30,
                'price' => 999,
                'selling_price' => 999,
                'quota_bonus_percent' => $bonus,
                'is_visible' => true,
                'sort_order' => 10,
            ]
        );

        return $plan->fresh(['terms', 'quotaPolicies']);
    }
}
