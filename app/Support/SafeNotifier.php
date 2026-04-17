<?php

namespace App\Support;

use Illuminate\Notifications\Notification;

/**
 * Wraps {@see \Illuminate\Notifications\RoutesNotifications::notify} so mail transport failures
 * (e.g. misconfigured SMTP, Symfony AbstractStream edge cases) do not bubble as HTTP 500s.
 */
final class SafeNotifier
{
    public static function notify(object $notifiable, Notification $notification): void
    {
        try {
            $notifiable->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
