<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile push (FCM / Web Push) — not wired yet; reserved for future channel.
    |--------------------------------------------------------------------------
    */
    'push' => [
        'enabled' => (bool) env('ENGAGEMENT_PUSH_ENABLED', false),
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
