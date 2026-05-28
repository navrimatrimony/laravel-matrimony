<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Notifications\Support\MatrimonyMailTemplate;
use App\Services\UserNotificationPreferencesService;
use App\Support\NotificationLocalization;
use App\Support\NotificationQueue;
use Illuminate\Notifications\Messages\MailMessage;

trait SendsMatrimonyMailChannel
{
    /**
     * @return list<string>
     */
    protected function matrimonyNotificationChannels(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User
            && app(UserNotificationPreferencesService::class)->emailAlertsEnabled($notifiable)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function matrimonyMailFromPayload(array $payload, object $notifiable): MailMessage
    {
        $user = $notifiable instanceof User ? $notifiable : null;
        $locale = NotificationLocalization::preferredLocaleForUser($user);
        $payload['message'] = NotificationLocalization::displayMessage($payload, $locale);

        return MatrimonyMailTemplate::fromToArray($payload, $locale);
    }

    /**
     * In-app alerts stay on the default (sync) connection; mail copies use the notifications queue.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        if (! NotificationQueue::mailEnabled()) {
            return [];
        }

        $connection = NotificationQueue::mailConnection();

        return $connection !== null ? ['mail' => $connection] : [];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        if (! NotificationQueue::mailEnabled()) {
            return [];
        }

        return ['mail' => NotificationQueue::mailQueueName()];
    }
}
