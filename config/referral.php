<?php

/**
 * Referral rewards (admin-tunable). Bonus extends referrer's paid window by N days when
 * the referred user first purchases a paid plan (see {@see \App\Services\ReferralService}).
 */
return [
    'enabled' => (bool) env('REFERRAL_ENABLED', true),

    /** Plan slug => extra days for the referrer's active subscription. */
    'rewards_by_plan_slug' => [
        'silver' => (int) env('REFERRAL_REWARD_SILVER_DAYS', 2),
        'gold' => (int) env('REFERRAL_REWARD_GOLD_DAYS', 5),
        'platinum' => (int) env('REFERRAL_REWARD_PLATINUM_DAYS', 10),
    ],
];
