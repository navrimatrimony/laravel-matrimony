<?php

namespace App\Notifications;

use App\Models\MatrimonyProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| InterestSentNotification (SSOT Day-10 — Recovery-Day-R5)
|--------------------------------------------------------------------------
| Notifies user when someone sends them interest.
*/
class InterestSentNotification extends Notification
{
    use Queueable;

    public function __construct(
        public MatrimonyProfile $senderProfile
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->senderProfile->full_name ?? 'Someone';
        return [
            'type' => 'interest_sent',
            'message' => "{$name} sent you an interest.",
            'sender_profile_id' => $this->senderProfile->id,
        ];
    }
}
