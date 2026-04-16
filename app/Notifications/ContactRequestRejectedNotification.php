<?php

namespace App\Notifications;

use App\Models\ContactRequest;
use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Day-32 Step 7: Notify sender when their contact request is rejected.
 */
class ContactRequestRejectedNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function __construct(
        public ContactRequest $contactRequest
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
        $receiver = $this->contactRequest->receiver;
        $endsAt = $this->contactRequest->cooldown_ends_at
            ? $this->contactRequest->cooldown_ends_at->format('M j, Y')
            : 'later';
        return [
            'type' => 'contact_request_rejected',
            'message' => 'Your contact request was declined. You can send a new request after the cooling period (ends ' . $endsAt . ').',
            'contact_request_id' => $this->contactRequest->id,
            'cooldown_ends_at' => $this->contactRequest->cooldown_ends_at?->toIso8601String(),
            'receiver_profile_id' => (int) ($receiver?->matrimonyProfile?->id ?? 0) ?: null,
        ];
    }
}
