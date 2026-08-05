<?php

namespace App\Notifications;

use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Suchak-facing: a proposal arrived on a challenge this Suchak published (U12).
 *
 * Database channel only (RT-5). Push rides {@see \App\Listeners\SendPushForDatabaseNotification}.
 */
class MarketplaceProposalReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $challengeId,
        public readonly string $proposerSuchakName,
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
            'type' => 'marketplace_proposal_received',
            'message_key' => 'suchak.marketplace_proposal_received',
            'message_params' => [
                'name' => $this->proposerSuchakName,
            ],
            'challenge_id' => $this->challengeId,
            'proposer_suchak_name' => $this->proposerSuchakName,
        ]);
    }
}
