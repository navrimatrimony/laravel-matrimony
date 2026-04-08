<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Discover sort (profile search)
    |--------------------------------------------------------------------------
    | When sort=discover, search excludes recently viewed / already engaged profiles
    | and orders with freshness + stable pseudo-random (same order per session per day
    | so pagination stays consistent).
    */
    'enabled' => env('PROFILE_ROTATION_ENABLED', true),

    /*
    | Profiles viewed by this member within the last N hours are hidden from Discover
    | (so you do not see the same cards again immediately). After that window they can
    | reappear lower in the list (never-viewed still preferred via ordering).
    */
    'recent_view_suppress_hours' => max(0, (int) env('PROFILE_ROTATION_RECENT_VIEW_SUPPRESS_HOURS', 72)),

    /** @deprecated Use recent_view_suppress_hours; if set (>0) and hours is 0, days×24 is used as fallback */
    'view_cooldown_days' => max(0, (int) env('PROFILE_ROTATION_VIEW_COOLDOWN_DAYS', 0)),
];
