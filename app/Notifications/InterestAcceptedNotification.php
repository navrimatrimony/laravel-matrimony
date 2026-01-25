<?php

namespace App\Notifications;

use App\Models\MatrimonyProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| InterestAcceptedNotification (SSOT Day-10 — Recovery-Day-R5)
|--------------------------------------------------------------------------
| Notifies sender when their interest is accepted.
*/
class InterestAcceptedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public MatrimonyProfile $accepterProfile
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->accepterProfile->full_name ?? 'Someone';
        return [
            'type' => 'interest_accepted',
            'message' => "{$name} accepted your interest.",
            'accepter_profile_id' => $this->accepterProfile->id,
        ];
    }
}
