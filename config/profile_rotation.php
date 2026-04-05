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

    'view_cooldown_days' => max(1, (int) env('PROFILE_ROTATION_VIEW_COOLDOWN_DAYS', 30)),
];
