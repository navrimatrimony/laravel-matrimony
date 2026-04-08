<?php

namespace App\Notifications;

use App\Models\ContactRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Day-32 Step 7: Notify receiver when someone sends a contact request.
 */
class ContactRequestReceivedNotification extends Notification
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
        $sender = $this->contactRequest->sender;
        $name = $sender->matrimonyProfile->full_name ?? $sender->name ?? 'Someone';
        return [
            'type' => 'contact_request_received',
            'message' => "{$name} requested your contact.",
            'contact_request_id' => $this->contactRequest->id,
            'sender_id' => $this->contactRequest->sender_id,
            'sender_profile_id' => (int) ($sender->matrimonyProfile?->id ?? 0) ?: null,
        ];
    }
}
