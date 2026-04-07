<?php

/**
 * Global monetization switches (admin-aligned; env for ops, UI copy from lang files).
 */
return [
    'coupons' => [
        'enabled' => (bool) env('MONETIZATION_COUPONS_ENABLED', true),
    ],
    'referral' => [
        'enabled' => (bool) config('referral.enabled', true),
    ],
    'wallet' => [
        'enabled' => (bool) env('MONETIZATION_WALLET_ENABLED', true),
    ],
    'boost' => [
        'enabled' => (bool) env('MONETIZATION_BOOST_ENABLED', true),
    ],
    /** Days before plan end to send in-app reminder. */
    'plan_expiry_notify_days_before' => (int) env('MONETIZATION_PLAN_EXPIRY_NOTIFY_DAYS', 2),
];
