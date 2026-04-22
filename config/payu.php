<?php

return [

    'merchant_key' => env('PAYU_MERCHANT_KEY', ''),

    'merchant_salt' => env('PAYU_MERCHANT_SALT', ''),

    /*
    |--------------------------------------------------------------------------
    | Checkout endpoint
    |--------------------------------------------------------------------------
    | Override with PAYU_CHECKOUT_URL if your account uses a custom host.
    */
    'checkout_url' => env('PAYU_CHECKOUT_URL', env('PAYU_MODE', 'test') === 'live'
        ? 'https://secure.payu.in/_payment'
        : 'https://test.payu.in/_payment'),

    /*
    |--------------------------------------------------------------------------
    | One-shot debug: dumps the exact PayU hash preimage string and exits.
    | Set PAYU_DEBUG_DD_HASH_STRING=true in .env, hit subscribe once, then remove.
    */
    'debug_dd_hash_string' => env('PAYU_DEBUG_DD_HASH_STRING', false),

];
