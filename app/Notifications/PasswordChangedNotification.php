<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security alert: the account password was changed.
 *
 * Written by {@see \App\Services\Account\MemberPasswordService} only. It is the
 * visible half of "we do not ask for the current password" — a change the member
 * did not make has to surface somewhere, and their other device is the one place
 * an attacker holding the phone cannot suppress.
 *
 * Carries no ids and no copy of the password. `type` is `password_changed`,
 * which is also the PushTypeRegistry key.
 */
class PasswordChangedNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function via(object $notifiable): array
    {
        return $this->matrimonyNotificationChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->matrimonyMailFromPayload($this->toArray($notifiable), $notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return NotificationMarathiPayload::withMessage([
            'type' => 'password_changed',
            'message_key' => 'notifications.password_changed_message',
        ]);
    }
}
