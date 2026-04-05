<?php

/*
|--------------------------------------------------------------------------
| Day-32: Communication & Contact Request Policy (SSOT)
|--------------------------------------------------------------------------
| Admin can override via admin panel; all changes must be logged to admin_audit_logs.
| These are defaults; no silent overwrite of existing grants/requests.
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Messaging policy (Chat governance)
    |--------------------------------------------------------------------------
    | Default mode must be free_chat_with_reply_gate.
    | Admin can override via Communication Policy screen (admin settings).
    */
    'allow_messaging' => env('COMMUNICATION_ALLOW_MESSAGING', true),

    // free_chat_with_reply_gate | contact_request_required
    'messaging_mode' => env('COMMUNICATION_MESSAGING_MODE', 'free_chat_with_reply_gate'),

    // Reply gate: max consecutive messages a sender can send without a reply.
    'max_consecutive_messages_without_reply' => (int) env('COMMUNICATION_MAX_CONSECUTIVE_MESSAGES_WITHOUT_REPLY', 2),

    // Cooling period after reply-gate limit is hit (hours).
    'reply_gate_cooling_hours' => (int) env('COMMUNICATION_REPLY_GATE_COOLING_HOURS', 24),

    // Sender usage limits (rolling windows).
    'max_messages_per_day_per_sender' => (int) env('COMMUNICATION_MAX_MESSAGES_PER_DAY_PER_SENDER', 20),
    'max_messages_per_week_per_sender' => (int) env('COMMUNICATION_MAX_MESSAGES_PER_WEEK_PER_SENDER', 100),
    'max_messages_per_month_per_sender' => (int) env('COMMUNICATION_MAX_MESSAGES_PER_MONTH_PER_SENDER', 300),

    // Optional anti-spam: limit new conversations per sender per calendar day.
    'max_new_conversations_per_day' => (int) env('COMMUNICATION_MAX_NEW_CONVERSATIONS_PER_DAY', 10),

    // Image messaging toggle (chat only).
    'allow_image_messages' => env('COMMUNICATION_ALLOW_IMAGE_MESSAGES', true),

    // all | paid_only
    // When set to paid_only, sender must have entitlement `chat_image_messages`.
    'image_messages_audience' => env('COMMUNICATION_IMAGE_MESSAGES_AUDIENCE', 'paid_only'),

    /*
    | Contact request eligibility does NOT require mutual interest.
    | Sender can request contact only after the receiver accepts sender's interest.
    */
    'contact_request_mode' => env('COMMUNICATION_CONTACT_REQUEST_MODE', 'mutual_only'), // mutual_only | direct_allowed | disabled

    /*
    | When true, using a paid contact reveal (contact_view credit) on a profile requires an
    | assisted matchmaking row (type=mediator) from the viewer to that profile with status interested.
    | Contact grants from the standard contact-request flow are unaffected.
    */
    'paid_contact_reveal_requires_matchmaking_interested' => env('COMMUNICATION_PAID_REVEAL_REQUIRES_MATCHMAKING_INTERESTED', true),

    /*
    | Days after reject before sender can request same receiver again. Range 7–365.
    */
    'reject_cooldown_days' => (int) env('COMMUNICATION_REJECT_COOLDOWN_DAYS', 90),

    /*
    | Days after which a pending request auto-expires. Range 1–30.
    */
    'pending_expiry_days' => (int) env('COMMUNICATION_PENDING_EXPIRY_DAYS', 7),

    /*
    | Max contact requests per sender per day (anti-spam). Null = no limit.
    */
    'max_requests_per_day_per_sender' => env('COMMUNICATION_MAX_REQUESTS_PER_DAY_PER_SENDER') ? (int) env('COMMUNICATION_MAX_REQUESTS_PER_DAY_PER_SENDER') : null,

    /*
    | Grant duration options available to receiver. Admin may enable/disable.
    */
    'grant_duration_options' => [
        'approve_once' => true,
        'approve_7_days' => true,
        'approve_30_days' => true,
    ],

    /*
    | Allowed contact scopes. Admin may disable specific scopes.
    */
    'allowed_contact_scopes' => [
        'email' => true,
        'phone' => true,
        'whatsapp' => true,
    ],

    /*
    | Request reason options (for "Why are you requesting contact?" dropdown).
    */
    'request_reasons' => [
        'talk_to_family' => 'Talk to family',
        'meet' => 'Meet',
        'need_more_details' => 'Need more details',
        'discuss_marriage_timeline' => 'Discuss marriage timeline',
        'other' => 'Other',
    ],

    /*
    | Chat restriction banner: "Upgrade" button target. Empty = app dashboard.
    | Example: COMMUNICATION_CHAT_UPGRADE_URL=https://example.com/pricing
    */
    'chat_upgrade_cta_url' => env('COMMUNICATION_CHAT_UPGRADE_URL', ''),
];
