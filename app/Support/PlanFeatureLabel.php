<?php

namespace App\Support;

use App\Models\PlanQuotaPolicy;
use App\Services\SubscriptionService;
use Illuminate\Support\Str;

/**
 * Human-readable lines for {@see \App\Models\PlanFeature} rows on public pricing.
 */
final class PlanFeatureLabel
{
    /**
     * Public catalog (/plans): scale pool limits by billing duration vs shortest option on the card.
     *
     * @param  float  $durationMultiplier  e.g. 90/30 = 3 for quarterly vs monthly baseline
     * @param  string|null  $billingDurationType  {@see PlanPrice::duration_type} or {@see PlanTerm::billing_key}
     */
    public static function catalogFormatValue(string $key, string $value, float $durationMultiplier, ?string $billingDurationType = null): string
    {
        $v = trim($value);
        if ($v === '') {
            return '—';
        }
        if ((int) $v === -1 || strcasecmp($v, 'unlimited') === 0) {
            return __('subscriptions.unlimited');
        }

        if (self::isTruthyKey($key)) {
            return self::truthy($v) ? __('subscriptions.yes') : __('subscriptions.no');
        }

        $mult = max(0.0, $durationMultiplier);
        if ($mult > 0 && $mult !== 1.0 && self::catalogShouldScaleLimitKey($key)) {
            $n = (int) $v;
            if ($n !== 9999 && $n >= 0) {
                $scaled = (int) max(0, (int) round($n * $mult));

                return self::formatValue($key, (string) $scaled);
            }
        }

        return self::formatValue($key, $v);
    }

    /**
     * Keys whose numeric cap represents a per-cycle pool that grows with subscription length on the pricing card.
     */
    private static function catalogShouldScaleLimitKey(string $key): bool
    {
        return in_array($key, [
            PlanFeatureKeys::CONTACT_VIEW_LIMIT,
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH,
            PlanFeatureKeys::INTEREST_VIEW_LIMIT,
            'photo_blur_limit',
            PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT,
        ], true);
    }

    /**
     * Public catalog: label may depend on quota {@code refresh_type} (e.g. chat send only shows “(per day)” when daily).
     *
     * @param  array<string, mixed>|null  $quotaPayload  Shape like {@see \App\Services\PlanQuotaPolicyMirror::payloadFromModel} output.
     */
    public static function catalogLabelForPricing(string $key, ?array $quotaPayload): string
    {
        if ($quotaPayload !== null && ($key === PlanFeatureKeys::CHAT_SEND_LIMIT || $key === SubscriptionService::FEATURE_CHAT_SEND_LIMIT)) {
            $rt = PlanQuotaRefreshRuntime::normalizeRefreshTypeString((string) ($quotaPayload['refresh_type'] ?? ''));

            if ($rt === PlanQuotaPolicy::REFRESH_DAILY || $rt === 'daily') {
                return __('subscriptions.pricing_feature_chat_send_daily');
            }

            return __('subscriptions.pricing_feature_chat_send');
        }

        return self::label($key);
    }

    public static function label(string $key): string
    {
        return match ($key) {
            SubscriptionService::FEATURE_CHAT_SEND_LIMIT => __('subscriptions.pricing_feature_chat_send'),
            PlanFeatureKeys::CHAT_CAN_READ => __('subscriptions.pricing_feature_chat_read'),
            PlanFeatureKeys::INTEREST_SEND_LIMIT => __('subscriptions.pricing_feature_interest_send'),
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => __('subscriptions.pricing_feature_interest_view_limit'),
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => __('subscriptions.feature_daily_profile_views'),
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => __('subscriptions.pricing_feature_contact_unlock'),
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => __('subscriptions.feature_chat_images'),
            PlanFeatureKeys::PHOTO_FULL_ACCESS => __('subscriptions.pricing_feature_photo_full'),
            PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT => __('subscriptions.pricing_feature_who_viewed_preview'),
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => __('subscriptions.pricing_feature_mediator'),
            PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => __('subscriptions.pricing_feature_boost'),
            PlanFeatureKeys::PRIORITY_LISTING => __('subscriptions.pricing_feature_priority'),
            PlanFeatureKeys::ADVANCED_PROFILE_SEARCH => __('subscriptions.pricing_feature_advanced_search'),
            PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT => __('subscriptions.pricing_feature_whatsapp_direct'),
            default => Str::headline(str_replace('_', ' ', $key)),
        };
    }

