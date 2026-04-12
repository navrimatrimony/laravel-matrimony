<?php

namespace App\Support;

/**
 * Canonical snake_case keys for {@see \App\Models\PlanFeature} (feature gating).
 *
 * Legacy keys (migrated via migrations; do not use in new rows):
 * - daily_chat_send_limit → {@see self::CHAT_SEND_LIMIT}
 * - monthly_interest_send_limit → {@see self::INTEREST_SEND_LIMIT}
 * - contact_number_access → {@see self::CONTACT_VIEW_LIMIT}
 * - Removed duplicates (do not reintroduce): chat_can_send (use chat_send_limit), contact_unlock (use contact_view_limit),
 *   profile_view_limit (use daily_profile_view_limit via {@see SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT}).
 *
 * {@see EntitlementService} mirrors these keys in {@see \App\Models\UserEntitlement} rows for active subscriptions.
 */
final class PlanFeatureKeys
{
    public const INTEREST_SEND_LIMIT = 'interest_send_limit';

    /** Max pending incoming interests per reset window with full reveal (name, photo, profile); -1 = unlimited. */
    public const INTEREST_VIEW_LIMIT = 'interest_view_limit';

    /** weekly | monthly | quarterly — window for {@see self::INTEREST_VIEW_LIMIT} ranking. */
    public const INTEREST_VIEW_RESET_PERIOD = 'interest_view_reset_period';

    public const CHAT_SEND_LIMIT = 'chat_send_limit';

    public const CHAT_CAN_READ = 'chat_can_read';

    public const WHO_VIEWED_ME_DAYS = 'who_viewed_me_days';

    /**
     * When {@see self::WHO_VIEWED_ME_DAYS} is 0, free-tier users may still see this many distinct viewers
     * in the current calendar month; additional viewers are shown blurred with upgrade CTA.
     * 0 = no preview (legacy full-lock teaser only).
     */
    public const WHO_VIEWED_ME_PREVIEW_LIMIT = 'who_viewed_me_preview_limit';

    public const PHOTO_BLUR_LIMIT = 'photo_blur_limit';

    /** Truthy plan value = subscriber may view full albums without tier blur. */
    public const PHOTO_FULL_ACCESS = 'photo_full_access';

    public const CONTACT_VIEW_LIMIT = 'contact_view_limit';

    public const PROFILE_BOOST_PER_WEEK = 'profile_boost_per_week';

    public const PRIORITY_LISTING = 'priority_listing';

    public const MEDIATOR_REQUESTS_PER_MONTH = 'mediator_requests_per_month';

    public const REFERRAL_BONUS_DAYS = 'referral_bonus_days';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::INTEREST_SEND_LIMIT,
            self::INTEREST_VIEW_LIMIT,
            self::INTEREST_VIEW_RESET_PERIOD,
            self::CHAT_SEND_LIMIT,
            self::CHAT_CAN_READ,
            self::WHO_VIEWED_ME_DAYS,
            self::WHO_VIEWED_ME_PREVIEW_LIMIT,
            self::PHOTO_BLUR_LIMIT,
            self::PHOTO_FULL_ACCESS,
            self::CONTACT_VIEW_LIMIT,
            self::PROFILE_BOOST_PER_WEEK,
            self::PRIORITY_LISTING,
            self::MEDIATOR_REQUESTS_PER_MONTH,
            self::REFERRAL_BONUS_DAYS,
        ];
    }
}
