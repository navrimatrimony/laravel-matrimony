<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanQuotaCheckoutSnapshot;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionQuotaBonusTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_quota_bonus_applies_only_to_refresh_limits(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlanWithQuotaPolicies();
        $term = PlanTerm::query()->create([
            'plan_id' => $plan->id,
            'billing_key' => PlanTerm::BILLING_HALF_YEARLY,
            'duration_days' => PlanTerm::durationDaysFor(PlanTerm::BILLING_HALF_YEARLY),
            'price' => 2400,
            'discount_percent' => 20,
            'quota_bonus_percent' => 10,
            'is_visible' => true,
            'sort_order' => PlanTerm::defaultSortOrder(PlanTerm::BILLING_HALF_YEARLY),
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $term->id,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addMonths(6),
            'status' => Subscription::STATUS_ACTIVE,
            'meta' => [
                'checkout_snapshot' => array_merge(
                    PlanQuotaCheckoutSnapshot::forPlan($plan),
                    [
                        'plan_term_id' => (int) $term->id,
                        'billing_key' => (string) $term->billing_key,
                        'quota_bonus_percent' => 10,
                    ],
                ),
            ],
        ]);

        $service = app(SubscriptionService::class);

        $this->assertSame(22, $service->getFeatureLimit($user, PlanFeatureKeys::CHAT_SEND_LIMIT));
        $this->assertSame(110, $service->getFeatureLimit($user, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH));
        $this->assertSame(10, $service->getFeatureLimit($user, PlanFeatureKeys::INTEREST_VIEW_LIMIT));
        $this->assertSame(-1, $service->getFeatureLimit($user, SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT));
        $this->assertSame(0, $service->getFeatureLimit($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT));
    }

    private function createPlanWithQuotaPolicies(): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Quota Bonus Plan',
            'slug' => 'quota-bonus-plan',
            'price' => 500,
            'discount_percent' => null,
            'duration_days' => 30,
            'default_billing_key' => PlanTerm::BILLING_HALF_YEARLY,
            'grace_period_days' => 0,
            'leftover_quota_carry_window_days' => null,
            'is_active' => true,
            'is_visible' => true,
            'sort_order' => 1,
            'highlight' => false,
            'applies_to_gender' => 'all',
            'gst_inclusive' => true,
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

        $this->setQuota($plan, PlanFeatureKeys::CHAT_SEND_LIMIT, PlanQuotaPolicy::REFRESH_DAILY, 20);
        $this->setQuota($plan, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH, PlanQuotaPolicy::REFRESH_WEEKLY, 100);
        $this->setQuota($plan, PlanFeatureKeys::INTEREST_VIEW_LIMIT, PlanQuotaPolicy::REFRESH_LIFETIME, 10);
        $this->setQuota($plan, PlanFeatureKeys::CONTACT_VIEW_LIMIT, PlanQuotaPolicy::REFRESH_DAILY, 0);
        $this->setQuota($plan, SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT, PlanQuotaPolicy::REFRESH_UNLIMITED, null);

        return $plan->fresh(['quotaPolicies']);
    }

    private function setQuota(Plan $plan, string $featureKey, string $refreshType, ?int $limit): void
    {
        PlanQuotaPolicy::query()
            ->where('plan_id', $plan->id)
            ->where('feature_key', $featureKey)
            ->update([
                'is_enabled' => true,
                'refresh_type' => $refreshType,
                'limit_value' => $refreshType === PlanQuotaPolicy::REFRESH_UNLIMITED ? null : max(0, (int) $limit),
            ]);
    }
}
