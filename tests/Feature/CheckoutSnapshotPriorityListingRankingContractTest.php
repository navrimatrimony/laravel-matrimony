<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\User;
use App\Services\ProfileSearchRankingService;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3C: member search priority ranking uses checkout_snapshot.quota_policies, not live plan_quota_policies.
 */
class CheckoutSnapshotPriorityListingRankingContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_member_ranking_unaffected_by_plan_quota_policy_edit(): void
    {
        $plan = $this->makePaidPlan(priorityEnabled: true);
        $priorityUser = User::factory()->create();
        $plainUser = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        app(SubscriptionService::class)->createSubscription($priorityUser, $plan, $term);

        $priorityProfile = MatrimonyProfile::factory()->for($priorityUser)->create([
            'lifecycle_state' => 'draft',
        ]);
        $plainProfile = MatrimonyProfile::factory()->for($plainUser)->create([
            'lifecycle_state' => 'draft',
        ]);

        $before = $this->spotlightOrderedIds([$priorityProfile->id, $plainProfile->id]);
        $this->assertSame([(int) $priorityProfile->id, (int) $plainProfile->id], $before);

        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', PlanFeatureKeys::PRIORITY_LISTING)
            ->update(['is_enabled' => false]);

        $after = $this->spotlightOrderedIds([$priorityProfile->id, $plainProfile->id]);
        $this->assertSame($before, $after);
        $this->assertFalse((bool) $plan->fresh()->quotaPolicies
            ->firstWhere('feature_key', PlanFeatureKeys::PRIORITY_LISTING)
            ?->is_enabled);
        $this->assertTrue((bool) ($priorityUser->fresh()->subscriptions()->first()?->checkoutSnapshot()
            ['quota_policies'][PlanFeatureKeys::PRIORITY_LISTING]['is_enabled'] ?? false));
    }

    public function test_live_catalog_priority_does_not_promote_existing_subscription_without_contract(): void
    {
        $plan = $this->makePaidPlan(priorityEnabled: false);
        $user = User::factory()->create();
        $plainUser = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $this->assertFalse((bool) ($sub->checkoutSnapshot()
            ['quota_policies'][PlanFeatureKeys::PRIORITY_LISTING]['is_enabled'] ?? true));

        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', PlanFeatureKeys::PRIORITY_LISTING)
            ->update(['is_enabled' => true]);

        $withContractOff = MatrimonyProfile::factory()->for($user)->create([
            'lifecycle_state' => 'draft',
        ]);
        $plainProfile = MatrimonyProfile::factory()->for($plainUser)->create([
            'lifecycle_state' => 'draft',
        ]);

        // Stable secondary id order: without contract priority, plain (lower id) may sort first
        // after the shared spotlight bucket — both should share non-spotlight rank.
        $ordered = $this->spotlightOrderedIds([$withContractOff->id, $plainProfile->id]);
        $this->assertSame(
            collect([$withContractOff->id, $plainProfile->id])->sort()->values()->all(),
            $ordered,
            'Existing member must not gain ranking from live plan_quota_policies edit'
        );
    }

    public function test_new_purchase_receives_current_catalog_priority_listing(): void
    {
        $plan = $this->makePaidPlan(priorityEnabled: false);
        $term = $plan->terms()->firstOrFail();

        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', PlanFeatureKeys::PRIORITY_LISTING)
            ->update(['is_enabled' => true]);

        $user = User::factory()->create();
        $plainUser = User::factory()->create();
        $sub = app(SubscriptionService::class)->createSubscription(
            $user,
            $plan->fresh(['quotaPolicies', 'features']),
            $term
        );

        $this->assertTrue((bool) ($sub->checkoutSnapshot()
            ['quota_policies'][PlanFeatureKeys::PRIORITY_LISTING]['is_enabled'] ?? false));

        $priorityProfile = MatrimonyProfile::factory()->for($user)->create([
            'lifecycle_state' => 'draft',
        ]);
        $plainProfile = MatrimonyProfile::factory()->for($plainUser)->create([
            'lifecycle_state' => 'draft',
        ]);

        $this->assertSame(
            [(int) $priorityProfile->id, (int) $plainProfile->id],
            $this->spotlightOrderedIds([$priorityProfile->id, $plainProfile->id])
        );
    }

    public function test_backfill_writes_missing_priority_listing_into_quota_policies(): void
    {
        $plan = $this->makePaidPlan(priorityEnabled: true);
        $user = User::factory()->create();
        $term = $plan->terms()->firstOrFail();

        $sub = app(SubscriptionService::class)->createSubscription($user, $plan, $term);
        $meta = is_array($sub->meta) ? $sub->meta : [];
        $snap = is_array($meta['checkout_snapshot'] ?? null) ? $meta['checkout_snapshot'] : [];
        $qp = is_array($snap['quota_policies'] ?? null) ? $snap['quota_policies'] : [];
        unset($qp[PlanFeatureKeys::PRIORITY_LISTING]);
        $snap['quota_policies'] = $qp;
        $meta['checkout_snapshot'] = $snap;
        $sub->meta = $meta;
        $sub->save();

        $this->assertArrayNotHasKey(
            PlanFeatureKeys::PRIORITY_LISTING,
            $sub->fresh()->checkoutSnapshot()['quota_policies'] ?? []
        );

        $changed = app(SubscriptionService::class)->backfillCheckoutSnapshotPriorityListing($sub->fresh());
        $this->assertTrue($changed);
        $this->assertTrue((bool) ($sub->fresh()->checkoutSnapshot()
            ['quota_policies'][PlanFeatureKeys::PRIORITY_LISTING]['is_enabled'] ?? false));

        $this->assertFalse(
            app(SubscriptionService::class)->backfillCheckoutSnapshotPriorityListing($sub->fresh())
        );
    }

    public function test_ranking_sql_does_not_reference_live_plan_quota_policies(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProfileSearchRankingService::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('plan_quota_policies', $source);
        $this->assertStringContainsString('checkout_snapshot.quota_policies', $source);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function spotlightOrderedIds(array $ids): array
    {
        $query = MatrimonyProfile::query()->whereIn('id', $ids);
        ProfileSearchRankingService::applySpotlightFirst($query);
        $query->orderBy('id');

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function makePaidPlan(bool $priorityEnabled): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Priority Ranking Plan',
            'slug' => 'priority_ranking_'.uniqid(),
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
            $defaults = PlanQuotaPolicy::defaultsForNewPlan($featureKey);
            if ($featureKey === PlanFeatureKeys::PRIORITY_LISTING) {
                $defaults['is_enabled'] = $priorityEnabled;
            }
            PlanQuotaPolicy::query()->create(array_merge(
                [
                    'plan_id' => $plan->id,
                    'feature_key' => $featureKey,
                ],
                $defaults,
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

        return $plan->fresh(['terms', 'quotaPolicies', 'features']);
    }
}
