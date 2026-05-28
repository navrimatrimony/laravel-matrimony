<?php

/**
 * Referral rewards (admin-tunable). Bonus extends referrer's paid window by N days when
 * the referred user first purchases a paid plan (see {@see \App\Services\ReferralService}).
 */
return [
    'enabled' => (bool) env('REFERRAL_ENABLED', true),

    /**
     * Plan slug => extra days for the referrer's active subscription.
     * Also supports gendered slugs (silver_female, gold_male) via base fallback in ReferralService.
     */
    'rewards_by_plan_slug' => [
        'silver' => (int) env('REFERRAL_REWARD_SILVER_DAYS', 2),
        'gold' => (int) env('REFERRAL_REWARD_GOLD_DAYS', 5),
        'platinum' => (int) env('REFERRAL_REWARD_PLATINUM_DAYS', 10),
    ],

    /**
     * Plan slug => feature quota bonus pack for referrer (added as carry_quota on active subscription meta).
     * Keys must be canonical plan feature keys; values are integer increments.
     */
    /**
     * First paid checkout benefit for users who registered via an invite (referred buyer).
     * Skipped when a manual coupon code is entered at checkout (coupon wins).
     */
    /**
     * Automatic fraud signals at registration; flagged rows can enter admin review queue.
     */
    'fraud' => [
        'auto_hold_on_flags' => (bool) env('REFERRAL_FRAUD_AUTO_HOLD', true),
        'rapid_invites_per_day' => (int) env('REFERRAL_FRAUD_RAPID_INVITES_PER_DAY', 5),
        'same_ip_lookback_days' => (int) env('REFERRAL_FRAUD_SAME_IP_LOOKBACK_DAYS', 30),
    ],

    /** Days a pending_claim reward stays claimable (0 = never expire). Overridable in admin engine settings. */
    'pending_claim_expiry_days' => (int) env('REFERRAL_PENDING_CLAIM_EXPIRY_DAYS', 90),

    /**
     * Referrer reward runs only when the referred buyer passes these gates (admin can toggle each).
     */
    'quality_gates' => [
        'require_profile_active' => (bool) env('REFERRAL_QUALITY_REQUIRE_PROFILE_ACTIVE', false),
        'require_mobile_verified' => (bool) env('REFERRAL_QUALITY_REQUIRE_MOBILE_VERIFIED', false),
        'require_photo_approved' => (bool) env('REFERRAL_QUALITY_REQUIRE_PHOTO_APPROVED', false),
        'cooling_period_days' => (int) env('REFERRAL_QUALITY_COOLING_PERIOD_DAYS', 0),
    ],

    'referred_checkout' => [
        'enabled' => (bool) env('REFERRAL_REFERRED_CHECKOUT_ENABLED', true),
        'percent_off' => (int) env('REFERRAL_REFERRED_CHECKOUT_PERCENT', 10),
        'extra_days' => (int) env('REFERRAL_REFERRED_CHECKOUT_EXTRA_DAYS', 0),
    ],

    /**
     * Share-link UTM defaults (overridable per link via utm_content = channel).
     */
    'growth' => [
        'utm' => [
            'source' => env('REFERRAL_UTM_SOURCE', 'member_referral'),
            'campaign' => env('REFERRAL_UTM_CAMPAIGN', 'invite'),
        ],
        'renewal_micro_bonus' => [
            'enabled' => (bool) env('REFERRAL_RENEWAL_MICRO_BONUS_ENABLED', false),
            'bonus_days' => (int) env('REFERRAL_RENEWAL_MICRO_BONUS_DAYS', 1),
        ],
    ],

    'feature_bonus_by_plan_slug' => [
        'silver' => [
            'chat_send_limit' => (int) env('REFERRAL_REWARD_SILVER_CHAT_BONUS', 5),
            'contact_view_limit' => (int) env('REFERRAL_REWARD_SILVER_CONTACT_BONUS', 1),
        ],
        'gold' => [
            'chat_send_limit' => (int) env('REFERRAL_REWARD_GOLD_CHAT_BONUS', 10),
            'contact_view_limit' => (int) env('REFERRAL_REWARD_GOLD_CONTACT_BONUS', 2),
            'interest_send_limit' => (int) env('REFERRAL_REWARD_GOLD_INTEREST_BONUS', 5),
        ],
        'platinum' => [
            'chat_send_limit' => (int) env('REFERRAL_REWARD_PLATINUM_CHAT_BONUS', 20),
            'contact_view_limit' => (int) env('REFERRAL_REWARD_PLATINUM_CONTACT_BONUS', 5),
            'interest_send_limit' => (int) env('REFERRAL_REWARD_PLATINUM_INTEREST_BONUS', 10),
            'daily_profile_view_limit' => (int) env('REFERRAL_REWARD_PLATINUM_PROFILE_VIEW_BONUS', 25),
        ],
    ],
];
