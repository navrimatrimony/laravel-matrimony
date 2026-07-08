<?php

namespace App\Support;

use App\Models\PlanQuotaPolicy;

final class PlanQuotaLimitCalculator
{
    public static function effectiveLimit(
        int $baseLimit,
        string $refreshType,
        int $quotaBonusPercent,
        float $durationMultiplier = 1.0
    ): int
    {
        if ($baseLimit < 0) {
            return -1;
        }

        if ($baseLimit === 0) {
            return $baseLimit;
        }

        $bonusPercent = max(0, min(100, $quotaBonusPercent));

        if (self::isPlanPeriodRefreshType($refreshType)) {
            $scaledLimit = (int) ceil($baseLimit * max(1.0, $durationMultiplier));

            return $scaledLimit + (int) ceil($scaledLimit * $bonusPercent / 100);
        }

        if (self::bonusAppliesToRefreshType($refreshType)) {
            return $baseLimit + (int) ceil($baseLimit * $bonusPercent / 100);
        }

        return $baseLimit;
    }

    public static function bonusAppliesToRefreshType(string $refreshType): bool
    {
        $refresh = PlanQuotaRefreshRuntime::normalizeRefreshTypeString($refreshType);

        return in_array($refresh, [
            PlanQuotaPolicy::REFRESH_DAILY,
            PlanQuotaPolicy::REFRESH_WEEKLY,
            PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST,
        ], true);
    }

    public static function isPlanPeriodRefreshType(string $refreshType): bool
    {
        $refresh = PlanQuotaRefreshRuntime::normalizeRefreshTypeString($refreshType);

        return in_array($refresh, [
            PlanQuotaPolicy::REFRESH_LIFETIME,
            PlanQuotaPolicy::REFRESH_TOTAL,
            PlanQuotaPolicy::REFRESH_PLAN_DURATION,
        ], true);
    }
}
