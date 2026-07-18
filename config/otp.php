<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile OTP delivery mode
    |--------------------------------------------------------------------------
    |
    | LOCAL / testing  → always "dev" (unless overridden)
    | STAGING          → MOBILE_OTP_DELIVERY=dev|whatsapp (default: whatsapp if
    |                    configured, else dev so QA is never blocked)
    | PRODUCTION       → always "whatsapp" (MOBILE_OTP_DELIVERY=dev is ignored)
    |
    | Explicit override: MOBILE_OTP_DELIVERY=dev|whatsapp
    |
    */
    'delivery' => env('MOBILE_OTP_DELIVERY', ''),

];
