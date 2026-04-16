<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralRewardGrantedNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function __construct(
        public int $bonusDays,
        public string $purchasedPlanName,
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
