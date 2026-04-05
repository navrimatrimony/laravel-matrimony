<?php

namespace App\Support;

use App\Services\SubscriptionService;
use Illuminate\Support\Str;

/**
 * Human-readable lines for {@see \App\Models\PlanFeature} rows on public pricing.
 */
final class PlanFeatureLabel
{
    public static function label(string $key): string
    {
        return match ($key) {
            SubscriptionService::FEATURE_DAILY_CHAT_SEND_LIMIT => __('subscriptions.feature_daily_chat'),
            SubscriptionService::FEATURE_MONTHLY_INTEREST_SEND_LIMIT => __('subscriptions.feature_monthly_interests'),
            PlanFeatureKeys::INTEREST_SEND_LIMIT => __('subscriptions.pricing_feature_interest_daily'),
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => __('subscriptions.feature_daily_profile_views'),
            SubscriptionService::FEATURE_CONTACT_NUMBER_ACCESS => __('subscriptions.feature_contact'),
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => __('subscriptions.feature_chat_images'),
            PlanFeatureKeys::PHOTO_FULL_ACCESS => __('subscriptions.pricing_feature_photo_full'),
            PlanFeatureKeys::CONTACT_UNLOCK => __('subscriptions.pricing_feature_contact_unlock'),
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => __('subscriptions.pricing_feature_who_viewed'),
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => __('subscriptions.pricing_feature_mediator'),
            PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => __('subscriptions.pricing_feature_boost'),
            PlanFeatureKeys::PRIORITY_LISTING => __('subscriptions.pricing_feature_priority'),
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

        return $v;
    }

    public static function shouldListKey(string $key): bool
    {
        return $key !== '' && ! str_starts_with($key, '_');
    }

    private static function isTruthyKey(string $key): bool
    {
        return in_array($key, [
            SubscriptionService::FEATURE_CONTACT_NUMBER_ACCESS,
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES,
            PlanFeatureKeys::CONTACT_UNLOCK,
            PlanFeatureKeys::PHOTO_FULL_ACCESS,
        ], true);
    }

    private static function truthy(string $value): bool
    {
        $s = strtolower(trim($value));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
