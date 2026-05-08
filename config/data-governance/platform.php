<?php

return [
    'environment_profile' => env('DATA_AUDIT_ENV_PROFILE', 'local'),

    'storage' => [
        'snapshot_base_path' => env('DATA_GOVERNANCE_SNAPSHOT_BASE_PATH', storage_path('app/data-audit/snapshots')),
        'comparison_base_path' => env('DATA_GOVERNANCE_COMPARISON_BASE_PATH', base_path('python-data-engine/output/comparisons')),
    ],

    'notification_hooks' => [
        'enabled' => (bool) env('DATA_AUDIT_HOOKS_ENABLED', false),
        'comparison_health_threshold' => (int) env('DATA_AUDIT_COMPARISON_HEALTH_THRESHOLD', 70),
        'high_severity_threshold' => (int) env('DATA_AUDIT_COMPARISON_HIGH_SEVERITY_THRESHOLD', 3),
        'webhook_url' => env('DATA_AUDIT_WEBHOOK_URL'),
        'email_to' => env('DATA_AUDIT_ALERT_EMAIL'),
    ],
];

