<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 3 — upgrade / renewal **coordination** (no new pricing rules, no direct quota writes).
 *
 * Carry and quota snapshots are SSOT via {@see QuotaEngineService::applyPlan} → {@see SubscriptionService::resolveCarryQuotaSnapshotForCheckout}.
 * Payable amounts use {@see RevenueOrchestratorService::prepareCheckout} → {@see SubscriptionService::resolvePaidPlanCheckout}.
 *
 * Scheduled downgrade is structured only; execution is not implemented.
 */
class SubscriptionUpgradeService
{
    public const TYPE_IMMEDIATE_UPGRADE = 'immediate_upgrade';

    public const TYPE_SCHEDULED_DOWNGRADE_NEXT_CYCLE = 'scheduled_downgrade_next_cycle';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly QuotaEngineService $quotaEngine,
        private readonly RevenueOrchestratorService $revenueOrchestrator,
    ) {}

    /**
     * Read-only hints for /my-plan (no pricing math beyond optional snapshot read).
     *
     * @return array{
     *     upgrade_available: bool,
     *     upgrade_cta_route: string,
     *     current_plan_name: string|null,
     *     immediate_upgrade_type: string
     * }
     */
    public function myPlanUiHints(User $user): array
    {
        $sub = $this->subscriptions->getActiveSubscription($user);
        $plan = $sub?->plan;
        $paid = $sub !== null
            && $plan !== null
            && ! Plan::isFreeCatalogSlug((string) $plan->slug);

        return [
            'upgrade_available' => $paid,
            'upgrade_cta_route' => route('plans.index'),
            'current_plan_name' => $plan?->name,
            'immediate_upgrade_type' => self::TYPE_IMMEDIATE_UPGRADE,
        ];
    }

    /**
     * Assess an immediate upgrade to {@code $newPlan} without persisting (caller uses existing PayU / subscribe flow to pay and activate).
     *
     * @return array<string, mixed>
     *
     * @throws HttpException
     */
    public function upgradeNow(
        User $user,
        Plan $newPlan,
        ?int $planTermId = null,
        ?int $planPriceId = null,
        ?string $couponCode = null,
    ): array {
        if (! $newPlan->is_active) {
            throw new HttpException(422, __('subscriptions.plan_inactive'));
        }
        if (Plan::isFreeCatalogSlug((string) $newPlan->slug)) {
            throw new HttpException(422, __('subscription_upgrade.target_plan_not_paid'));
        }

        $current = $this->subscriptions->getActiveSubscription($user);
        if ($current === null || $current->plan === null) {
            throw new HttpException(422, __('subscription_upgrade.no_active_subscription'));
        }
        if ((int) $current->plan_id === (int) $newPlan->id) {
            throw new HttpException(422, __('subscription_upgrade.same_plan'));
        }

        $now = now();
        $apply = $this->quotaEngine->applyPlan($user, $newPlan, $now);
        $carryFromEngine = is_array($apply['carry_quota'] ?? null) ? $apply['carry_quota'] : [];

        /** @var array<string, int> $upgradeCreditByFeature Phase 3: reserved; empty until product maps proration → policy units without bypassing engine rules. */
        $upgradeCreditByFeature = [];
        $mergedCarryPreview = $this->mergeCarryWithUpgradeCredit($carryFromEngine, $upgradeCreditByFeature);

        $timePreview = $this->timeRemainingPreview($current, $now);

        $prepared = $this->revenueOrchestrator->prepareCheckout($user, $newPlan, $planTermId, $planPriceId, $couponCode);
        $resolved = $prepared['resolved'];
        $finalAmount = round((float) ($resolved['final_amount'] ?? 0), 2);

        return [
            'type' => self::TYPE_IMMEDIATE_UPGRADE,
            'current_subscription_id' => (int) $current->id,
            'current_plan_id' => (int) $current->plan_id,
            'target_plan_id' => (int) $newPlan->id,
            'quota_policies_preview' => $apply['quota_policies'],
            'carry_quota_from_engine' => $carryFromEngine,
            'upgrade_credit_by_feature' => $upgradeCreditByFeature,
            'carry_quota_merged_preview' => $mergedCarryPreview,
            'time_remaining_preview' => $timePreview,
            'requires_payment' => $finalAmount > 0.0,
            'prepared_checkout' => $prepared,
            'resolved_checkout' => $resolved,
        ];
    }

    /**
     * Placeholder for next-cycle downgrade scheduling (no persistence / rules yet).
     *
     * @return array<string, mixed>
     */
    public function scheduleDowngradeForNextCycle(User $user, Plan $targetPlan, ?int $planTermId = null): array
    {
        return [
            'supported' => false,
            'type' => self::TYPE_SCHEDULED_DOWNGRADE_NEXT_CYCLE,
            'message' => __('subscription_upgrade.downgrade_not_implemented'),
            'user_id' => (int) $user->id,
            'target_plan_id' => (int) $targetPlan->id,
            'plan_term_id' => $planTermId,
        ];
    }

    /**
     * @param  array<string, int>  $carryFromEngine
     * @param  array<string, int>  $upgradeCreditByFeature
     * @return array<string, int>
     */
    private function mergeCarryWithUpgradeCredit(array $carryFromEngine, array $upgradeCreditByFeature): array
    {
        $out = $carryFromEngine;
        foreach ($upgradeCreditByFeature as $key => $raw) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $inc = max(0, (int) $raw);
            if ($inc === 0) {
                continue;
            }
            $out[$key] = max(0, (int) ($out[$key] ?? 0)) + $inc;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function timeRemainingPreview(Subscription $current, CarbonInterface $at): array
    {
        $starts = $current->starts_at;
        $ends = $current->ends_at;
        if ($starts === null || $ends === null) {
            return [
                'fraction_of_period_remaining' => null,
                'checkout_base_amount' => null,
                'unused_value_preview_rupees' => null,
            ];
        }
        if ($at->greaterThanOrEqualTo($ends)) {
            return [
                'fraction_of_period_remaining' => 0.0,
                'checkout_base_amount' => null,
                'unused_value_preview_rupees' => null,
            ];
        }

        $totalSeconds = max(1, $ends->getTimestamp() - $starts->getTimestamp());
        $remainingSeconds = max(0, $ends->getTimestamp() - $at->getTimestamp());
        $fraction = min(1.0, max(0.0, $remainingSeconds / $totalSeconds));

        $snap = $current->checkoutSnapshot();
        $base = isset($snap['base_amount']) ? round((float) $snap['base_amount'], 2) : null;
        $unusedValue = ($base !== null && $base > 0) ? round($base * $fraction, 2) : null;

        return [
            'fraction_of_period_remaining' => round($fraction, 4),
            'checkout_base_amount' => $base,
            'unused_value_preview_rupees' => $unusedValue,
        ];
    }
}
