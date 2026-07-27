<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile push — Firebase Cloud Messaging, HTTP v1.
    |--------------------------------------------------------------------------
    |
    | `enabled` is the MASTER kill switch for the whole channel. It is the only
    | value that must live in .env, because everything else an operator wants to
    | change day to day (which notification types push, quiet hours) is an admin
    | setting in the database — see NotificationPlatformSettingsService.
    |
    | `project_id` and `credentials` are deliberately null by default: the service
    | account JSON already names its own project, so FirebasePushService reads
    | project_id out of the credentials file. Setting FCM_PROJECT_ID would create
    | a second source of truth for the same fact, so only override it if you are
    | intentionally pointing at a different Firebase project.
    |
    */
    'push' => [
        'enabled' => (bool) env('ENGAGEMENT_PUSH_ENABLED', false),

        /** Absolute path to the Firebase service-account JSON. */
        'credentials' => env('FCM_CREDENTIALS_PATH'),

        /** Overrides the `project_id` inside the credentials file. Normally null. */
        'project_id' => env('FCM_PROJECT_ID'),

        /*
        | Seconds to wait on one messages:send call.
        |
        | Sends are SYNCHRONOUS (see PushDispatchService). They are deliberately
        | NOT queued: production runs QUEUE_CONNECTION=database with workers that
        | only serve the default and `bulk-intake` queues, so anything pushed onto
        | the `notifications` queue is never executed — verified 2026-07-27, that
        | queue held 82 jobs due since 2026-06-17. A push that silently joins a
        | 40-day-old backlog is worse than one that fails loudly in the log.
        |
        | Keep this short. The call sits inside the request that created the
        | notification, so this value is the worst case that request can be
        | delayed by a slow Firebase. Measured round trip from production is
        | ~0.12s, so 5s is ~40x headroom and still an imperceptible stall.
        */
        'timeout' => (int) env('FCM_HTTP_TIMEOUT', 5),

        /** Fallback quiet-hours window, used until an admin saves the setting. */
        'quiet_hours' => [
            'enabled' => (bool) env('ENGAGEMENT_PUSH_QUIET_HOURS_ENABLED', true),
            'start_hour' => (int) env('ENGAGEMENT_PUSH_QUIET_HOURS_START', 22),
            'end_hour' => (int) env('ENGAGEMENT_PUSH_QUIET_HOURS_END', 8),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inactive user reminders (email + in-app; optional WhatsApp template)
    |--------------------------------------------------------------------------
    */
    'inactive_reminder' => [
        'enabled' => (bool) env('ENGAGEMENT_INACTIVE_REMINDER_ENABLED', true),
        /** No meaningful session activity for this many days → eligible. */
        'after_days' => (int) env('ENGAGEMENT_INACTIVE_REMINDER_DAYS', 3),
        /** Do not send another inactive reminder within this many days. */
        'cooldown_days' => (int) env('ENGAGEMENT_INACTIVE_REMINDER_COOLDOWN_DAYS', 7),
        'whatsapp' => [
            'enabled' => (bool) env('ENGAGEMENT_INACTIVE_WHATSAPP_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | “New matches” digest (daily; in-app + email)
    |--------------------------------------------------------------------------
    */
    'new_matches_digest' => [
        'enabled' => (bool) env('ENGAGEMENT_NEW_MATCHES_DIGEST_ENABLED', true),
        /** Minimum match score (0–100) for a candidate to count toward the digest. */
        'min_score' => (int) env('ENGAGEMENT_NEW_MATCHES_MIN_SCORE', 55),
        /** Tab passed to MatchingService (perfect | daily | near | …). */
        'tab' => env('ENGAGEMENT_NEW_MATCHES_TAB', 'perfect'),
        /** Max candidates scanned from the tab (lightweight cap). */
        'candidate_limit' => (int) env('ENGAGEMENT_NEW_MATCHES_LIMIT', 12),
        /** Minimum matches at/above min_score before a digest is sent. */
        'min_matches' => (int) env('ENGAGEMENT_NEW_MATCHES_MIN_COUNT', 1),
        /** Cooldown between digests for the same user. */
        'cooldown_days' => (int) env('ENGAGEMENT_NEW_MATCHES_COOLDOWN_DAYS', 1),
    ],

];
