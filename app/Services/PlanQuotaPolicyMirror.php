<?php

namespace App\Services;

use App\Models\PlanQuotaPolicy;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Http\Request;

/**
 * Maps admin {@see PlanQuotaPolicy} payloads to legacy {@see \App\Models\PlanFeature} string rows (mirror only).
 * Member-facing limits and catalog copy use {@see PlanQuotaPolicy} / {@see PlanQuotaCheckoutSnapshot} directly.
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
            foreach (self::mirroredFeatureRowsFromPolicyPayload($featureKey, $p) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payloadFromModel(PlanQuotaPolicy $policy): array
    {
        return [
            'is_enabled' => (bool) $policy->is_enabled,
            'refresh_type' => (string) $policy->refresh_type,
            'limit_value' => $policy->limit_value,
            'daily_sub_cap' => $policy->daily_sub_cap,
            'per_day_usage_limit_enabled' => (bool) $policy->per_day_usage_limit_enabled,
            'policy_meta' => $policy->policy_meta,
        ];
    }

    /**
     * Same shape as admin request / checkout snapshot rows.
     *
     * @param  array<string, mixed>  $p
     * @return list<array{key: string, value: string}>
     */
    public static function mirroredFeatureRowsFromPolicyPayload(string $featureKey, array $p): array
    {
        if (PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($featureKey)) {
            $enabled = filter_var($p['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || (string) ($p['is_enabled'] ?? '') === '1';

            return [['key' => $featureKey, 'value' => $enabled ? '1' : '0']];
        }

        if ($featureKey === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
            return self::mirrorWhoViewedPreviewPolicy($p);
        }

        return [['key' => $featureKey, 'value' => self::mirrorLimitValue($p)]];
    }

    /**
     * Numeric cap for {@see SubscriptionService::getFeatureLimit} (excluding boolean-only quota keys).
     *
     * @param  array<string, mixed>  $p
     */
    public static function subscriptionLimitIntFromQuotaPayload(string $featureKey, array $p): int
    {
        if ($featureKey === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
            foreach (self::mirrorWhoViewedPreviewPolicy($p) as $row) {
                if ($row['key'] === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
                    return (int) $row['value'];
                }
            }

            return 0;
        }

        $v = self::mirrorLimitValue($p);

        return $v === '-1' ? -1 : (int) $v;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<array{key: string, value: string}>
     */
    private static function mirrorWhoViewedPreviewPolicy(array $p): array
    {
        $enabled = filter_var($p['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($p['is_enabled'] ?? '') === '1';
        $refresh = (string) ($p['refresh_type'] ?? PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST);
        $limitRaw = $p['limit_value'] ?? null;
        if (! $enabled) {
            return [
                ['key' => PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT, 'value' => '0'],
                ['key' => FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS, 'value' => '0'],
            ];
        }
        if ($refresh === PlanQuotaPolicy::REFRESH_UNLIMITED) {
            return [
                ['key' => PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT, 'value' => '-1'],
                ['key' => FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS, 'value' => '1'],
            ];
        }
        $preview = 0;
        if ($limitRaw !== '' && $limitRaw !== null) {
            $preview = max(0, (int) $limitRaw);
        }

        return [
            ['key' => PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT, 'value' => (string) $preview],
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
