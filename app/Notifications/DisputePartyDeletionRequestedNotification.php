<?php

namespace App\Notifications;

use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Admin-facing: a member who is a party to an open Suchak dispute requested deletion (U3).
 *
 * Database channel only (RT-5). NOTIFY_ONLY — dispute rows are never mutated.
 */
class DisputePartyDeletionRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $customerFullName,
        public readonly string $eventDate,
        public readonly int $openDisputeCount,
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
            'type' => 'dispute_party_deletion_requested',
            'message_key' => 'account.dispute_party_deletion_requested',
            'message_params' => [
                'name' => $this->customerFullName,
                'date' => $this->eventDate,
                'count' => $this->openDisputeCount,
            ],
            'customer_full_name' => $this->customerFullName,
            'event_date' => $this->eventDate,
            'open_dispute_count' => $this->openDisputeCount,
        ]);
    }
}
