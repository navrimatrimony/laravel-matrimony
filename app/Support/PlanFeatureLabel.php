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
            SubscriptionService::FEATURE_CHAT_SEND_LIMIT => 'Messages/day',
            PlanFeatureKeys::INTEREST_SEND_LIMIT => 'Interests/day',
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => __('subscriptions.feature_daily_profile_views'),
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => 'Contact views',
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

        // 🔥 CUSTOM HUMAN FRIENDLY TEXT

        if ($key === 'chat_can_read') {
            return $value == '1' ? 'Can read messages' : 'Cannot read messages';
        }

        if ($key === 'chat_send_limit') {
            return $value == '9999' ? 'Unlimited messages' : $value.'/day';
        }

        if ($key === 'contact_view_limit') {
            return $value == '9999' ? 'Unlimited' : $value;
        }

        if ($key === 'interest_send_limit') {
            return $value == '9999' ? 'Unlimited interests' : $value.'/day';
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
