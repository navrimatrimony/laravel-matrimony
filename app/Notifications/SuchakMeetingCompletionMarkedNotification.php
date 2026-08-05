<?php

namespace App\Notifications;

use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Member-facing: Suchak marked a meeting complete — confirmation awaits (U8).
 *
 * Database channel only (RT-5). Push rides {@see \App\Listeners\SendPushForDatabaseNotification}.
 */
class SuchakMeetingCompletionMarkedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $visitId,
        public readonly string $scheduledDate,
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
            'type' => 'suchak_meeting_completion_marked',
            'message_key' => 'suchak.meeting_completion_marked',
            'message_params' => [
                'date' => $this->scheduledDate,
            ],
            'visit_id' => $this->visitId,
            'scheduled_date' => $this->scheduledDate,
        ]);
    }
}
