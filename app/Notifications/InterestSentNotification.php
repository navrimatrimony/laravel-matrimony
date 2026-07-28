<?php

namespace App\Notifications;

use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use App\Services\Interest\ReceivedInterestTeaserPolicy;
use App\Services\InterestSendLimitService;
use App\Services\WhoViewed\NotificationTeaserRenderer;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;
use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| InterestSentNotification (SSOT Day-10 — Recovery-Day-R5)
|--------------------------------------------------------------------------
| Notifies user when someone sends them interest.
*/
class InterestSentNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function __construct(
        public MatrimonyProfile $senderProfile
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
        $interest = Interest::query()
            ->where('sender_profile_id', $this->senderProfile->id)
            ->where('receiver_profile_id', $notifiable->matrimonyProfile?->id ?? 0)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        $unlocked = true;
        if ($notifiable->matrimonyProfile && $interest) {
            $unlocked = app(InterestSendLimitService::class)->isIncomingInterestUnlocked($notifiable, $interest);
        }

        if ($unlocked) {
            $name = $this->senderProfile->full_name ?? 'Someone';

            return NotificationMarathiPayload::withMessage([
                'type' => 'interest_sent',
                'message' => "{$name} sent you an interest.",
                // Named separately from `message` so the push copy never has to
                // parse an English sentence back apart to find the name.
                'sender_name' => $name,
                'sender_profile_id' => $this->senderProfile->id,
                'revealed' => true,
            ]);
        }

        $receiverProfile = $notifiable->matrimonyProfile;
        $teaser = null;
        if ($receiverProfile !== null) {
            $policy = ReceivedInterestTeaserPolicy::forLockedPresentation(ReceivedInterestTeaserPolicy::normalized());
            $at = $interest?->created_at ?? now();
            $teaser = app(WhoViewedTeaserPresenter::class)->presentFromMatrimonyProfile(
                $this->senderProfile,
                $at,
                $policy,
                [
                    'owner_profile' => $receiverProfile,
                    'teaser_time_line' => 'interest_received',
                ],
            );
        }

        return NotificationMarathiPayload::withMessage([
            'type' => 'interest_sent',
            'message' => __('interests.notification_blurred_sender'),
            'sender_profile_id' => null,
            'revealed' => false,
            // Server-side only, and deliberately NOT `sender_profile_id` — that key
            // stays null precisely because this reader may not see the sender, and it
            // is scanned to build a tappable profile link. This one lets the server
            // rebuild the same MASKED card in the reader's language later; it is in
            // no link scanner. See NotificationTeaserRenderer.
            NotificationTeaserRenderer::ACTOR_PROFILE_ID_KEY => (int) $this->senderProfile->id,
            'teaser' => $teaser,
            'teaser_plans_url' => route('plans.index'),
            'teaser_context_url' => route('interests.received'),
            'teaser_context_label' => __('notifications.teaser_open_received_interests'),
            'mail_action_url' => route('interests.received'),
            'mail_action_text' => __('notifications.teaser_open_received_interests'),
        ]);
    }
}
