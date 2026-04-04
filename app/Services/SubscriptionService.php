<?php

namespace App\Services;

use App\Models\Interest;
use App\Models\Message;
use App\Models\Plan;
use App\Models\ProfileView;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Subscription limits are driven only by {@see Plan} + {@see PlanFeature} (admin-editable).
 * Users without a subscription row use the active "free" plan features.
 */
class SubscriptionService
{
    public const FEATURE_DAILY_CHAT_SEND_LIMIT = 'daily_chat_send_limit';

    public const FEATURE_MONTHLY_INTEREST_SEND_LIMIT = 'monthly_interest_send_limit';

    public const FEATURE_DAILY_PROFILE_VIEW_LIMIT = 'daily_profile_view_limit';

    /** "1" = can see contact number when policy allows (mutual interest, grants, etc.). */
    public const FEATURE_CONTACT_NUMBER_ACCESS = 'contact_number_access';

    /** "1" = may send chat images (in addition to communication policy). */
    public const FEATURE_CHAT_IMAGE_MESSAGES = 'chat_image_messages';

    public function subscribe(User $user, Plan $plan): Subscription
    {
        if (! $plan->is_active) {
            throw new HttpException(422, __('subscriptions.plan_inactive'));
        }

        return DB::transaction(function () use ($user, $plan) {
            $now = now();
            Subscription::query()
                ->where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update(['status' => Subscription::STATUS_CANCELLED, 'updated_at' => $now]);

            $duration = (int) $plan->duration_days;
            $endsAt = null;
            if ($duration > 0) {
                $endsAt = $now->copy()->addDays($duration);
            }

            return Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'status' => Subscription::STATUS_ACTIVE,
            ]);
        });
    }

    public function getActiveSubscription(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('starts_at')
            ->first();
    }

    public function getActivePlan(User $user): Plan
    {
        $sub = $this->getActiveSubscription($user);
        if ($sub) {
            return $sub->plan()->with('features')->firstOrFail();
        }

        return $this->defaultFreePlan();
    }

    /**
     * Effective plan for limits (subscription or default free).
     */
    public function getEffectivePlan(User $user): Plan
    {
        return $this->getActivePlan($user);
    }

    /**
     * Boolean gates for named product features (maps to plan_feature rows).
     */
    public function hasFeature(User $user, string $feature): bool
    {
        if ($user->isAnyAdmin()) {
            return true;
        }

        $plan = $this->getEffectivePlan($user);
        $plan->loadMissing('features');

        return match ($feature) {
            'chat' => $this->getFeatureLimit($user, self::FEATURE_DAILY_CHAT_SEND_LIMIT) !== 0,
            'interest' => $this->getFeatureLimit($user, self::FEATURE_MONTHLY_INTEREST_SEND_LIMIT) !== 0,
            'profile_views' => $this->getFeatureLimit($user, self::FEATURE_DAILY_PROFILE_VIEW_LIMIT) !== 0,
            'contact_number', 'see_contact' => $this->truthyFeature($plan, self::FEATURE_CONTACT_NUMBER_ACCESS),
            'chat_images' => $this->truthyFeature($plan, self::FEATURE_CHAT_IMAGE_MESSAGES),
            default => $this->truthyFeature($plan, $feature),
        };
    }

    /**
     * @return int -1 = unlimited, 0 = blocked
     */
    public function getFeatureLimit(User $user, string $key): int
    {
        if ($user->isAnyAdmin()) {
            return -1;
        }

        $plan = $this->getEffectivePlan($user);
        $plan->loadMissing('features');

        $raw = $plan->featureValue($key);
        if ($raw === null || $raw === '') {
            return $this->defaultLimitForKey($key);
        }

        return $this->parseLimitInt($raw);
    }

    public function assertHasFeature(User $user, string $feature): void
    {
        if (! $this->hasFeature($user, $feature)) {
            throw new HttpException(403, __('subscriptions.feature_locked'));
        }
    }

    public function assertWithinChatSendLimit(User $user): void
    {
        if ($user->isAnyAdmin()) {
            return;
        }
        $lim = $this->getFeatureLimit($user, self::FEATURE_DAILY_CHAT_SEND_LIMIT);
        if ($lim === -1) {
            return;
        }
        if ($lim === 0) {
            throw new HttpException(403, __('subscriptions.chat_locked'));
        }
        $used = $this->countChatSendsToday($user);
        if ($used >= $lim) {
            throw new HttpException(403, __('subscriptions.chat_daily_limit'));
        }
    }

    public function assertWithinInterestLimit(User $user): void
    {
        if ($user->isAnyAdmin()) {
            return;
        }
        $lim = $this->getFeatureLimit($user, self::FEATURE_MONTHLY_INTEREST_SEND_LIMIT);
        if ($lim === -1) {
            return;
        }
        if ($lim === 0) {
            throw new HttpException(403, __('subscriptions.interest_locked'));
        }
        $used = $this->countInterestsThisMonth($user);
        if ($used >= $lim) {
            throw new HttpException(403, __('subscriptions.interest_monthly_limit'));
        }
    }

    public function assertWithinProfileViewLimit(User $user): void
    {
        if ($user->isAnyAdmin()) {
            return;
        }
        $lim = $this->getFeatureLimit($user, self::FEATURE_DAILY_PROFILE_VIEW_LIMIT);
        if ($lim === -1) {
            return;
        }
        if ($lim === 0) {
            throw new HttpException(403, __('subscriptions.profile_views_locked'));
        }
        $used = $this->countProfileViewsToday($user);
        if ($used >= $lim) {
            throw new HttpException(403, __('subscriptions.profile_view_daily_limit'));
        }
    }

    public function canViewContactNumber(User $user): bool
    {
        return $this->hasFeature($user, 'contact_number');
    }

    public function canUseChatImages(User $user): bool
    {
        return $this->hasFeature($user, 'chat_images');
    }

    public function countChatSendsToday(User $user): int
    {
        $profile = $user->matrimonyProfile;
        if (! $profile) {
            return 0;
        }
        $start = now()->startOfDay();

        return (int) Message::query()
            ->where('sender_profile_id', $profile->id)
            ->where('sent_at', '>=', $start)
            ->count();
    }

    public function countInterestsThisMonth(User $user): int
    {
        $profile = $user->matrimonyProfile;
        if (! $profile) {
            return 0;
        }
        $start = now()->startOfMonth();

        return (int) Interest::query()
            ->where('sender_profile_id', $profile->id)
            ->where('created_at', '>=', $start)
            ->count();
    }

    public function countProfileViewsToday(User $user): int
    {
        $profile = $user->matrimonyProfile;
        if (! $profile) {
            return 0;
        }
        $start = now()->startOfDay();

        return (int) ProfileView::query()
            ->where('viewer_profile_id', $profile->id)
            ->where('created_at', '>=', $start)
            ->count();
    }

    private function defaultFreePlan(): Plan
    {
        $p = Plan::defaultFree();
        if ($p) {
            return $p->loadMissing('features');
        }

        return Plan::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail()->loadMissing('features');
    }

    private function truthyFeature(Plan $plan, string $key): bool
    {
        $v = strtolower(trim((string) ($plan->featureValue($key, '0') ?? '0')));

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private function parseLimitInt(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'unlimited') {
            return -1;
        }

        return (int) $raw;
    }

    private function defaultLimitForKey(string $key): int
    {
        return match ($key) {
            self::FEATURE_DAILY_CHAT_SEND_LIMIT => 10,
            self::FEATURE_MONTHLY_INTEREST_SEND_LIMIT => 5,
            self::FEATURE_DAILY_PROFILE_VIEW_LIMIT => -1,
            default => 0,
        };
    }
}
