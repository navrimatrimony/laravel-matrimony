<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\PlanQuotaCheckoutSnapshot;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3B: non-quota PlanFeature contract lives in checkout_snapshot.features only for existing subs.
 */
class CheckoutSnapshotFeaturesContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_freezes_non_quota_features_into_checkout_snapshot(): void
    {
        $plan = $this->makePaidPlan([
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
            PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES => '1',
            PlanFeatureKeys::BIODATA_EXPORT_LIMIT => '20',
        ]);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $features = $sub->checkoutSnapshot()['features'] ?? null;

        $this->assertIsArray($features);
        $this->assertSame('1', $features[SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES] ?? null);
        $this->assertSame('1', $features[PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES] ?? null);
        $this->assertSame('20', $features[PlanFeatureKeys::BIODATA_EXPORT_LIMIT] ?? null);
        $this->assertArrayNotHasKey(PlanFeatureKeys::CHAT_SEND_LIMIT, $features);
    }

    public function test_existing_subscription_unaffected_by_plan_feature_edit_after_purchase(): void
    {
        $plan = $this->makePaidPlan([
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
            PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES => '1',
            PlanFeatureKeys::BIODATA_EXPORT_LIMIT => '10',
        ]);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        app(SubscriptionService::class)->createSubscription($user, $plan, $term);

        $subs = app(SubscriptionService::class);
        $ents = app(EntitlementService::class);

        $this->assertTrue($subs->hasFeature($user, 'chat_images'));
        $this->assertTrue($ents->hasFeature((int) $user->id, PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES));
        $this->assertSame(10, $subs->getFeatureLimit($user, PlanFeatureKeys::BIODATA_EXPORT_LIMIT));

        $this->setPlanFeature($plan, SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES, '0');
        $this->setPlanFeature($plan, PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES, '0');
        $this->setPlanFeature($plan, PlanFeatureKeys::BIODATA_EXPORT_LIMIT, '99');
        Plan::forgetCachedPlanFeaturesByPlanId((int) $plan->id);

        $this->assertTrue($subs->hasFeature($user, 'chat_images'));
        $this->assertTrue($ents->hasFeature((int) $user->id, PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES));
        $this->assertSame(10, $subs->getFeatureLimit($user, PlanFeatureKeys::BIODATA_EXPORT_LIMIT));
        $this->assertSame('0', $plan->fresh()->featureValue(SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES));
        $this->assertSame('99', $plan->fresh()->featureValue(PlanFeatureKeys::BIODATA_EXPORT_LIMIT));
    }

    public function test_new_purchase_receives_current_catalog_feature_values(): void
    {
        $plan = $this->makePaidPlan([
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '0',
            PlanFeatureKeys::BIODATA_EXPORT_LIMIT => '5',
        ]);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $this->setPlanFeature($plan, SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES, '1');
        $this->setPlanFeature($plan, PlanFeatureKeys::BIODATA_EXPORT_LIMIT, '40');
        Plan::forgetCachedPlanFeaturesByPlanId((int) $plan->id);

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan->fresh(['features', 'quotaPolicies']), $term);
        $features = $sub->checkoutSnapshot()['features'];

        $this->assertSame('1', $features[SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES]);
        $this->assertSame('40', $features[PlanFeatureKeys::BIODATA_EXPORT_LIMIT]);
        $this->assertTrue(app(SubscriptionService::class)->hasFeature($user, 'chat_images'));
        $this->assertSame(40, app(SubscriptionService::class)->getFeatureLimit($user, PlanFeatureKeys::BIODATA_EXPORT_LIMIT));
    }

    public function test_backfill_writes_missing_features_map_from_plan(): void
    {
        $plan = $this->makePaidPlan([
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
            PlanFeatureKeys::BIODATA_EXPORT_LIMIT => '7',
        ]);
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
                    ['quota_policies' => PlanQuotaCheckoutSnapshot::forPlan($plan)['quota_policies']],
                    [
                        'plan_term_id' => (int) $term->id,
                        'plan_name' => $plan->name,
                    ],
                ),
            ],
        ]);

        $this->assertArrayNotHasKey('features', $sub->checkoutSnapshot());

        $changed = app(SubscriptionService::class)->backfillCheckoutSnapshotFeatures($sub->fresh());
        $this->assertTrue($changed);

        $features = $sub->fresh()->checkoutSnapshot()['features'];
        $this->assertSame('1', $features[SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES]);
        $this->assertSame('7', $features[PlanFeatureKeys::BIODATA_EXPORT_LIMIT]);
    }

    public function test_complete_features_snapshot_unchanged_by_backfill(): void
    {
        $plan = $this->makePaidPlan([
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
        ]);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $before = $sub->checkoutSnapshot()['features'];

        $changed = app(SubscriptionService::class)->backfillCheckoutSnapshotFeatures($sub->fresh());
        $this->assertFalse($changed);
        $this->assertSame($before, $sub->fresh()->checkoutSnapshot()['features']);
    }

    /**
     * @param  array<string, string>  $features
     */
    private function makePaidPlan(array $features): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Features Contract Plan',
            'slug' => 'features_contract_'.uniqid(),
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
            $this->setPlanFeature($plan, $key, $value);
        }

        return $plan->fresh(['terms', 'quotaPolicies', 'features']);
    }

    private function setPlanFeature(Plan $plan, string $key, string $value): void
    {
        PlanFeature::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'key' => $key],
            ['value' => $value],
        );
    }
}
