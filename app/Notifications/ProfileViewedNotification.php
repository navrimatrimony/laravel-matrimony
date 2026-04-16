<?php

namespace App\Notifications;

use App\Models\MatrimonyProfile;
use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| ProfileViewedNotification (SSOT Day-9 — Recovery-Day-R4)
|--------------------------------------------------------------------------
|
| Notifies user when their profile is viewed (or view-back by showcase).
|
*/
class ProfileViewedNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function __construct(
        public MatrimonyProfile $viewerProfile,
        public bool $isViewBack = false
    ) {}

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
        $name = $this->viewerProfile->full_name ?? 'Someone';
        $message = "{$name} viewed your profile.";

        return [
            'type' => 'profile_viewed',
            'message' => $message,
            'viewer_profile_id' => $this->viewerProfile->id,
            'is_view_back' => $this->isViewBack,
        ];
    }
}
