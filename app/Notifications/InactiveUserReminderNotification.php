<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when the member has been away for several days (scheduled job).
 */
class InactiveUserReminderNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function via(object $notifiable): array
    {
        return $this->matrimonyNotificationChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return MatrimonyMailTemplate::fromToArray($this->toArray($notifiable));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inactive_reminder',
            'message' => __('notifications.inactive_reminder_message', [
                'name' => $notifiable->name ?? __('notifications.fellow_member'),
            ]),
            'mail_action_url' => url(route('dashboard', [], false)),
            'mail_action_text' => __('mail.common.open_dashboard'),
        ];
    }
}
