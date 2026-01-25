<?php

namespace App\Notifications;

use App\Models\MatrimonyProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| InterestRejectedNotification (SSOT Day-10 — Recovery-Day-R5)
|--------------------------------------------------------------------------
| Notifies sender when their interest is rejected.
*/
class InterestRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public MatrimonyProfile $rejecterProfile
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->rejecterProfile->full_name ?? 'Someone';
        return [
            'type' => 'interest_rejected',
            'message' => "{$name} declined your interest.",
            'rejecter_profile_id' => $this->rejecterProfile->id,
        ];
    }
}
