<?php

namespace App\Notifications;

use App\Models\MatrimonyProfile;
use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
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
    use SendsMatrimonyMailChannel;

    public function __construct(
        public MatrimonyProfile $accepterProfile
    ) {}

    public function via(object $notifiable): array
    {
        return $this->matrimonyNotificationChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->matrimonyMailFromPayload($this->toArray($notifiable), $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->accepterProfile->full_name ?? 'Someone';

        return NotificationMarathiPayload::withMessage([
            'type' => 'interest_accepted',
            'message' => "{$name} accepted your interest.",
            // Named separately from `message` so the push copy never has to parse
            // an English sentence back apart to find the name. Acceptance is only
            // ever sent to the member who sent the interest, so this is revealed.
            'accepter_name' => $name,
            'accepter_profile_id' => $this->accepterProfile->id,
        ]);
    }
}
