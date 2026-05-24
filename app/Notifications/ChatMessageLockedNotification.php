<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChatMessageLockedNotification extends Notification
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'chat_message_locked',
            'message' => __('notifications.chat_locked_message_anonymous'),
            'revealed' => false,
            'mail_action_url' => route('plans.index'),
            'mail_action_text' => __('who_viewed.upgrade_cta'),
        ];
    }
}