    public static function formatValue(string $key, string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '—';
        }
        if ((int) $v === -1 || strcasecmp($v, 'unlimited') === 0) {
            return __('subscriptions.unlimited');
        }

        if (self::isTruthyKey($key)) {
            return self::truthy($v) ? __('subscriptions.yes') : __('subscriptions.no');
        }

        // 🔥 CUSTOM HUMAN FRIENDLY TEXT

        if ($key === 'chat_send_limit') {
            return $value == '9999' ? 'Unlimited messages' : $value.'/day';
        }

        if ($key === 'contact_view_limit') {
            return $value == '9999' ? 'Unlimited' : $value;
        }

        if ($key === 'interest_send_limit') {
            return $value == '9999' ? 'Unlimited interests' : $value.'/day';
        }

        if ($key === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
            if ((int) $v === -1 || (int) $v >= 999) {
                return __('subscriptions.who_viewed_catalog_see_all_viewers');
            }
            if ((int) $v === 0) {
                return __('subscriptions.who_viewed_catalog_hidden');
            }

            return trans_choice('subscriptions.who_viewed_catalog_see_n_profiles', (int) $v, ['count' => (int) $v]);
        }

        return $v;
    }

    public static function shouldListKey(string $key): bool
    {
        return $key !== '' && ! str_starts_with($key, '_');
    }

    /**
     * @param  array<string, mixed>  $payload  {@see PlanQuotaPolicy} / snapshot row.
     */
    public static function quotaCatalogShouldListRow(string $featureKey, array $payload): bool
    {
        $enabled = filter_var($payload['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($payload['is_enabled'] ?? '') === '1'
            || ($payload['is_enabled'] ?? false) === true;
        if (! $enabled) {
            return false;
        }
        if (PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($featureKey)) {
            return true;
        }
        if ($featureKey === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
            $refresh = PlanQuotaRefreshRuntime::normalizeRefreshTypeString((string) ($payload['refresh_type'] ?? ''));
            if ($refresh === PlanQuotaPolicy::REFRESH_UNLIMITED) {
                return true;
            }
            $lim = $payload['limit_value'] ?? null;
            if ($lim === null || $lim === '') {
                return false;
            }
            if ((int) $lim === 0) {
                return false;
            }

            return true;
        }
        $refresh = (string) ($payload['refresh_type'] ?? '');
        if ($refresh === PlanQuotaPolicy::REFRESH_UNLIMITED) {
            return true;
        }
        $lim = $payload['limit_value'] ?? null;
        if ($lim === null || $lim === '') {
            return false;
        }

        return (int) $lim !== 0;
    }

    public static function quotaCatalogShouldListMirroredPair(string $key, string $value, ?string $sourceFeatureKey): bool
    {
        if (self::isTruthyKey($key)) {
            return self::truthy($value);
        }
        $v = trim($value);
        if ($v === '' || $v === '0') {
            return false;
        }

        return true;
    }

    private static function isTruthyKey(string $key): bool
    {
        return in_array($key, [
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES,
            PlanFeatureKeys::CHAT_CAN_READ,
            PlanFeatureKeys::PHOTO_FULL_ACCESS,
            PlanFeatureKeys::PRIORITY_LISTING,
            PlanFeatureKeys::ADVANCED_PROFILE_SEARCH,
            PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT,
        ], true);
    }

    private static function truthy(string $value): bool
    {
        $s = strtolower(trim($value));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
