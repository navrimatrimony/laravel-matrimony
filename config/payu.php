<?php

return [
    'merchant_key' => env('PAYU_KEY'),
    'merchant_salt' => env('PAYU_SALT'),
    'checkout_url' => env('PAYU_BASE_URL'),
    // Optional: required only when CheckoutPro requests mcpLookup (MCP merchants).
    'merchant_secret' => env('PAYU_MERCHANT_SECRET'),

    /*
    | Optional PayU verify_payment webservice (postservice).
    | When enabled=false or URL/credentials missing, member activation keeps
    | reverse-hash-only behaviour via MemberPayuActivationService.
    */
    'verify_payment' => [
        'enabled' => (bool) env('PAYU_VERIFY_PAYMENT_ENABLED', false),
        // Empty → derived from checkout_url (test.payu.in vs info.payu.in).
        'url' => env('PAYU_VERIFY_PAYMENT_URL'),
        'timeout_seconds' => (int) env('PAYU_VERIFY_PAYMENT_TIMEOUT', 15),
    ],
];
