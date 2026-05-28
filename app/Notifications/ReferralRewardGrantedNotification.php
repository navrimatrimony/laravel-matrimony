<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Services\ReferralService;
use App\Support\NotificationLocalization;
use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralRewardGrantedNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    /**
     * @param  array<string, int>  $featureBonus
     */
    public function __construct(
        public int $bonusDays,
        public string $purchasedPlanName,
        public array $featureBonus = [],
    ) {}

    public function via(object $notifiable): array
    {
        return $this->matrimonyNotificationChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->matrimonyMailFromPayload($this->toArray($notifiable), $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        $summaries = app(ReferralService::class)->formatRewardBenefitsSummaryBilingual(
            $this->bonusDays,
            $this->featureBonus,
        );

        if ($summaries['en'] !== '') {
            $message = NotificationLocalization::translate('notifications.referral_reward_message', [
                'plan' => $this->purchasedPlanName,
                'benefits' => $summaries['en'],
            ], NotificationLocalization::LOCALE_EN);
            $messageMr = NotificationLocalization::translate('notifications.referral_reward_message', [
                'plan' => $this->purchasedPlanName,
                'benefits' => $summaries['mr'],
            ], NotificationLocalization::LOCALE_MR);
        } else {
            $pair = NotificationLocalization::pair('notifications.referral_reward_message_days_only', [
                'days' => $this->bonusDays,
                'plan' => $this->purchasedPlanName,
            ]);
            $message = $pair['message'];
            $messageMr = $pair['message_mr'];
        }

        return NotificationMarathiPayload::withMessage([
            'type' => 'referral_reward',
            'bonus_days' => $this->bonusDays,
            'purchased_plan_name' => $this->purchasedPlanName,
            'feature_bonus' => $this->featureBonus,
            'benefits_summary' => $summaries['en'],
            'message' => $message,
            'message_mr' => $messageMr,
        ]);
    }
}
