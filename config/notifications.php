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

    /*
    |--------------------------------------------------------------------------
    | Queues (mail copies + scheduled engagement batches)
    |--------------------------------------------------------------------------
    |
    | In-app (database) notifications stay synchronous. Mail copies and heavy
    | engagement cron work can run on the queue when enabled below.
    | Requires: php artisan queue:work (and jobs table when using database driver).
    |
    */
    'queue' => [
        'mail_enabled' => filter_var(
            env('NOTIFICATION_MAIL_QUEUE_ENABLED', env('QUEUE_CONNECTION', 'sync') !== 'sync'),
            FILTER_VALIDATE_BOOL
        ),
        'connection' => env('NOTIFICATION_QUEUE_CONNECTION'),
        'name' => env('NOTIFICATION_QUEUE_NAME', 'notifications'),
        'engagement_batches' => filter_var(
            env('NOTIFICATION_ENGAGEMENT_QUEUE_ENABLED', env('QUEUE_CONNECTION', 'sync') !== 'sync'),
            FILTER_VALIDATE_BOOL
        ),
        'engagement_connection' => env('NOTIFICATION_ENGAGEMENT_QUEUE_CONNECTION'),
        'engagement_name' => env('NOTIFICATION_ENGAGEMENT_QUEUE_NAME', 'notifications'),
    ],

];
