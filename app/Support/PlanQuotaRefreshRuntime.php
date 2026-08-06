<?php

namespace App\Support;

use App\Models\PlanQuotaPolicy;

/**
 * Maps {@see PlanQuotaPolicy::refresh_type} to legacy incoming-interest window tokens
 * stored on {@see PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD} for entitlements / usage.
 */
final class PlanQuotaRefreshRuntime
{
    public static function normalizeRefreshTypeString(string $refresh): string
    {
        return PlanQuotaPolicy::normalizeRefreshType($refresh);
    }

    /**
     * @return 'weekly'|'monthly'|'quarterly'|'daily'|'lifetime'
     */
    public static function interestViewResetPeriodTokenFromPayload(array $payload): string
    {
        $rt = self::normalizeRefreshTypeString((string) ($payload['refresh_type'] ?? ''));
        if ($rt === PlanQuotaPolicy::REFRESH_UNLIMITED || $rt === '') {
            return 'monthly';
        }

        return match ($rt) {
            PlanQuotaPolicy::REFRESH_WEEKLY, 'weekly' => 'weekly',
            PlanQuotaPolicy::REFRESH_DAILY, 'daily' => 'daily',
            PlanQuotaPolicy::REFRESH_LIFETIME => 'lifetime',
            PlanQuotaPolicy::REFRESH_QUARTERLY, 'quarterly' => 'quarterly',
            PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST => 'monthly',
            default => 'monthly',
        };
    }
}
