<?php

namespace App\Notifications;

use App\Models\MatrimonyProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| ProfileViewedNotification (SSOT Day-9 — Recovery-Day-R4)
|--------------------------------------------------------------------------
|
| Notifies user when their profile is viewed (or view-back by demo).
|
*/
class ProfileViewedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public MatrimonyProfile $viewerProfile,
        public bool $isViewBack = false
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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
