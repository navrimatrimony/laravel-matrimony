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
    | Contact request allowed only after mutual interest when true.
    */
    'contact_request_mode' => env('COMMUNICATION_CONTACT_REQUEST_MODE', 'mutual_only'), // mutual_only | direct_allowed | disabled

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
];
