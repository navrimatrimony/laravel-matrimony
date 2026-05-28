<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ChatMessageLockedNotification;
use App\Notifications\PlanExpiringSoonNotification;
use App\Notifications\ReferralActivityNotification;
use App\Notifications\ReferralRewardGrantedNotification;
use App\Support\SafeNotifier;

/**
 * In-app (database) notifications for monetization and engagement — all copy is user-visible.
 */
class NotificationService
{
    public function notifyPlanExpiringSoon(User $user, Subscription $subscription, int $daysLeft): bool
    {
        if ($this->planExpiryReminderRecentlySent($user, (int) $subscription->id, $daysLeft)) {
            return false;
        }

        $plan = $subscription->plan;
        $name = $plan?->name ?? __('subscriptions.default_plan_name');
        $ends = $subscription->ends_at;
        $endsDisplay = $ends ? $ends->timezone(config('app.timezone'))->toDayDateTimeString() : '';

        SafeNotifier::notify($user, new PlanExpiringSoonNotification(
            $name,
            $daysLeft,
            $endsDisplay,
            (int) $subscription->id,
        ));

        return true;
    }

    /**
     * Avoid duplicate heads-up if the nightly job runs twice within the window.
     */
    private function planExpiryReminderRecentlySent(User $user, int $subscriptionId, int $daysLeft): bool
    {
        return $user->notifications()
            ->where('type', PlanExpiringSoonNotification::class)
            ->where('created_at', '>=', now()->subHours(48))
            ->get()
            ->contains(function ($n) use ($subscriptionId, $daysLeft): bool {
                $d = is_array($n->data) ? $n->data : [];

                return (int) ($d['subscription_id'] ?? 0) === $subscriptionId
                    && (int) ($d['days_left'] ?? 0) === $daysLeft;
            });
    }

    /**
     * Notify referrers when their invitee purchases a paid plan (reward already applied in DB).
     */
    /**
     * @param  array<string, int>  $featureBonus
     */
    public function notifyReferralReward(User $referrer, User $referredUser, int $bonusDays, string $purchasedPlanName, array $featureBonus = []): void
    {
        if (! AdminActivityNotificationGate::allowsPeerActivityNotification($referrer)) {
            return;
        }
        SafeNotifier::notify($referrer, new ReferralRewardGrantedNotification($bonusDays, $purchasedPlanName, $featureBonus));
    }

    public function notifyReferralInviteRegistered(User $referrer, string $inviteeDisplayName): void
    {
        $this->notifyReferralActivity($referrer, ReferralActivityNotification::TYPE_INVITE_REGISTERED, $inviteeDisplayName);
    }

    public function notifyReferralInviteUpgraded(User $referrer, string $inviteeDisplayName, string $planName): void
    {
        $this->notifyReferralActivity($referrer, ReferralActivityNotification::TYPE_INVITE_UPGRADED, $inviteeDisplayName, $planName);
    }

    public function notifyReferralRewardPending(User $referrer, string $inviteeDisplayName, string $planName, int $bonusDays): void
    {
        $this->notifyReferralActivity($referrer, ReferralActivityNotification::TYPE_REWARD_PENDING, $inviteeDisplayName, $planName, $bonusDays);
    }

    public function notifyReferralCapSkipped(User $referrer, string $inviteeDisplayName, string $planName): void
    {
        $this->notifyReferralActivity($referrer, ReferralActivityNotification::TYPE_CAP_SKIPPED, $inviteeDisplayName, $planName);
    }

    private function notifyReferralActivity(
        User $referrer,
        string $activityType,
        string $inviteeDisplayName,
        string $planName = '',
        int $bonusDays = 0,
    ): void {
        if (! AdminActivityNotificationGate::allowsPeerActivityNotification($referrer)) {
            return;
        }

        SafeNotifier::notify($referrer, new ReferralActivityNotification(
            $activityType,
            $inviteeDisplayName,
            $planName,
            $bonusDays,
        ));
    }

    /**
     * When the receiver cannot read chat per plan, still surface that a message is waiting (upgrade path is visible).
     */
    public function notifyChatReceivedWhileReadLocked(User $receiverUser, User $senderUser): void
    {
        if (! AdminActivityNotificationGate::allowsPeerActivityNotification($senderUser)) {
            return;
        }
        SafeNotifier::notify($receiverUser, new ChatMessageLockedNotification);
    }

    /**
     * Batch: subscriptions that end in exactly N days from “today” (per-day window).
     */
    public function notifySubscriptionsExpiringSoon(int $daysBefore): int
    {
        $daysBefore = max(1, $daysBefore);
        $target = now()->addDays($daysBefore)->startOfDay();
        $targetEnd = $target->copy()->endOfDay();

        $subs = Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$target, $targetEnd])
            ->with(['user', 'plan'])
            ->get();

        $sent = 0;
        foreach ($subs as $sub) {
            $user = $sub->user;
            if (! $user) {
                continue;
            }
            if ($this->notifyPlanExpiringSoon($user, $sub, $daysBefore)) {
                $sent++;
            }
        }

        return $sent;
    }
}
