<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| ProfileSoftDeletedNotification
|--------------------------------------------------------------------------
|
| 👉 Notifies user when their profile is soft deleted by admin
|
*/
class ProfileSoftDeletedNotification extends Notification
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
            'type' => 'profile_soft_deleted',
            'message' => 'Your matrimony profile has been deleted.',
            'reason' => $this->reason,
        ];
    }
}
