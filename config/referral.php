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
