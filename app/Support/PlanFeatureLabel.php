<?php

namespace App\Support;

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

        if ($key === PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD) {
            return self::catalogInterestViewResetDisplay($v, $billingDurationType);
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
     * Align marketing copy with selected billing period; weekly reset stays weekly.
     */
    private static function catalogInterestViewResetDisplay(string $stored, ?string $billingDurationType): string
    {
        $s = strtolower(trim($stored));
        if ($s === 'weekly') {
            return __('interests.period_weekly');
        }

        $t = $billingDurationType !== null ? strtolower(trim($billingDurationType)) : '';

        return match ($t) {
            'quarterly' => __('interests.period_quarterly'),
            'half_yearly' => __('interests.period_half_yearly'),
            'yearly' => __('interests.period_yearly'),
            'monthly', '' => self::formatValue(PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD, $stored),
            default => self::formatValue(PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD, $stored),
        };
    }

    public static function label(string $key): string
    {
        return match ($key) {
            SubscriptionService::FEATURE_CHAT_SEND_LIMIT => __('subscriptions.pricing_feature_chat_send'),
            PlanFeatureKeys::CHAT_CAN_READ => __('subscriptions.pricing_feature_chat_read'),
            PlanFeatureKeys::INTEREST_SEND_LIMIT => __('subscriptions.pricing_feature_interest_send'),
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => __('subscriptions.pricing_feature_interest_view_limit'),
            PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD => __('subscriptions.pricing_feature_interest_view_reset'),
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => __('subscriptions.feature_daily_profile_views'),
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => __('subscriptions.pricing_feature_contact_unlock'),
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => __('subscriptions.feature_chat_images'),
            PlanFeatureKeys::PHOTO_FULL_ACCESS => __('subscriptions.pricing_feature_photo_full'),
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => __('subscriptions.pricing_feature_who_viewed'),
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

        if ($key === PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD) {
            return match (strtolower(trim($value))) {
                'weekly' => __('interests.period_weekly'),
                'quarterly' => __('interests.period_quarterly'),
                'monthly' => __('interests.period_monthly'),
                default => $v,
            };
        }

        return $v;
    }

    public static function shouldListKey(string $key): bool
    {
        return $key !== '' && ! str_starts_with($key, '_');
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
