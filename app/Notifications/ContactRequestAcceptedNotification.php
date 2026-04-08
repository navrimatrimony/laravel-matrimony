<?php

namespace App\Notifications;

use App\Models\ContactGrant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Day-32 Step 7: Notify sender when their contact request is accepted.
 */
class ContactRequestAcceptedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ContactGrant $grant
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $receiver = $this->grant->contactRequest->receiver;
        $name = $receiver->matrimonyProfile->full_name ?? $receiver->name ?? 'They';
        $validUntil = $this->grant->valid_until->format('M j, Y');
        return [
            'type' => 'contact_request_accepted',
            'message' => "{$name} approved your contact request. Shared: Phone (primary). Valid until {$validUntil}.",
            'contact_request_id' => $this->grant->contact_request_id,
            'contact_grant_id' => $this->grant->id,
            'receiver_profile_id' => (int) ($receiver->matrimonyProfile?->id ?? 0) ?: null,
        ];
    }
}
