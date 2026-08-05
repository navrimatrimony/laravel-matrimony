<?php

namespace App\Notifications;

use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Suchak-facing: a represented customer cancelled a pending account deletion.
 *
 * Database channel only (RT-5). Push rides {@see \App\Listeners\SendPushForDatabaseNotification}.
 * Payload is privacy-validated: customer name + date only (U2).
 */
class SuchakCustomerDeletionCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $customerFullName,
        public readonly string $eventDate,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return NotificationMarathiPayload::withMessage([
            'type' => 'suchak_customer_deletion_cancelled',
            'message_key' => 'account.suchak_customer_deletion_cancelled',
            'message_params' => [
                'name' => $this->customerFullName,
                'date' => $this->eventDate,
            ],
            'customer_full_name' => $this->customerFullName,
            'event_date' => $this->eventDate,
        ]);
    }
}
