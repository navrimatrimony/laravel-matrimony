<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\PlanSubscriptionTerms;
use App\Services\QuotaEngineService;
use App\Services\SubscriptionService;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 2 Checkpoint B: runtime grace/carry readers use subscription contract columns.
 */
class SubscriptionContractTimingReadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_and_grace_window_use_subscription_columns_not_live_plan(): void
    {
        $plan = $this->makePaidPlan(grace: 5, carry: null);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $sub->forceFill([
            'ends_at' => now()->subDay(),
            'grace_period_days' => 5,
        ])->save();

        $plan->update(['grace_period_days' => 0]);

        $this->assertTrue($sub->fresh()->isActiveNow());
        $this->assertNotNull(
            Subscription::queryAuthoritativeAccessForUser($user)->first()
        );

        $sub->forceFill(['grace_period_days' => 0])->save();
        $this->assertFalse($sub->fresh()->isActiveNow());
        $this->assertNull(
            Subscription::queryAuthoritativeAccessForUser($user)->first()
        );
    }

    public function test_expire_subscriptions_uses_frozen_grace_not_plan(): void
    {
        $plan = $this->makePaidPlan(grace: 30, carry: null);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $sub->forceFill([
            'ends_at' => now()->subDays(3),
            'grace_period_days' => 1,
            'status' => Subscription::STATUS_ACTIVE,
        ])->save();

        // Live catalog still says long grace — must not keep the row active.
        $this->assertSame(30, (int) $plan->fresh()->grace_period_days);

        $expired = app(SubscriptionService::class)->expireSubscriptions();
        $this->assertGreaterThanOrEqual(1, $expired);
        $this->assertSame(Subscription::STATUS_EXPIRED, (string) $sub->fresh()->status);
    }

    public function test_carry_window_uses_subscription_carry_column(): void
    {
        $plan = $this->makePaidPlan(grace: 2, carry: 5);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $sub->forceFill([
            'ends_at' => now()->subDays(4),
            'grace_period_days' => 2,
            'leftover_quota_carry_window_days' => 5,
        ])->save();

        $plan->update(['leftover_quota_carry_window_days' => null]);

        $method = new ReflectionMethod(SubscriptionService::class, 'isWithinGraceOrCarryWindow');
        $method->setAccessible(true);
        $svc = app(SubscriptionService::class);

        $this->assertTrue($method->invoke($svc, $sub->fresh(), now()));

        $sub->forceFill(['leftover_quota_carry_window_days' => 0])->save();
        $this->assertFalse($method->invoke($svc, $sub->fresh(), now()));
    }

    public function test_renew_refreezes_and_plan_edit_does_not_change_existing_access(): void
    {
        $plan = $this->makePaidPlan(grace: 3, carry: 7);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $this->assertSame(3, (int) $sub->grace_period_days);

        $plan->update(['grace_period_days' => 99, 'leftover_quota_carry_window_days' => 1]);
        $sub->refresh();
        $this->assertSame(3, (int) $sub->grace_period_days);
        $this->assertSame(7, (int) $sub->leftover_quota_carry_window_days);

        $renewed = app(SubscriptionService::class)->renewSubscription($user, $term->fresh());
        $this->assertSame(99, (int) $renewed->grace_period_days);
        $this->assertSame(1, (int) $renewed->leftover_quota_carry_window_days);
    }

    public function test_backfilled_row_drives_quota_summary_grace_not_live_plan(): void
    {
        $plan = $this->makePaidPlan(grace: 14, carry: 10);
        $user = User::factory()->create();
        \App\Models\MatrimonyProfile::factory()->create(['user_id' => $user->id]);
        $user->refresh();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        Subscription::query()->whereKey($sub->id)->update([
            'grace_period_days' => 4,
            'leftover_quota_carry_window_days' => 2,
        ]);
        $plan->update(['grace_period_days' => 50, 'leftover_quota_carry_window_days' => 40]);

        $summary = app(QuotaEngineService::class)->getUserQuotaSummary($user->fresh());
        $this->assertNotNull($summary);
        $this->assertSame(4, (int) ($summary['plan_grace_period_days'] ?? -1));
        $this->assertSame(2, (int) ($summary['plan_carry_window_days'] ?? -1));
    }

    public function test_assign_from_subscription_uses_frozen_grace_for_valid_until(): void
    {
        $plan = $this->makePaidPlan(grace: 7, carry: null);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $ends = now()->addDays(10)->startOfSecond();
        $sub->forceFill([
            'ends_at' => $ends,
            'grace_period_days' => 2,
        ])->save();
        $plan->update(['grace_period_days' => 20]);

        $fresh = $sub->fresh();
        app(EntitlementService::class)->assignFromSubscription($fresh);

        $ent = \App\Models\UserEntitlement::query()
            ->where('user_id', $user->id)
            ->whereNotNull('valid_until')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($ent);
        $expected = $fresh->ends_at->copy()->addDays(2);
        $this->assertSame(
            $expected->getTimestamp(),
            $ent->valid_until->getTimestamp(),
            'valid_until must use subscription grace (2), not live plan (20)'
        );
    }

    private function makePaidPlan(int $grace, ?int $carry): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Contract Reader Plan',
            'slug' => 'contract_reader_'.uniqid(),
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
