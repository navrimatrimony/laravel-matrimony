<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email channel for in-app notifications
    |--------------------------------------------------------------------------
    |
    | When enabled, database notifications that implement the matrimony mail
    | template will also send email if the user has a non-empty email address.
    | Queue worker + MAIL_* must be configured on the server for delivery.
    |
    */
    'mail' => [
        'enabled' => env('NOTIFICATION_MAIL_ENABLED', true),
    ],

];
