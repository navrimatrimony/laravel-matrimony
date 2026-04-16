<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlanExpiringSoonNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function __construct(
        public string $planName,
        public int $daysLeft,
        public string $endsAtDisplay,
        public ?int $subscriptionId = null,
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
            'type' => 'plan_expiring_soon',
            'plan_name' => $this->planName,
            'days_left' => $this->daysLeft,
            'ends_at_display' => $this->endsAtDisplay,
            'subscription_id' => $this->subscriptionId,
            'message' => __('notifications.plan_expiring_soon_message', [
                'plan' => $this->planName,
                'days' => $this->daysLeft,
            ]),
        ];
    }
}
