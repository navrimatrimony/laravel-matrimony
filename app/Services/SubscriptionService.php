<?php

namespace App\Services;

use App\Models\Interest;
use App\Models\Message;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanTerm;
use App\Models\ProfileView;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanFeatureKeys;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Subscription limits are driven only by {@see Plan} + {@see PlanFeature} (admin-editable).
 * Users without a subscription row use the active "free" plan features.
 */
class SubscriptionService
{
    public const FEATURE_CHAT_SEND_LIMIT = 'chat_send_limit';

    public const FEATURE_INTEREST_SEND_LIMIT = 'interest_send_limit';

    public const FEATURE_DAILY_PROFILE_VIEW_LIMIT = 'daily_profile_view_limit';

    /** "1" = may send chat images (in addition to communication policy). */
    public const FEATURE_CHAT_IMAGE_MESSAGES = 'chat_image_messages';

    public function subscribe(
        User $user,
        Plan $plan,
        ?int $planTermId = null,
        ?int $planPriceId = null,
        ?string $couponCode = null,
    ): Subscription {
        if (! $plan->is_active) {
            throw new HttpException(422, __('subscriptions.plan_inactive'));
        }

        $couponSvc = app(CouponService::class);
        $rawCoupon = trim((string) ($couponCode ?? ''));

        return DB::transaction(function () use ($user, $plan, $planTermId, $planPriceId, $rawCoupon, $couponSvc) {
            $now = now();

            // 1) Cancel any current active subscription(s) — rows kept for history (same as model safeguard on create).
            Subscription::deactivateActiveSubscriptionsForUserId((int) $user->id);

            $visiblePrices = PlanPrice::query()
                ->where('plan_id', $plan->id)
                ->where('is_visible', true)
                ->orderBy('sort_order')
                ->get();

            $planTerm = null;
            $planPrice = null;
            $duration = (int) $plan->duration_days;
            $coupon = null;

            if ($visiblePrices->isNotEmpty()) {
                if ($planPriceId === null) {
                    throw new HttpException(422, __('subscriptions.pick_billing_period'));
                }
                $planPrice = $visiblePrices->firstWhere('id', $planPriceId);
                if (! $planPrice) {
                    throw new HttpException(422, __('subscriptions.invalid_billing_period'));
                }
                $duration = (int) $planPrice->duration_days;
                if ($rawCoupon !== '') {
                    $coupon = $couponSvc->lockCouponByCode($rawCoupon);
                    if (! $coupon) {
                        throw new HttpException(422, __('subscriptions.coupon_invalid'));
                    }
                    $couponSvc->assertLockedCouponForCheckout(
                        $coupon,
                        (int) $plan->id,
                        (float) $planPrice->final_price,
                        (string) $planPrice->duration_type
                    );
                }
            } else {
                $visibleTerms = PlanTerm::query()
                    ->where('plan_id', $plan->id)
                    ->where('is_visible', true)
                    ->orderBy('sort_order')
                    ->get();

                if ($visibleTerms->isNotEmpty()) {
                    if ($planTermId === null) {
                        throw new HttpException(422, __('subscriptions.pick_billing_period'));
                    }
                    $planTerm = $visibleTerms->firstWhere('id', $planTermId);
                    if (! $planTerm) {
                        throw new HttpException(422, __('subscriptions.invalid_billing_period'));
                    }
                    $duration = (int) $planTerm->duration_days;
                    if ($rawCoupon !== '') {
                        $coupon = $couponSvc->lockCouponByCode($rawCoupon);
                        if (! $coupon) {
                            throw new HttpException(422, __('subscriptions.coupon_invalid'));
                        }
                        $couponSvc->assertLockedCouponForCheckout(
                            $coupon,
                            (int) $plan->id,
                            (float) $planTerm->final_price,
                            (string) $planTerm->billing_key
                        );
                    }
                } elseif ($rawCoupon !== '') {
                    $coupon = $couponSvc->lockCouponByCode($rawCoupon);
                    if (! $coupon) {
                        throw new HttpException(422, __('subscriptions.coupon_invalid'));
                    }
                    $couponSvc->assertLockedCouponForCheckout(
                        $coupon,
                        (int) $plan->id,
                        (float) $plan->final_price,
                        null
                    );
                }
            }

            $endsAt = null;
            if ($duration > 0) {
                $endsAt = $now->copy()->addDays($duration);
            }

            $sub = Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_term_id' => $planTerm?->id,
                'plan_price_id' => $planPrice?->id,
                'coupon_id' => $coupon?->id,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'status' => Subscription::STATUS_ACTIVE,
            ]);

            if ($coupon) {
                $couponSvc->incrementRedemption($coupon);
            }

            return $sub;
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

    public function getActivePlan(?User $user = null): Plan
    {
        if ($user !== null) {
            $sub = $this->getActiveSubscription($user);
            if ($sub) {
                return $sub->plan()->with('features')->firstOrFail();
            }
        }

        return $this->defaultFreePlan();
    }

    /**
     * Effective plan for limits (subscription or default free). Guest users resolve to default free / fallback.
     */
    public function getEffectivePlan(?User $user = null): Plan
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
            'chat' => $this->getFeatureLimit($user, self::FEATURE_CHAT_SEND_LIMIT) !== 0,
            'interest' => app(InterestSendLimitService::class)->effectiveDailyLimit($user) !== 0,
            'profile_views' => $this->getFeatureLimit($user, self::FEATURE_DAILY_PROFILE_VIEW_LIMIT) !== 0,
            'contact_number', 'see_contact' => $this->getFeatureLimit($user, PlanFeatureKeys::CONTACT_VIEW_LIMIT) !== 0,
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
        $lim = $this->getFeatureLimit($user, self::FEATURE_CHAT_SEND_LIMIT);
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
        app(InterestSendLimitService::class)->assertCanSend($user);
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

        $any = Plan::query()->where('is_active', true)->orderBy('sort_order')->first();
        if ($any) {
            return $any->loadMissing('features');
        }

        return $this->syntheticFallbackPlan();
    }

    /**
     * When no plan rows exist yet (migrations without seed), avoid ModelNotFoundException / 404 on public pages.
     */
    private function syntheticFallbackPlan(): Plan
    {
        $plan = new Plan([
            'name' => 'Free',
            'slug' => 'free',
            'price' => 0,
            'discount_percent' => null,
            'duration_days' => 0,
            'is_active' => true,
            'sort_order' => 0,
            'highlight' => false,
        ]);
        $plan->setRelation('features', collect());

        return $plan;
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
            self::FEATURE_CHAT_SEND_LIMIT => 10,
            self::FEATURE_INTEREST_SEND_LIMIT => 5,
            self::FEATURE_DAILY_PROFILE_VIEW_LIMIT => -1,
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => 0,
            default => 0,
        };
    }
}
