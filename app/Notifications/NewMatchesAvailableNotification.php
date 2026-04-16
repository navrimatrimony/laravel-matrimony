<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Digest when the matching engine finds strong candidates (scheduled job).
 */
class NewMatchesAvailableNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function __construct(
        public int $matchCount,
        public int $topScore,
        public string $tab,
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
        return [
            'type' => 'new_matches_digest',
            'match_count' => $this->matchCount,
            'top_score' => $this->topScore,
            'tab' => $this->tab,
            'message' => __('notifications.new_matches_digest_message', [
                'count' => $this->matchCount,
                'score' => $this->topScore,
            ]),
            'mail_action_url' => route('matches.index', ['tab' => $this->tab], true),
            'mail_action_text' => __('mail.common.open_matches'),
        ];
    }
}
