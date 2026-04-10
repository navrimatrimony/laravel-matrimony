<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin moderation engine (Laravel + Python sync via /api/moderation-config)
    |--------------------------------------------------------------------------
    */

    'engine_version_label' => env('PHOTO_MODERATION_ENGINE_VERSION', 'laravel-nudenet-1'),

    /**
     * When distinct profiles upload images whose stored scan classifies as `unsafe` within this window,
     * {@see \App\Services\Admin\ModerationBurstAlertService} may surface an admin banner (placeholder).
     */
    'unsafe_burst_threshold' => (int) env('MODERATION_UNSAFE_BURST_THRESHOLD', 99999),

    /**
     * Prefix for {@see \App\Http\Controllers\Api\ModerationConfigController} `version` (fingerprint suffix reflects settings).
     */
    'python_config_version' => env('MODERATION_PYTHON_CONFIG_VERSION', 'v1'),

];
