<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'tesseract' => [
        'path' => env('TESSERACT_PATH'),
    ],

    /*
    | Vision API (image-to-text, e.g. Google Cloud Vision).
    | Use config('services.vision.key') and config('services.vision.url') in code.
    */
    'vision' => [
        'key' => env('VISION_API_KEY'),
        'url' => env('VISION_API_URL', 'https://vision.googleapis.com/v1/images:annotate'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'url' => env('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'moderation_url' => env('OPENAI_MODERATION_URL', 'https://api.openai.com/v1/moderations'),
        'moderation_model' => env('OPENAI_MODERATION_MODEL', 'omni-moderation-latest'),
    ],

    'sarvam' => [
        // Sarvam API (header: api-subscription-key); used for optional match-boost chat completions.
        'subscription_key' => env('SARVAM_API_SUBSCRIPTION_KEY'),
        'base_url' => env('SARVAM_API_BASE_URL', 'https://api.sarvam.ai'),
        'chat_model' => env('SARVAM_CHAT_MODEL', 'sarvam-105b'),
        'timeout' => (int) env('SARVAM_HTTP_TIMEOUT', 20),
    ],

    /*
    | Local NudeNet HTTP API (multipart field name: file).
    */
    'nudenet' => [
        /*
         * Python/FastAPI NudeNet service. Port 8000 is often the Laravel app — use the detector port (e.g. 8001).
         */
        'url' => env('NUDENET_DETECT_URL', 'http://127.0.0.1:8001/detect'),
        'timeout' => (int) env('NUDENET_TIMEOUT', 15),
    ],

];
