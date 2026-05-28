<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| ImageApprovedNotification
|--------------------------------------------------------------------------
|
| 👉 Notifies user when their profile image is approved by admin
|
*/
class ImageApprovedNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $reason
    ) {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->matrimonyNotificationChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->matrimonyMailFromPayload($this->toArray($notifiable), $notifiable);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return NotificationMarathiPayload::withMessage([
            'type' => 'image_approved',
            'message' => __('notifications.image_approved_message'),
            'reason' => $this->reason,
            'mail_action_url' => route('matrimony.profile.upload-photo'),
            'mail_action_text' => __('notifications.view_profile'),
        ]);
    }
}
