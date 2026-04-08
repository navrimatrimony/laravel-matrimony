<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AdminActivityNotificationGate;
use App\Notifications\ChatMessageLockedNotification;
use App\Notifications\PlanExpiringSoonNotification;
use App\Notifications\ReferralRewardGrantedNotification;

/**
 * In-app (database) notifications for monetization and engagement — all copy is user-visible.
 */
class NotificationService
{
    public function notifyPlanExpiringSoon(User $user, Subscription $subscription, int $daysLeft): void
    {
        $plan = $subscription->plan;
        $name = $plan?->name ?? __('subscriptions.default_plan_name');
        $ends = $subscription->ends_at;
        $endsDisplay = $ends ? $ends->timezone(config('app.timezone'))->toDayDateTimeString() : '';

        $user->notify(new PlanExpiringSoonNotification($name, $daysLeft, $endsDisplay));
    }

    /**
     * Notify referrers when their invitee purchases a paid plan (reward already applied in DB).
     */
    public function notifyReferralReward(User $referrer, User $referredUser, int $bonusDays, string $purchasedPlanName): void
    {
        if (! AdminActivityNotificationGate::allowsPeerActivityNotification($referrer)) {
            return;
        }
        $referrer->notify(new ReferralRewardGrantedNotification($bonusDays, $purchasedPlanName));
    }

    /**
     * When the receiver cannot read chat per plan, still surface that a message is waiting (upgrade path is visible).
     */
    public function notifyChatReceivedWhileReadLocked(User $receiverUser, MatrimonyProfile $senderProfile, int $conversationId): void
    {
        $senderProfile->loadMissing('user');
        if (! AdminActivityNotificationGate::allowsPeerActivityNotification($senderProfile->user)) {
            return;
        }
        $receiverUser->notify(new ChatMessageLockedNotification($senderProfile, $conversationId));
    }

    /**
     * Batch: subscriptions that end in exactly N days (idempotent per run via notification title hash — lightweight).
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
            $this->notifyPlanExpiringSoon($user, $sub, $daysBefore);
            $sent++;
        }

        return $sent;
    }
}
