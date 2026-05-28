<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReferralActivityNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public const TYPE_INVITE_REGISTERED = 'referral_invite_registered';

    public const TYPE_INVITE_UPGRADED = 'referral_invite_upgraded';

    public const TYPE_REWARD_PENDING = 'referral_reward_pending';

    public const TYPE_CAP_SKIPPED = 'referral_cap_skipped';

    public function __construct(
        public string $activityType,
        public string $inviteeDisplayName,
        public string $planName = '',
        public int $bonusDays = 0,
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
        $messageKey = match ($this->activityType) {
            self::TYPE_INVITE_REGISTERED => 'notifications.referral_invite_registered_message',
            self::TYPE_INVITE_UPGRADED => 'notifications.referral_invite_upgraded_message',
            self::TYPE_REWARD_PENDING => 'notifications.referral_reward_pending_message',
            self::TYPE_CAP_SKIPPED => 'notifications.referral_cap_skipped_message',
            default => 'notifications.referral_invite_registered_message',
        };

        $actionUrl = $this->activityType === self::TYPE_REWARD_PENDING
            ? route('plans.index')
            : route('referrals.index');

        $actionText = $this->activityType === self::TYPE_REWARD_PENDING
            ? __('referrals.view_plans_claim')
            : __('referrals.dashboard_card_link');

        $params = [
            'name' => $this->inviteeDisplayName,
            'plan' => $this->planName !== '' ? $this->planName : __('referrals.member_placeholder'),
            'days' => $this->bonusDays,
        ];

        return NotificationMarathiPayload::withMessage([
            'type' => $this->activityType,
            'message_key' => $messageKey,
            'message_params' => $params,
            'invitee_name' => $this->inviteeDisplayName,
            'plan_name' => $this->planName,
            'bonus_days' => $this->bonusDays,
            'mail_action_url' => $actionUrl,
            'mail_action_text' => $actionText,
        ]);
    }
}
