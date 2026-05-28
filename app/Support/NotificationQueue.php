<?php

namespace App\Support;

/**
 * Central toggles for notification-related queue usage (see config/notifications.php).
 */
final class NotificationQueue
{
    public static function mailEnabled(): bool
    {
        return (bool) config('notifications.queue.mail_enabled', false);
    }

    public static function mailConnection(): ?string
    {
        $connection = config('notifications.queue.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function mailQueueName(): string
    {
        return (string) config('notifications.queue.name', 'notifications');
    }

    public static function engagementBatchesEnabled(): bool
    {
        return (bool) config('notifications.queue.engagement_batches', false);
    }

    public static function engagementConnection(): ?string
    {
        $connection = config('notifications.queue.engagement_connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function engagementQueueName(): string
    {
        return (string) config('notifications.queue.engagement_name', 'notifications');
    }
}
