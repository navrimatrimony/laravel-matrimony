<?php

/**
 * Meta WhatsApp Cloud API (Graph) — outbound OTP + inbound webhooks.
 *
 * Template: create an approved Utility (or Authentication) template in Meta Business Suite
 * with a single body placeholder for the OTP (e.g. "Your code is {{1}}"), then set
 * WHATSAPP_OTP_TEMPLATE_NAME and WHATSAPP_OTP_TEMPLATE_LANGUAGE to match.
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api
 */
return [

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v22.0'),

    /** Permanent token (System User) or short-lived token from Meta; keep in env / secrets manager. */
    'access_token' => env('WHATSAPP_ACCESS_TOKEN', ''),

    /** Phone Number ID from WhatsApp > API setup in Meta Developer app. */
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', ''),

    /** Default country calling code without + (India = 91). Used when the user enters 10 digits. */
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '91'),

    /** Approved template name for OTP (must match Meta Business Manager). */
    'otp_template_name' => env('WHATSAPP_OTP_TEMPLATE_NAME', ''),

    /** e.g. en_US, en, mr */
    'otp_template_language' => env('WHATSAPP_OTP_TEMPLATE_LANGUAGE', 'en_US'),

    /**
     * Optional JSON array of body parameters (each item: ["type" => "text", "text" => "..."]).
     * If null/empty, a single parameter is sent: the OTP string.
     * Use this if your approved template has multiple {{n}} placeholders in order.
     */
    'otp_template_body_parameters_json' => env('WHATSAPP_OTP_TEMPLATE_BODY_PARAMETERS_JSON'),

    /**
     * Separate approved template for engagement reminders (inactive user job), if used.
     * Body variables: first line is usually a short message + URL (see ENGAGEMENT_INACTIVE_WHATSAPP_ENABLED).
     */
    'engagement_template_name' => env('WHATSAPP_ENGAGEMENT_TEMPLATE_NAME', ''),
    'engagement_template_language' => env('WHATSAPP_ENGAGEMENT_TEMPLATE_LANGUAGE', 'en_US'),
    'engagement_template_body_parameters_json' => env('WHATSAPP_ENGAGEMENT_TEMPLATE_BODY_PARAMETERS_JSON'),

    'http_timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 15),

    /** Webhook verification (Meta sends hub.verify_token on subscribe). */
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN', ''),

    /** App Secret — used to validate X-Hub-Signature-256 on inbound webhooks when set. */
    'app_secret' => env('WHATSAPP_APP_SECRET', ''),
];
