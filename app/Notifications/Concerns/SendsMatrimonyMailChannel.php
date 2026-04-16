<?php

namespace App\Notifications\Concerns;

use App\Models\User;

trait SendsMatrimonyMailChannel
{
    /**
     * @return list<string>
     */
    protected function matrimonyNotificationChannels(object $notifiable): array
    {
        $channels = ['database'];

        if (! config('notifications.mail.enabled', true)) {
            return $channels;
        }

        if ($notifiable instanceof User && is_string($notifiable->email) && trim($notifiable->email) !== '') {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
