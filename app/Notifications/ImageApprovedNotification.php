<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
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
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'image_approved',
            'message' => 'Your profile image has been approved by admin.',
            'reason' => $this->reason,
        ];
    }
}
