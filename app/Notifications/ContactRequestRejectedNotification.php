<?php

namespace App\Notifications;

use App\Models\ContactRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Day-32 Step 7: Notify sender when their contact request is rejected.
 */
class ContactRequestRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ContactRequest $contactRequest
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $endsAt = $this->contactRequest->cooldown_ends_at
            ? $this->contactRequest->cooldown_ends_at->format('M j, Y')
            : 'later';
        return [
            'type' => 'contact_request_rejected',
            'message' => 'Your contact request was declined. You can send a new request after the cooling period (ends ' . $endsAt . ').',
            'contact_request_id' => $this->contactRequest->id,
            'cooldown_ends_at' => $this->contactRequest->cooldown_ends_at?->toIso8601String(),
        ];
    }
}
