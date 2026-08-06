<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\Subscription;

/**
 * Phase 2: catalog timing on {@see Plan}; frozen subscription-contract timing on {@see Subscription}.
 * Plan readers ({@see gracePeriodDays} / {@see leftoverQuotaCarryWindowDays}) are Keep intentionally
 * for free-tier UI, admin catalog, and purchase-time copy — paid runtime uses *ForSubscription only.
 * Legacy {@see PlanQuotaPolicy::grace_percent_of_plan} is derived from plan for DB compatibility.
 */
final class PlanSubscriptionTerms
{
    public static function gracePeriodDays(Plan $plan): int
    {
        return max(0, (int) ($plan->grace_period_days ?? 0));
    }

    /**
     * Days after grace ends during which purchasing a new plan can still apply leftover quota (null = not set).
     */
    public static function leftoverQuotaCarryWindowDays(?Plan $plan): ?int
    {
        if ($plan === null) {
            return null;
        }
        $v = $plan->leftover_quota_carry_window_days;

        return $v === null ? null : max(0, (int) $v);
    }

    /**
     * Frozen contract grace on the subscription row (runtime access / expiry / entitlements).
     */
    public static function gracePeriodDaysForSubscription(Subscription $subscription): int
    {
        return max(0, (int) ($subscription->grace_period_days ?? 0));
    }

    /**
     * Frozen leftover carry window on the subscription row (null = carry disabled for this contract).
     */
    public static function leftoverQuotaCarryWindowDaysForSubscription(Subscription $subscription): ?int
    {
        $v = $subscription->leftover_quota_carry_window_days;

        return $v === null ? null : max(0, (int) $v);
    }

    /**
     * Catalog → contract copy at purchase/renew (Phase 2 Checkpoint A writers).
     *
     * @return array{grace_period_days: int, leftover_quota_carry_window_days: int|null}
     */
    public static function contractTimingAttributesFromPlan(Plan $plan): array
    {
        return [
            'grace_period_days' => self::gracePeriodDays($plan),
            'leftover_quota_carry_window_days' => self::leftoverQuotaCarryWindowDays($plan),
        ];
    }

    /**
     * Legacy column: percent of plan duration equivalent to {@see gracePeriodDays()} (0–100).
     */
    public static function derivedGracePercentForQuotaPolicies(Plan $plan): int
    {
        $days = self::gracePeriodDays($plan);
        $dur = (int) ($plan->duration_days ?? 0);
        if ($dur <= 0 || $days <= 0) {
            return 0;
        }

        return (int) min(100, max(0, round($days / $dur * 100)));
    }

    public static function syncDerivedGracePercentToAllQuotaPolicies(Plan $plan): void
    {
        $pct = self::derivedGracePercentForQuotaPolicies($plan->fresh());
        PlanQuotaPolicy::query()->where('plan_id', $plan->id)->update(['grace_percent_of_plan' => $pct]);
    }
}
