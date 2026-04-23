<?php

namespace App\Support;

use App\Models\PlanQuotaPolicy;

/**
 * Public pricing: one complete line per quota policy (label + value) from payload only.
 */
final class PlanQuotaCatalogFormatter
{
    /**
     * Full catalog line: {@code "{label} — {value}"} from quota payload (SSOT). No split rendering in views.
     *
     * @param  array<string, mixed>  $payload  Normalized quota policy payload (same shape as {@see PlanQuotaPolicyMirror::payloadFromModel}).
     */
    public static function catalogLineFromPayload(
        string $featureKey,
        array $payload,
        float $durationMultiplier = 1.0,
        ?string $billingDurationType = null,
    ): string {
        $label = PlanFeatureLabel::catalogLabelForPricing($featureKey, $payload);
        $value = self::quotaValueLineOnlyFromPayload($featureKey, $payload, $durationMultiplier, $billingDurationType);

        return $label.' — '.$value;
    }

    /**
     * Value segment only (after em dash), for tests and rare internal use.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function quotaValueLineOnlyFromPayload(
        string $featureKey,
        array $payload,
        float $durationMultiplier = 1.0,
        ?string $billingDurationType = null,
    ): string {
        if (PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($featureKey)) {
            $on = filter_var($payload['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || (string) ($payload['is_enabled'] ?? '') === '1';

            return $on ? __('subscriptions.yes') : __('subscriptions.no');
        }

        if ($featureKey === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
            return self::whoViewedCatalogLine($payload);
        }

        if ($featureKey === PlanFeatureKeys::CHAT_SEND_LIMIT) {
            return self::chatSendCatalogLine($payload, $durationMultiplier, $billingDurationType);
        }

        $enabled = filter_var($payload['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($payload['is_enabled'] ?? '') === '1';
        if (! $enabled) {
            return '—';
        }

        $refresh = PlanQuotaRefreshRuntime::normalizeRefreshTypeString((string) ($payload['refresh_type'] ?? ''));
        $limitRaw = $payload['limit_value'] ?? null;
        if ($refresh === PlanQuotaPolicy::REFRESH_UNLIMITED) {
            return __('subscriptions.unlimited');
        }
        if ($limitRaw === null || $limitRaw === '') {
            return '—';
        }
        $n = (int) $limitRaw;
        if ($n === -1) {
            return __('subscriptions.unlimited');
        }

        $mult = max(0.0, $durationMultiplier);
        $scaled = $n;
        if ($mult > 0 && $mult !== 1.0 && self::shouldScaleLimitForCatalog($featureKey)) {
            if ($n !== 9999 && $n >= 0) {
                $scaled = (int) max(0, (int) round($n * $mult));
            }
        }

        return self::appendRefreshSuffix((string) $scaled, $refresh);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function whoViewedCatalogLine(array $payload): string
    {
        $refresh = PlanQuotaRefreshRuntime::normalizeRefreshTypeString((string) ($payload['refresh_type'] ?? ''));
        if ($refresh === PlanQuotaPolicy::REFRESH_UNLIMITED) {
            return __('subscriptions.who_viewed_catalog_see_all_viewers');
        }
        $lim = $payload['limit_value'] ?? null;
        if ($lim === null || $lim === '') {
            return __('subscriptions.who_viewed_catalog_hidden');
        }
        $v = (int) $lim;
        if ($v === -1 || $v >= 999) {
            return __('subscriptions.who_viewed_catalog_see_all_viewers');
        }
        if ($v === 0) {
            return __('subscriptions.who_viewed_catalog_hidden');
        }

        if ($refresh === PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST) {
            return __('subscriptions.quota_line_per_month', ['count' => $v]);
        }

        return self::appendRefreshSuffix((string) $v, $refresh);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function chatSendCatalogLine(array $payload, float $durationMultiplier, ?string $billingDurationType): string
    {
        $enabled = filter_var($payload['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($payload['is_enabled'] ?? '') === '1';
        if (! $enabled) {
            return '—';
        }
        $refresh = PlanQuotaRefreshRuntime::normalizeRefreshTypeString((string) ($payload['refresh_type'] ?? ''));
        $limitRaw = $payload['limit_value'] ?? null;
        if ($refresh === PlanQuotaPolicy::REFRESH_UNLIMITED) {
            return __('subscriptions.unlimited');
        }
        if ($limitRaw === null || $limitRaw === '') {
            return '—';
        }
        $n = (int) $limitRaw;
        if ($n === -1 || $n >= 9999) {
            return __('subscriptions.unlimited');
        }
        $mult = max(0.0, $durationMultiplier);
        $scaled = $n;
        if ($mult > 0 && $mult !== 1.0) {
            $scaled = (int) max(0, (int) round($n * $mult));
        }
        if ($refresh === PlanQuotaPolicy::REFRESH_DAILY || $refresh === 'daily') {
            return (string) $scaled.__('subscriptions.quota_line_chat_suffix_per_day');
        }

        return self::appendRefreshSuffix((string) $scaled, $refresh);
    }

    private static function appendRefreshSuffix(string $countPart, string $refresh): string
    {
        $refresh = PlanQuotaRefreshRuntime::normalizeRefreshTypeString($refresh);

        return match ($refresh) {
            PlanQuotaPolicy::REFRESH_LIFETIME, 'lifetime' => __('subscriptions.quota_line_total', ['count' => $countPart]),
            PlanQuotaPolicy::REFRESH_DAILY, 'daily' => __('subscriptions.quota_line_per_day', ['count' => $countPart]),
            PlanQuotaPolicy::REFRESH_WEEKLY, 'weekly' => __('subscriptions.quota_line_per_week', ['count' => $countPart]),
            PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST => __('subscriptions.quota_line_per_month', ['count' => $countPart]),
            PlanQuotaPolicy::REFRESH_QUARTERLY, 'quarterly' => __('subscriptions.quota_line_per_quarter', ['count' => $countPart]),
            PlanQuotaPolicy::REFRESH_UNLIMITED => __('subscriptions.unlimited'),
            default => __('subscriptions.quota_line_per_month', ['count' => $countPart]),
        };
    }

    private static function shouldScaleLimitForCatalog(string $key): bool
    {
        return in_array($key, [
            PlanFeatureKeys::CONTACT_VIEW_LIMIT,
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH,
            PlanFeatureKeys::INTEREST_VIEW_LIMIT,
            'photo_blur_limit',
            PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT,
        ], true);
    }
}
