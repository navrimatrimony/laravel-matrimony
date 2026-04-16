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
    /**
     * Comma-separated “days before ends_at” windows to send plan-expiry heads-up (e.g. 7,2,1).
     * Each window sends at most once per subscription per value (see NotificationService dedup).
     */
    'plan_expiry_notify_days_before_list' => array_values(array_unique(array_filter(array_map(
        static fn (string $v): int => max(0, (int) trim($v)),
        explode(',', (string) env('MONETIZATION_PLAN_EXPIRY_NOTIFY_DAYS', '7,2,1'))
    )))),
];
