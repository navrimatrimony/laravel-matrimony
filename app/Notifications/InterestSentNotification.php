<?php

namespace App\Notifications;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Services\InterestSendLimitService;
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
        $interest = Interest::query()
            ->where('sender_profile_id', $this->senderProfile->id)
            ->where('receiver_profile_id', $notifiable->matrimonyProfile?->id ?? 0)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        $unlocked = true;
        if ($notifiable->matrimonyProfile && $interest) {
            $unlocked = app(InterestSendLimitService::class)->isIncomingInterestUnlocked($notifiable, $interest);
        }

        if ($unlocked) {
            $name = $this->senderProfile->full_name ?? 'Someone';

            return [
                'type' => 'interest_sent',
                'message' => "{$name} sent you an interest.",
                'sender_profile_id' => $this->senderProfile->id,
                'revealed' => true,
            ];
        }

        return [
            'type' => 'interest_sent',
            'message' => __('interests.notification_blurred_sender'),
            'sender_profile_id' => null,
            'revealed' => false,
        ];
    }
}
