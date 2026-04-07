<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PlanExpiringSoonNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $planName,
        public int $daysLeft,
        public string $endsAtDisplay,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'plan_expiring_soon',
            'plan_name' => $this->planName,
            'days_left' => $this->daysLeft,
            'ends_at_display' => $this->endsAtDisplay,
            'message' => __('notifications.plan_expiring_soon_message', [
                'plan' => $this->planName,
                'days' => $this->daysLeft,
            ]),
        ];
    }
}
