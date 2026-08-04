<?php

return [
    'merchant_key' => env('PAYU_KEY'),
    'merchant_salt' => env('PAYU_SALT'),
    'checkout_url' => env('PAYU_BASE_URL'),
    // Optional: required only when CheckoutPro requests mcpLookup (MCP merchants).
    'merchant_secret' => env('PAYU_MERCHANT_SECRET'),
];
