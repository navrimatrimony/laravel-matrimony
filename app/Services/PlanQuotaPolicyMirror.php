<?php

namespace App\Services;

use App\Models\PlanQuotaPolicy;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Http\Request;

/**
 * Maps admin {@see PlanQuotaPolicy} payloads to {@see \App\Models\PlanFeature} string rows (runtime SSOT for gates).
 */
final class PlanQuotaPolicyMirror
{
    /**
     * @return list<array{key: string, value: string}>
     */
    public static function planFeatureRowsFromQuotaRequest(Request $request): array
    {
        $payload = $request->input('quota_policies');
        if (! is_array($payload)) {
            return [];
        }

        $out = [];
        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            if (! isset($payload[$featureKey]) || ! is_array($payload[$featureKey])) {
                continue;
            }
            $p = $payload[$featureKey];
            foreach (self::mirrorOne($featureKey, $p) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<array{key: string, value: string}>
     */
    private static function mirrorOne(string $featureKey, array $p): array
    {
        if (PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($featureKey)) {
            $enabled = filter_var($p['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || (string) ($p['is_enabled'] ?? '') === '1';

            return [['key' => $featureKey, 'value' => $enabled ? '1' : '0']];
        }

        if ($featureKey === PlanFeatureKeys::WHO_VIEWED_ME_DAYS) {
            return self::mirrorWhoViewed($p);
        }

        return [['key' => $featureKey, 'value' => self::mirrorLimitValue($p)]];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<array{key: string, value: string}>
     */
    private static function mirrorWhoViewed(array $p): array
    {
        $enabled = filter_var($p['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($p['is_enabled'] ?? '') === '1';
        $refresh = (string) ($p['refresh_type'] ?? PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST);
        $limitRaw = $p['limit_value'] ?? null;
        $days = 0;
        if (! $enabled) {
            return [
                ['key' => PlanFeatureKeys::WHO_VIEWED_ME_DAYS, 'value' => '0'],
                ['key' => FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS, 'value' => '0'],
            ];
        }
        if ($refresh === PlanQuotaPolicy::REFRESH_UNLIMITED) {
            $days = 999;
        } elseif ($limitRaw !== '' && $limitRaw !== null) {
            $days = max(0, (int) $limitRaw);
        }

        return [
            ['key' => PlanFeatureKeys::WHO_VIEWED_ME_DAYS, 'value' => (string) $days],
            ['key' => FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS, 'value' => '1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function mirrorLimitValue(array $p): string
    {
        $enabled = filter_var($p['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($p['is_enabled'] ?? '') === '1';
        if (! $enabled) {
            return '0';
        }

        $refresh = (string) ($p['refresh_type'] ?? PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST);
        if ($refresh === PlanQuotaPolicy::REFRESH_UNLIMITED) {
            return '-1';
        }

        $limitRaw = $p['limit_value'] ?? null;
        if ($limitRaw === '' || $limitRaw === null) {
            return '0';
        }

        return (string) max(0, (int) $limitRaw);
    }
}
