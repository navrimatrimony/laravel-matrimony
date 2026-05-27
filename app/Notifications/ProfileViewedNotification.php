<?php

namespace App\Notifications;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Models\User;
use App\Notifications\Concerns\SendsMatrimonyMailChannel;
use App\Notifications\Support\MatrimonyMailTemplate;
use App\Services\WhoViewed\WhoViewedNotificationIdentityGate;
use App\Services\WhoViewed\WhoViewedTeaserPolicy;
use App\Services\WhoViewed\WhoViewedTeaserPresenter;
use App\Support\NotificationMarathiPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/*
|--------------------------------------------------------------------------
| ProfileViewedNotification (SSOT Day-9 — Recovery-Day-R4)
|--------------------------------------------------------------------------
|
| Notifies user when their profile is viewed (or view-back by showcase).
|
*/
class ProfileViewedNotification extends Notification
{
    use Queueable;
    use SendsMatrimonyMailChannel;

    public function __construct(
        public MatrimonyProfile $viewerProfile,
        public bool $isViewBack = false
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
        $viewerId = (int) $this->viewerProfile->id;
        $token = $notifiable instanceof User
            ? WhoViewedNotificationIdentityGate::viewerDedupeToken($notifiable, $viewerId)
            : '';

        $reveal = $notifiable instanceof User
            && WhoViewedNotificationIdentityGate::mayRevealViewerInProfileViewNotification($notifiable, $viewerId);

        if ($reveal) {
            $name = $this->viewerProfile->full_name ?? 'Someone';
            $message = "{$name} viewed your profile.";

            return NotificationMarathiPayload::withMessage([
                'type' => 'profile_viewed',
                'message' => $message,
                'viewer_profile_id' => $this->viewerProfile->id,
                'is_view_back' => $this->isViewBack,
                'revealed' => true,
                'viewer_dedupe_token' => $token,
            ]);
        }

        $teaser = null;
        $owner = $notifiable instanceof User ? $notifiable->matrimonyProfile : null;
        if ($owner !== null) {
            $viewerViewCount = ProfileView::query()
                ->where('viewed_profile_id', $owner->id)
                ->where('viewer_profile_id', $viewerId)
                ->count();
            $policy = WhoViewedTeaserPolicy::forWhoViewedLockedTeasers(WhoViewedTeaserPolicy::normalized());
            $teaser = app(WhoViewedTeaserPresenter::class)->presentFromMatrimonyProfile(
                $this->viewerProfile,
                now(),
                $policy,
                [
                    'owner_profile' => $owner,
                    'viewer_view_count' => $viewerViewCount,
                    'teaser_time_line' => 'profile_view',
                ],
            );
        }

        return NotificationMarathiPayload::withMessage([
            'type' => 'profile_viewed',
            'message' => __('who_viewed.notification_profile_viewed_anonymous'),
            'is_view_back' => $this->isViewBack,
            'revealed' => false,
            'viewer_dedupe_token' => $token,
            'teaser' => $teaser,
            'teaser_plans_url' => route('plans.index'),
            'teaser_context_url' => route('who-viewed.index'),
            'teaser_context_label' => __('notifications.teaser_open_who_viewed'),
            'mail_action_url' => route('who-viewed.index'),
            'mail_action_text' => __('notifications.teaser_open_who_viewed'),
        ]);
    }
}
