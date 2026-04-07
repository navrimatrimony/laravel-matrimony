<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ReferralRewardGrantedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $bonusDays,
        public string $purchasedPlanName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'referral_reward',
            'bonus_days' => $this->bonusDays,
            'purchased_plan_name' => $this->purchasedPlanName,
            'message' => __('notifications.referral_reward_message', [
                'days' => $this->bonusDays,
                'plan' => $this->purchasedPlanName,
            ]),
        ];
    }
}
