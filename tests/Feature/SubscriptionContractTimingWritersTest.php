<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanSubscriptionTerms;
use App\Services\SubscriptionService;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2 Checkpoint A: contract columns written at purchase/renew; readers still use plans.
 */
class SubscriptionContractTimingWritersTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_columns_exist_and_backfill_copies_from_plan(): void
    {
        $this->assertTrue(Schema::hasColumn('subscriptions', 'grace_period_days'));
        $this->assertTrue(Schema::hasColumn('subscriptions', 'leftover_quota_carry_window_days'));

        $plan = $this->makePaidPlan(grace: 14, carry: 7);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        // Insert without explicit contract columns (DB default grace=0) then prove backfill path via helper+update.
        $subId = Subscription::query()->insertGetId([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $term->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
            'status' => Subscription::STATUS_ACTIVE,
            'grace_period_days' => 0,
            'leftover_quota_carry_window_days' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulate migration backfill for this row (same semantics as migration UPDATE JOIN).
        Subscription::query()->whereKey($subId)->update(
            PlanSubscriptionTerms::contractTimingAttributesFromPlan($plan->fresh())
        );

        $sub = Subscription::query()->findOrFail($subId);
        $this->assertSame(14, (int) $sub->grace_period_days);
        $this->assertSame(7, (int) $sub->leftover_quota_carry_window_days);
    }

    public function test_create_subscription_freezes_grace_and_carry_from_plan(): void
    {
        $plan = $this->makePaidPlan(grace: 10, carry: 30);
        $user = User::factory()->create();
        $term = $plan->terms()->where('billing_key', PlanTerm::BILLING_MONTHLY)->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);

        $this->assertSame(10, (int) $sub->grace_period_days);
        $this->assertSame(30, (int) $sub->leftover_quota_carry_window_days);

        // Checkpoint A: runtime still reads catalog (plan), not the frozen columns.
        $this->assertSame(10, PlanSubscriptionTerms::gracePeriodDays($plan));
        $this->assertSame(10, PlanSubscriptionTerms::gracePeriodDays($sub->plan()->first()));
    }

    public function test_renew_subscription_refreezes_timing_from_current_plan(): void
    {
        $plan = $this->makePaidPlan(grace: 3, carry: null);
        $user = User::factory()->create();
        $term = $plan->terms()->where('billing_key', PlanTerm::BILLING_MONTHLY)->firstOrFail();

        $existing = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $this->assertSame(3, (int) $existing->grace_period_days);
        $this->assertNull($existing->leftover_quota_carry_window_days);

        $plan->update([
            'grace_period_days' => 21,
            'leftover_quota_carry_window_days' => 14,
        ]);

        $renewed = app(SubscriptionService::class)->renewSubscription($user, $term->fresh());

        $this->assertSame((int) $existing->id, (int) $renewed->id);
        $this->assertSame(21, (int) $renewed->grace_period_days);
        $this->assertSame(14, (int) $renewed->leftover_quota_carry_window_days);
    }

    public function test_helper_does_not_invent_duplicate_timing_keys(): void
    {
        $plan = $this->makePaidPlan(grace: 5, carry: 0);
        $attrs = PlanSubscriptionTerms::contractTimingAttributesFromPlan($plan);

        $this->assertSame(['grace_period_days', 'leftover_quota_carry_window_days'], array_keys($attrs));
        $this->assertSame(5, $attrs['grace_period_days']);
        $this->assertSame(0, $attrs['leftover_quota_carry_window_days']);
    }

    private function makePaidPlan(int $grace, ?int $carry): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Contract Timing Plan',
            'slug' => 'contract_timing_'.uniqid(),
            'price' => 999,
            'selling_price' => 999,
            'duration_days' => 30,
            'is_active' => true,
            'is_visible' => true,
            'sort_order' => 10,
            'highlight' => false,
            'applies_to_gender' => 'all',
            'gst_inclusive' => true,
            'grace_period_days' => $grace,
            'leftover_quota_carry_window_days' => $carry,
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
                'quota_bonus_percent' => 0,
                'is_visible' => true,
                'sort_order' => 10,
            ]
        );

        return $plan->fresh(['terms', 'quotaPolicies']);
    }
}
