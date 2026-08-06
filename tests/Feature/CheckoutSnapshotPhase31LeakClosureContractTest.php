<?php

namespace Tests\Feature;

use App\Exceptions\QuotaPolicySourceViolation;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Services\EntitlementService;
use App\Services\FeatureUsageService;
use App\Services\PlanQuotaCheckoutSnapshot;
use App\Services\PlanQuotaUiSource;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 3.1: final contract leak closure — existing paid members must not read live catalog.
 */
class CheckoutSnapshotPhase31LeakClosureContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_who_viewed_refresh_type_unaffected_by_live_plan_edit(): void
    {
        $plan = $this->makePaidPlan();
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $whoKey = PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT;
        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', $whoKey)
            ->update(['refresh_type' => PlanQuotaPolicy::REFRESH_WEEKLY]);

        $sub = app(SubscriptionService::class)->createSubscription(
            $user,
            $plan->fresh(['quotaPolicies', 'features']),
            $term
        );

        $payload = $sub->checkoutSnapshot()['quota_policies'][$whoKey];
        $this->assertSame(PlanQuotaPolicy::REFRESH_WEEKLY, $payload['refresh_type']);

        $windowBefore = app(FeatureUsageService::class)->whoViewedMePreviewWindow($user);
        $this->assertNotNull($windowBefore['since']);
        $this->assertFalse($windowBefore['uses_month_copy']);

        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', $whoKey)
            ->update(['refresh_type' => PlanQuotaPolicy::REFRESH_DAILY]);

        $windowAfter = app(FeatureUsageService::class)->whoViewedMePreviewWindow($user);
        $this->assertEquals(
            $windowBefore['since']?->toDateTimeString(),
            $windowAfter['since']?->toDateTimeString()
        );
        $this->assertSame($windowBefore['uses_month_copy'], $windowAfter['uses_month_copy']);
        $this->assertSame(
            PlanQuotaPolicy::REFRESH_DAILY,
            PlanQuotaPolicy::query()->where('plan_id', $plan->id)->where('feature_key', $whoKey)->value('refresh_type')
        );
    }

    public function test_plan_quota_ui_source_fail_closed_for_paid_sub_missing_snapshot(): void
    {
        Log::spy();

        $plan = $this->makePaidPlan();
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

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
                    ['plan_term_id' => (int) $term->id],
                ),
            ],
        ]);

        $sub->meta = [
            'checkout_snapshot' => [
                'plan_term_id' => (int) $term->id,
            ],
        ];
        $sub->save();

        $this->expectException(QuotaPolicySourceViolation::class);
        PlanQuotaUiSource::policyPayloadsForUser($user->fresh());
    }

    public function test_plan_quota_ui_source_fail_closed_for_incomplete_quota_policies(): void
    {
        $plan = $this->makePaidPlan();
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

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
                    ['features' => []],
                ),
            ],
        ]);

        $qp = $sub->checkoutSnapshot()['quota_policies'];
        unset($qp[PlanFeatureKeys::CHAT_CAN_READ]);
        $meta = $sub->meta;
        $meta['checkout_snapshot']['quota_policies'] = $qp;
        $sub->meta = $meta;
        $sub->save();

        $this->expectException(QuotaPolicySourceViolation::class);
        PlanQuotaUiSource::policyPayloadsForUser($user->fresh());
    }

    public function test_chat_can_read_not_skipped_when_live_quota_row_removed(): void
    {
        $plan = $this->makePaidPlan();
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $snap = PlanQuotaCheckoutSnapshot::forPlan($plan);
        $snap['quota_policies'][PlanFeatureKeys::CHAT_CAN_READ]['is_enabled'] = false;
        $snap['quota_policies'][PlanFeatureKeys::CHAT_CAN_READ]['limit_value'] = 0;

        $sub = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $term->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'grace_period_days' => 0,
            'meta' => ['checkout_snapshot' => PlanQuotaCheckoutSnapshot::forPlan($plan)],
        ]);

        // Overwrite frozen contract after create hook so entitlements still exist.
        $meta = $sub->meta;
        $meta['checkout_snapshot'] = $snap;
        $sub->meta = $meta;
        $sub->save();
        app(EntitlementService::class)->assignFromSubscription($sub->fresh());

        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', PlanFeatureKeys::CHAT_CAN_READ)
            ->delete();

        $allowed = app(FeatureUsageService::class)->canUse(
            (int) $user->id,
            FeatureUsageService::FEATURE_CHAT_CAN_READ
        );
        $this->assertFalse($allowed);
    }

    public function test_carry_leftover_does_not_use_live_plan_when_previous_snap_incomplete(): void
    {
        Log::spy();

        $plan = $this->makePaidPlan();
        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', PlanFeatureKeys::CHAT_SEND_LIMIT)
            ->update(['limit_value' => 500, 'is_enabled' => true]);

        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $term->id,
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subDay(),
            'status' => Subscription::STATUS_ACTIVE,
            'grace_period_days' => 7,
            'leftover_quota_carry_window_days' => 14,
            'meta' => [
                'checkout_snapshot' => array_merge(
                    PlanQuotaCheckoutSnapshot::forPlan($plan),
                    [
                        'plan_term_id' => (int) $term->id,
                        'quota_bonus_percent' => 0,
                        'quota_duration_multiplier' => 1.0,
                    ],
                ),
            ],
        ]);
        $sub->update([
            'status' => Subscription::STATUS_EXPIRED,
            'meta' => [
                'checkout_snapshot' => [
                    'plan_term_id' => (int) $term->id,
                    'quota_bonus_percent' => 0,
                    'quota_duration_multiplier' => 1.0,
                ],
            ],
        ]);

        $m = new ReflectionMethod(SubscriptionService::class, 'resolveCarryQuotaFromPreviousSubscription');
        $m->setAccessible(true);
        $carry = $m->invoke(app(SubscriptionService::class), $user, now());

        $this->assertSame([], $carry);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message): bool {
                return $message === 'subscription_checkout_snapshot_missing_quota_policies_for_carry';
            });
    }

    public function test_chat_image_send_respects_snapshot_feature_after_plan_feature_edit(): void
    {
        $plan = $this->makePaidPlan([
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
        ]);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $this->assertTrue(app(SubscriptionService::class)->canUseChatImages($user));
        $this->assertTrue(
            UserEntitlement::query()
                ->where('user_id', $user->id)
                ->where('entitlement_key', SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES)
                ->whereNull('revoked_at')
                ->exists()
        );

        PlanFeature::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'key' => SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES],
            ['value' => '0']
        );
        Plan::forgetCachedPlanFeaturesByPlanId((int) $plan->id);

        // Entitlement row still exists, but frozen snapshot value stays '1'.
        $this->assertTrue(app(SubscriptionService::class)->canUseChatImages($user));

        // Flip the frozen contract to 0 — gate must follow snapshot, not live catalog / entitlement alone.
        $sub = app(SubscriptionService::class)->getActiveSubscription($user);
        $meta = $sub->meta;
        $meta['checkout_snapshot']['features'][SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES] = '0';
        $sub->meta = $meta;
        $sub->save();

        $this->assertFalse(app(SubscriptionService::class)->canUseChatImages($user->fresh()));
        $this->assertTrue(
            UserEntitlement::query()
                ->where('user_id', $user->id)
                ->where('entitlement_key', SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES)
                ->whereNull('revoked_at')
                ->exists(),
            'entitlement row alone must not authorize chat images'
        );
    }

    public function test_assign_from_subscription_key_set_from_snapshot_not_live_plan_features(): void
    {
        $plan = $this->makePaidPlan([
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
            PlanFeatureKeys::BIODATA_EXPORT_LIMIT => '10',
        ]);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $snap = PlanQuotaCheckoutSnapshot::forPlan($plan);
        unset($snap['features'][PlanFeatureKeys::BIODATA_EXPORT_LIMIT]);

        $sub = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $term->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'grace_period_days' => 0,
            'meta' => ['checkout_snapshot' => PlanQuotaCheckoutSnapshot::forPlan($plan)],
        ]);

        $meta = $sub->meta;
        $meta['checkout_snapshot'] = $snap;
        $sub->meta = $meta;
        $sub->save();

        PlanFeature::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'key' => 'brand_new_catalog_only_feature'],
            ['value' => '1']
        );

        // Clear entitlements from create hook, then re-assign from edited snapshot.
        UserEntitlement::query()->where('user_id', $user->id)->delete();
        app(EntitlementService::class)->assignFromSubscription($sub->fresh());

        $keys = UserEntitlement::query()
            ->where('user_id', $user->id)
            ->pluck('entitlement_key')
            ->all();

        $this->assertContains(SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES, $keys);
        $this->assertNotContains('brand_new_catalog_only_feature', $keys);
        $this->assertNotContains(PlanFeatureKeys::BIODATA_EXPORT_LIMIT, $keys);
    }

    public function test_timing_soft_defaults_still_zero_and_one(): void
    {
        Log::spy();
        $prop = new \ReflectionProperty(SubscriptionService::class, 'loggedMissingCheckoutQuotaTiming');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $plan = $this->makePaidPlan();
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

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
                    ['plan_term_id' => (int) $term->id],
                ),
            ],
        ]);

        $bonus = new ReflectionMethod(SubscriptionService::class, 'quotaBonusPercentForSubscription');
        $bonus->setAccessible(true);
        $mult = new ReflectionMethod(SubscriptionService::class, 'quotaDurationMultiplierForSubscription');
        $mult->setAccessible(true);

        $svc = app(SubscriptionService::class);
        $this->assertSame(0, (int) $bonus->invoke($svc, $sub));
        $this->assertSame(1.0, (float) $mult->invoke($svc, $sub));

        Log::shouldHaveReceived('warning')
            ->twice()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'subscription_checkout_snapshot_missing_quota_timing'
                    && in_array($context['missing_key'] ?? null, ['quota_bonus_percent', 'quota_duration_multiplier'], true)
                    && ($context['fallback'] === 0 || $context['fallback'] === 1.0);
            });
    }

    /**
     * @param  array<string, string>  $features
     */
    private function makePaidPlan(array $features = []): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Phase31 Plan',
            'slug' => 'phase31_'.uniqid(),
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
                'quota_bonus_percent' => 0,
                'is_visible' => true,
                'sort_order' => 10,
            ]
        );

        foreach ($features as $key => $value) {
            PlanFeature::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'key' => $key],
                ['value' => $value],
            );
        }

        return $plan->fresh(['terms', 'quotaPolicies', 'features']);
    }
}
