<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanQuotaPolicy;

/**
 * Centralized subscription timing for a plan: grace after paid window and leftover-quota carry rules.
 * UI stores days on {@see Plan}; legacy {@see PlanQuotaPolicy::grace_percent_of_plan} is derived for DB compatibility.
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
