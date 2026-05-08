<?php

/**
 * Python Data Engine integration (CLI today; HTTP API reserved on port 8003).
 *
 * Invocation: Symfony Process runs the configured Python binary against {@see runner_path}
 * with cwd {@see working_directory} and env {@code MODE=analyze|fix} (same contract as `python-data-engine/scripts/runner.py`).
 *
 * Future: POST http://127.0.0.1:{DATA_ENGINE_HTTP_PORT}/api/run?mode=analyze
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    */
    'enabled' => filter_var(env('DATA_ENGINE_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Python interpreter
    |--------------------------------------------------------------------------
    |
    | Prefer DATA_ENGINE_PYTHON_BINARY for explicit venv paths (Windows/Linux).
    | Falls back to DATA_ENGINE_PYTHON then platform defaults.
    |
    */
    'python_binary' => env('DATA_ENGINE_PYTHON_BINARY')
        ?: env('DATA_ENGINE_PYTHON', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'),

    /*
    |--------------------------------------------------------------------------
    | Runner script & working directory
    |--------------------------------------------------------------------------
    */
    'runner_path' => env('DATA_ENGINE_RUNNER_PATH')
        ?: base_path('python-data-engine/scripts/runner.py'),

    'working_directory' => env('DATA_ENGINE_WORKING_DIRECTORY')
        ?: base_path('python-data-engine'),

    /*
    |--------------------------------------------------------------------------
    | Concurrency (Cache::lock key `python-data-engine-run`)
    |--------------------------------------------------------------------------
    */
    'lock_ttl_seconds' => (int) env('DATA_ENGINE_LOCK_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Reserved HTTP API port (future FastAPI/Flask mode — do not bind Laravel here)
    |--------------------------------------------------------------------------
    */
    'http_port' => (int) env('DATA_ENGINE_HTTP_PORT', 8003),

    'http_base_url' => env('DATA_ENGINE_HTTP_BASE_URL', 'http://127.0.0.1:8003'),

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    */
    'timeout_seconds' => (int) env('DATA_ENGINE_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Production: dispatch {@see \App\Jobs\RunPythonDataEngineJob} instead of running inline.
    |--------------------------------------------------------------------------
    */
    'queue_on_production' => filter_var(env('DATA_ENGINE_QUEUE_ON_PRODUCTION', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Driver: cli (default) | http (reserved — not implemented)
    |--------------------------------------------------------------------------
    */
    'driver' => env('DATA_ENGINE_DRIVER', 'cli'),

    /*
    |--------------------------------------------------------------------------
    | Safety: mutation mode (fix) gate
    |--------------------------------------------------------------------------
    |
    | Keep false by default so routine admin actions or accidental clicks do not
    | trigger write operations in python-data-engine fix mode.
    |
    */
    'allow_fix_mode' => filter_var(env('DATA_ENGINE_ALLOW_FIX_MODE', false), FILTER_VALIDATE_BOOL),

    'retention' => [
        'snapshot_keep_per_entity' => (int) env('DATA_AUDIT_RETENTION_SNAPSHOT_KEEP_PER_ENTITY', 20),
        'snapshot_max_age_days' => (int) env('DATA_AUDIT_RETENTION_SNAPSHOT_MAX_AGE_DAYS', 30),
        'comparison_keep_files' => (int) env('DATA_AUDIT_RETENTION_COMPARISON_KEEP_FILES', 50),
        'comparison_max_age_days' => (int) env('DATA_AUDIT_RETENTION_COMPARISON_MAX_AGE_DAYS', 30),
        'report_max_age_days' => (int) env('DATA_AUDIT_RETENTION_REPORT_MAX_AGE_DAYS', 30),
        'log_max_age_days' => (int) env('DATA_AUDIT_RETENTION_LOG_MAX_AGE_DAYS', 30),
    ],

    'ops' => [
        'stale_hours' => (int) env('DATA_AUDIT_STALE_HOURS', 24),
        'warning_failure_streak' => (int) env('DATA_AUDIT_WARNING_FAILURE_STREAK', 2),
        'critical_failure_streak' => (int) env('DATA_AUDIT_CRITICAL_FAILURE_STREAK', 4),
        'storage_warning_bytes' => (int) env('DATA_AUDIT_STORAGE_WARNING_BYTES', 1073741824),
        'alert_cooldown_minutes' => (int) env('DATA_AUDIT_ALERT_COOLDOWN_MINUTES', 30),
    ],

    'autonomous' => [
        'enabled' => filter_var(env('DATA_GOVERNANCE_AUTONOMOUS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'quarantine_retention_days' => (int) env('DATA_GOVERNANCE_QUARANTINE_RETENTION_DAYS', 30),
        'bulk_validation_limit' => (int) env('DATA_GOVERNANCE_BULK_VALIDATION_LIMIT', 1000),
        'critical_drift_block_threshold' => (int) env('DATA_GOVERNANCE_CRITICAL_DRIFT_BLOCK_THRESHOLD', 1),
        'parity_block_threshold' => (int) env('DATA_GOVERNANCE_PARITY_BLOCK_THRESHOLD', 5),
        'orphan_block_threshold' => (int) env('DATA_GOVERNANCE_ORPHAN_BLOCK_THRESHOLD', 1),
    ],

    'queues' => [
        'snapshot' => env('DATA_GOVERNANCE_QUEUE_SNAPSHOT', 'governance-snapshot'),
        'comparison' => env('DATA_GOVERNANCE_QUEUE_COMPARISON', 'governance-comparison'),
        'repair' => env('DATA_GOVERNANCE_QUEUE_REPAIR', 'governance-repair'),
        'quarantine' => env('DATA_GOVERNANCE_QUEUE_QUARANTINE', 'governance-quarantine'),
        'timeout_seconds' => (int) env('DATA_GOVERNANCE_QUEUE_TIMEOUT', 900),
        'tries' => (int) env('DATA_GOVERNANCE_QUEUE_TRIES', 3),
        'backoff_seconds' => (int) env('DATA_GOVERNANCE_QUEUE_BACKOFF', 60),
    ],

];
