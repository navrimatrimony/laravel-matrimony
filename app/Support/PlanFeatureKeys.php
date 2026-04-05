<?php

namespace App\Support;

/**
 * Canonical snake_case keys for {@see \App\Models\PlanFeature} (feature gating).
 */
final class PlanFeatureKeys
{
    public const PROFILE_VIEW_LIMIT = 'profile_view_limit';

    public const INTEREST_SEND_LIMIT = 'interest_send_limit';

    public const CHAT_SEND_LIMIT = 'chat_send_limit';

    public const CHAT_CAN_SEND = 'chat_can_send';

    public const CHAT_CAN_READ = 'chat_can_read';

    public const WHO_VIEWED_ME_DAYS = 'who_viewed_me_days';

    public const PHOTO_BLUR_LIMIT = 'photo_blur_limit';

    /** Truthy plan value = subscriber may view full albums without tier blur. */
    public const PHOTO_FULL_ACCESS = 'photo_full_access';

    public const CONTACT_VIEW_LIMIT = 'contact_view_limit';

    public const CONTACT_UNLOCK = 'contact_unlock';

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
            self::PROFILE_VIEW_LIMIT,
            self::INTEREST_SEND_LIMIT,
            self::CHAT_SEND_LIMIT,
            self::CHAT_CAN_SEND,
            self::CHAT_CAN_READ,
            self::WHO_VIEWED_ME_DAYS,
            self::PHOTO_BLUR_LIMIT,
            self::PHOTO_FULL_ACCESS,
            self::CONTACT_VIEW_LIMIT,
            self::CONTACT_UNLOCK,
            self::PROFILE_BOOST_PER_WEEK,
            self::PRIORITY_LISTING,
            self::MEDIATOR_REQUESTS_PER_MONTH,
            self::REFERRAL_BONUS_DAYS,
        ];
    }
}
