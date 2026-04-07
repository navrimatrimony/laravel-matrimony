<?php

namespace App\Services;

use App\Models\Coupon;
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
        if ($rawCoupon !== '' && ! config('monetization.coupons.enabled', true)) {
            throw new HttpException(422, __('subscriptions.coupon_invalid'));
        }

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
            $baseAmount = (float) $plan->final_price;
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
                $baseAmount = (float) $planPrice->final_price;
                if ($rawCoupon !== '') {
                    $coupon = $couponSvc->lockCouponByCode($rawCoupon);
                    if (! $coupon) {
                        throw new HttpException(422, __('subscriptions.coupon_invalid'));
                    }
                    $couponSvc->assertLockedCouponForCheckout(
                        $coupon,
                        (int) $plan->id,
                        $baseAmount,
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
                    $baseAmount = (float) $planTerm->final_price;
                    if ($rawCoupon !== '') {
                        $coupon = $couponSvc->lockCouponByCode($rawCoupon);
                        if (! $coupon) {
                            throw new HttpException(422, __('subscriptions.coupon_invalid'));
                        }
                        $couponSvc->assertLockedCouponForCheckout(
                            $coupon,
                            (int) $plan->id,
                            $baseAmount,
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
                        $baseAmount,
                        null
                    );
                }
            }

            $subscriptionMeta = [];
            if ($coupon) {
                $applied = $this->applyCoupon($coupon, $baseAmount);
                $duration += (int) ($applied['extra_duration_days'] ?? 0);
                $subscriptionMeta = $applied['subscription_meta'] ?? [];
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
                'meta' => $subscriptionMeta !== [] ? $subscriptionMeta : null,
            ]);

            if ($coupon) {
                $couponSvc->incrementRedemption($coupon);
            }

            if ($coupon && $coupon->type === Coupon::TYPE_FEATURE) {
                $this->applyFeatureCouponGrants($sub, $coupon);
            }

            app(ReferralService::class)->applyPurchaseRewardIfEligible($user, $plan);

            return $sub;
        });
    }

    /**
     * @return array{
     *     discount_amount: float,
     *     final_price: float,
     *     extra_duration_days: int,
     *     subscription_meta: array<string, mixed>
     * }
     */
    public function applyCoupon(?Coupon $coupon, float $planPrice): array
    {
        if (! $coupon || ! config('monetization.coupons.enabled', true)) {
            $planPrice = max(0, round($planPrice, 2));

            return [
                'discount_amount' => 0.0,
                'final_price' => $planPrice,
                'extra_duration_days' => 0,
                'subscription_meta' => [],
            ];
        }

        $planPrice = max(0, round($planPrice, 2));
        $couponSvc = app(CouponService::class);

        return match ($coupon->type) {
            Coupon::TYPE_DAYS => [
                'discount_amount' => 0.0,
                'final_price' => $planPrice,
                'extra_duration_days' => max(0, (int) round((float) $coupon->value)),
                'subscription_meta' => [
                    'coupon_applied' => [
                        'type' => $coupon->type,
                        'code' => $coupon->code,
                        'extra_days' => max(0, (int) round((float) $coupon->value)),
                    ],
                ],
            ],
            Coupon::TYPE_FEATURE => [
                'discount_amount' => 0.0,
                'final_price' => $planPrice,
                'extra_duration_days' => 0,
                'subscription_meta' => [
                    'coupon_applied' => [
                        'type' => $coupon->type,
                        'code' => $coupon->code,
                        'feature_payload' => $coupon->feature_payload,
                    ],
                ],
            ],
            default => [
                'discount_amount' => round($planPrice - $couponSvc->amountAfterCoupon($coupon, $planPrice), 2),
                'final_price' => $couponSvc->amountAfterCoupon($coupon, $planPrice),
                'extra_duration_days' => 0,
                'subscription_meta' => [
                    'coupon_applied' => [
                        'type' => $coupon->type,
                        'code' => $coupon->code,
                        'discount_amount' => round($planPrice - $couponSvc->amountAfterCoupon($coupon, $planPrice), 2),
                        'final_price' => $couponSvc->amountAfterCoupon($coupon, $planPrice),
                    ],
                ],
            ],
        };
    }

    private function applyFeatureCouponGrants(Subscription $sub, Coupon $coupon): void
    {
        $payload = $coupon->feature_payload ?? [];
        $rawKey = trim((string) ($payload['feature_key'] ?? ''));
        if ($rawKey === '') {
            return;
        }

        $key = app(FeatureUsageService::class)->normalizeFeatureKey($rawKey);
        $grantDays = max(1, (int) ($payload['grant_days'] ?? 30));
        $until = now()->copy()->addDays($grantDays);
        $grace = (int) config('subscription.grace_days', 0);
        if ($sub->ends_at !== null) {
            $cap = $sub->ends_at->copy()->addDays($grace);
            if ($until->gt($cap)) {
                $until = $cap;
            }
        }

        \App\Models\UserEntitlement::query()->updateOrCreate(
            [
                'user_id' => $sub->user_id,
                'entitlement_key' => $key,
            ],
            [
                'valid_until' => $until,
                'revoked_at' => null,
                'value_override' => '1',
            ]
        );
    }

    /**
     * True when the user has subscription access (paid window or grace after ends_at).
     */
    public function isActive(User $user): bool
    {
        return $this->getActiveSubscription($user) !== null;
    }

    /**
     * Latest subscription row that still grants access (including grace_days after ends_at).
     */
    public function getActiveSubscription(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->effectivelyActiveForAccess()
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * Mark subscriptions as expired after the grace window has passed (batch update).
     */
    public function expireSubscriptions(): int
    {
        $grace = (int) config('subscription.grace_days', 0);

        return Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now()->subDays($grace))
            ->update([
                'status' => Subscription::STATUS_EXPIRED,
                'updated_at' => now(),
            ]);
    }

    /**
     * New paid period from now(); entitlements assigned via {@see Subscription} created hook.
     */
    public function createSubscription(User $user, Plan $plan, PlanTerm $term): Subscription
    {
        if ((int) $term->plan_id !== (int) $plan->id) {
            throw new HttpException(422, __('subscriptions.invalid_billing_period'));
        }

        return DB::transaction(function () use ($user, $plan, $term) {
            Subscription::query()
                ->where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'updated_at' => now(),
                ]);

            $duration = (int) $term->duration_days;
            $now = now();
            $endsAt = $duration > 0 ? $now->copy()->addDays($duration) : null;

            return Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_term_id' => $term->id,
                'plan_price_id' => null,
                'coupon_id' => null,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'status' => Subscription::STATUS_ACTIVE,
            ]);
        });
    }

    /**
     * Extend the current paid window from the current ends_at when still in the future; otherwise start from now.
     */
    public function renewSubscription(User $user, PlanTerm $term): Subscription
    {
        if (! $term->relationLoaded('plan')) {
            $term->load('plan');
        }

        $plan = $term->plan ?? Plan::query()->find($term->plan_id);
        if (! $plan) {
            throw new HttpException(422, __('subscriptions.invalid_billing_period'));
        }

        return DB::transaction(function () use ($user, $term, $plan) {
            $existing = $this->getActiveSubscription($user);
            $duration = (int) $term->duration_days;
            if ($duration <= 0) {
                throw new HttpException(422, __('subscriptions.invalid_billing_period'));
            }

            if ($existing) {
                // Renewal window starts at max(ends_at, now()) — never anchor extension in the past (e.g. grace / late renew).
                $startsAt = $existing->ends_at === null
                    ? now()
                    : ($existing->ends_at->greaterThan(now()) ? $existing->ends_at->copy() : now()->copy());
                $newEnds = $startsAt->copy()->addDays($duration);

                $existing->update([
                    'plan_term_id' => $term->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $newEnds,
                    'updated_at' => now(),
                ]);

                $fresh = $existing->fresh();
                app(EntitlementService::class)->assignFromSubscription($fresh);

                return $fresh;
            }

            Subscription::query()
                ->where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'updated_at' => now(),
                ]);

            return $this->createSubscription($user, $plan, $term);
        });
    }

    /**
     * Simple upgrade: cancel current actives and start a new term (no proration).
     */
    public function upgradeSubscription(User $user, Plan $newPlan, PlanTerm $term): Subscription
    {
        if ((int) $term->plan_id !== (int) $newPlan->id) {
            throw new HttpException(422, __('subscriptions.invalid_billing_period'));
        }

        return DB::transaction(function () use ($user, $newPlan, $term) {
            Subscription::query()
                ->where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->update([
                    'status' => Subscription::STATUS_CANCELLED,
                    'updated_at' => now(),
                ]);

            return $this->createSubscription($user, $newPlan, $term);
        });
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
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => 3,
            default => 0,
        };
    }
}
