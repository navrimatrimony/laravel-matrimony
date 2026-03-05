<?php

namespace App\Notifications;

use App\Models\ContactRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Day-32 Step 7: Notify sender when their pending contact request expires.
 */
class ContactRequestExpiredNotification extends Notification
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
        return [
            'type' => 'contact_request_expired',
            'message' => 'Your contact request was not responded to and has expired. You can send a new request if you wish.',
            'contact_request_id' => $this->contactRequest->id,
        ];
    }
}
