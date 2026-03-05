<?php

namespace App\Notifications;

use App\Models\ContactGrant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Day-32 Step 7: Notify sender when their contact access is revoked.
 */
class ContactGrantRevokedNotification extends Notification
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
        return [
            'type' => 'contact_grant_revoked',
            'message' => 'Your access to their contact has been revoked.',
            'contact_request_id' => $this->grant->contact_request_id,
            'contact_grant_id' => $this->grant->id,
        ];
    }
}
