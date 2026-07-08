<?php

namespace App\Support;

use App\Models\PlanQuotaPolicy;

final class PlanQuotaLimitCalculator
{
    public static function effectiveLimit(int $baseLimit, string $refreshType, int $quotaBonusPercent): int
    {
        if ($baseLimit < 0) {
            return -1;
        }

        if ($baseLimit === 0 || ! self::bonusAppliesToRefreshType($refreshType)) {
            return $baseLimit;
        }

        $bonusPercent = max(0, min(100, $quotaBonusPercent));

        return $baseLimit + (int) ceil($baseLimit * $bonusPercent / 100);
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
}
