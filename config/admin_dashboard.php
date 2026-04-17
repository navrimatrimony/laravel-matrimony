<?php

return [

    'cache_ttl' => (int) env('ADMIN_DASHBOARD_CACHE_TTL', 90),

    'risk' => [
        'max_interests_per_day' => (int) env('ADMIN_RISK_MAX_INTERESTS_PER_DAY', 30),
    ],

    'insights' => [
        'risk_count_threshold' => (int) env('ADMIN_INSIGHTS_RISK_THRESHOLD', 10),
        'min_revenue_threshold' => (float) env('ADMIN_INSIGHTS_MIN_REVENUE_THRESHOLD', 1000),
        /** Hide matching rule insight for N hours after a tracked action showed improvement */
        'suppression_hours' => (int) env('ADMIN_INSIGHTS_SUPPRESSION_HOURS', 48),
        /** Show follow-up success cards for recent positive effects */
        'follow_up_hours' => (int) env('ADMIN_INSIGHTS_FOLLOW_UP_HOURS', 48),
    ],

];
