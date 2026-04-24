<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\QuotaPolicySourceViolation;
use App\Models\Coupon;
use App\Models\Interest;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\ProfileView;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use App\Support\UserFeatureUsageKeys;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Numeric quotas: {@see PlanQuotaPolicy} at purchase time are frozen in {@see Subscription::checkoutSnapshot()}
 * ({@code quota_policies}); live {@see PlanQuotaPolicy} rows apply for catalog / free tier. Legacy {@see PlanFeature}
 * mirror is not used for those limits.
 */
class SubscriptionService
{
    public const FEATURE_CHAT_SEND_LIMIT = 'chat_send_limit';

    public const FEATURE_INTEREST_SEND_LIMIT = 'interest_send_limit';

    public const FEATURE_DAILY_PROFILE_VIEW_LIMIT = 'daily_profile_view_limit';

    /** "1" = may send chat images (in addition to communication policy). */
    public const FEATURE_CHAT_IMAGE_MESSAGES = 'chat_image_messages';

    /**
     * Resolve billing selection and payable amount (after coupon) without mutating subscriptions.
     * Used by PayU checkout so the gateway amount matches {@see subscribe()}.
     *
     * @return array{
     *     plan_term_id: ?int,
     *     plan_price_id: ?int,
     *     coupon_code: ?string,
     *     final_amount: float,
     *     duration_days: int,
     *     plan_term: ?PlanTerm,
     *     plan_price: ?PlanPrice,
     *     coupon: ?Coupon,
     *     base_amount: float,
     *     subscription_meta_preview: array<string, mixed>
     * }
     */
    public function resolvePaidPlanCheckout(
        User $user,
        Plan $plan,
        ?int $planTermId = null,
        ?int $planPriceId = null,
        ?string $couponCode = null,
    ): array {
        if (! $plan->is_active) {
            throw new HttpException(422, __('subscriptions.plan_inactive'));
        }

        PlanPrice::ensureMirrorMatchesTerms($plan->fresh());

        $couponSvc = app(CouponService::class);
        $rawCoupon = trim((string) ($couponCode ?? ''));
        if ($rawCoupon !== '' && ! config('monetization.coupons.enabled', true)) {
            throw new HttpException(422, __('subscriptions.coupon_invalid'));
        }

        $planTerm = null;
        $planPrice = null;
        $duration = (int) $plan->duration_days;
        $baseAmount = (float) $plan->final_price;
        $coupon = null;

        $plan->loadMissing('terms');
        $visibleTerms = $plan->terms->where('is_visible', true)->sortBy('sort_order')->values();

        if ($visibleTerms->isNotEmpty()) {
            if ($planTermId === null) {
                throw new HttpException(422, __('subscriptions.pick_billing_period'));
            }
            $planTerm = PlanTerm::query()
                ->where('plan_id', $plan->id)
                ->whereKey($planTermId)
                ->first();
            // Catalog visibility is not subscription validity: explicit term id must resolve for locked checkout / PayU finalize.
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

        $applied = $this->applyCoupon($coupon, $baseAmount);
        $subscriptionMetaPreview = $applied['subscription_meta'] ?? [];
        if (! is_array($subscriptionMetaPreview)) {
            $subscriptionMetaPreview = [];
        }
        $subscriptionMetaPreview = array_merge($subscriptionMetaPreview, PlanQuotaCheckoutSnapshot::forPlan($plan));

        return [
            'plan_term_id' => $planTerm?->id,
            'plan_price_id' => $planPrice?->id,
            'coupon_code' => $rawCoupon !== '' ? $rawCoupon : null,
            'final_amount' => max(0.0, round((float) $applied['final_price'], 2)),
            'duration_days' => $duration,
            'plan_term' => $planTerm,
            'plan_price' => $planPrice,
            'coupon' => $coupon,
            'base_amount' => $baseAmount,
            'subscription_meta_preview' => $subscriptionMetaPreview,
        ];
    }

    public function subscribe(
        User $user,
        Plan $plan,
        ?int $planTermId = null,
        ?int $planPriceId = null,
        ?string $couponCode = null,
    ): Subscription {
        $couponSvc = app(CouponService::class);
        $rawCoupon = trim((string) ($couponCode ?? ''));

        return DB::transaction(function () use ($user, $plan, $planTermId, $planPriceId, $rawCoupon, $couponSvc) {
            $now = now();
            $carryQuota = app(QuotaEngineService::class)->applyPlan($user, $plan, $now)['carry_quota'];

            $resolved = $this->resolvePaidPlanCheckout($user, $plan, $planTermId, $planPriceId, $rawCoupon !== '' ? $rawCoupon : null);
            $planTerm = $resolved['plan_term'];
            $planPrice = $resolved['plan_price'];
            $duration = (int) $resolved['duration_days'];
            $coupon = $resolved['coupon'];

            // 1) Cancel any current active subscription(s) — rows kept for history (same as model safeguard on create).
            Subscription::deactivateActiveSubscriptionsForUserId((int) $user->id);

            $couponApplied = $coupon ? $this->applyCoupon($coupon, (float) $resolved['base_amount']) : null;
            if ($couponApplied !== null) {
                $duration += (int) ($couponApplied['extra_duration_days'] ?? 0);
            }
            $finalCharged = round((float) ($couponApplied['final_price'] ?? $resolved['final_amount']), 2);

            $subscriptionMeta = [];
            if ($couponApplied !== null) {
                $metaFromCoupon = $couponApplied['subscription_meta'] ?? [];
                if (is_array($metaFromCoupon) && $metaFromCoupon !== []) {
                    $subscriptionMeta = $metaFromCoupon;
                }
            }
            $checkoutQuota = PlanQuotaCheckoutSnapshot::forPlan($plan);
            if ($planTerm !== null) {
                $couponSnap = $this->checkoutCouponSnapshotFromResolved($resolved, $couponApplied);
                $subscriptionMeta['checkout_snapshot'] = array_merge(
                    $checkoutQuota,
                    [
                        'plan_name' => (string) $plan->name,
                        'billing_key' => (string) $planTerm->billing_key,
                        'plan_term_id' => (int) $planTerm->id,
                        'duration_days' => (int) $planTerm->duration_days,
                        'discount_percent' => $planTerm->discount_percent !== null ? (int) $planTerm->discount_percent : null,
                        'base_amount' => round((float) $resolved['base_amount'], 2),
                        'final_amount' => $finalCharged,
                        'currency' => 'INR',
                    ],
                    $couponSnap,
                );
            } else {
                $subscriptionMeta['checkout_snapshot'] = array_merge(
                    $checkoutQuota,
                    [
                        'plan_name' => (string) $plan->name,
                        'billing_key' => null,
                        'plan_term_id' => null,
                        'duration_days' => $duration,
                        'discount_percent' => null,
                        'base_amount' => round((float) $resolved['base_amount'], 2),
                        'final_amount' => $finalCharged,
                        'currency' => 'INR',
                    ],
                );
            }
            if ($carryQuota !== []) {
                $subscriptionMeta['carry_quota'] = $carryQuota;
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
                'meta' => $subscriptionMeta,
            ]);

            if ($coupon) {
                $couponSvc->incrementRedemption($coupon);
            }

            if ($coupon && $coupon->type === Coupon::TYPE_FEATURE) {
                $this->applyFeatureCouponGrants($sub, $coupon);
            }

            app(RevenueOrchestratorService::class)->finalizePurchase($user, $plan, $sub);

            return $sub;
        });
    }

    /**
     * Persist subscription + immutable payment snapshot after PayU success (uses locked pending payload only).
     *
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $payuPayload
     */
    public function finalizePayuSubscription(User $user, Plan $plan, array $pending, string $txnid, array $payuPayload): Subscription
    {
        return DB::transaction(function () use ($user, $plan, $pending, $txnid, $payuPayload) {
            $existingPayment = Payment::query()
                ->where('user_id', $user->id)
                ->where('payment_status', 'success')
                ->where(function ($q) use ($txnid) {
                    $q->where('txnid', $txnid);
                    if (Schema::hasColumn('payments', 'payu_txnid')) {
                        $q->orWhere('payu_txnid', $txnid);
                    }
                })
                ->first();

            $resumeSubscriptionOnly = false;
            if ($existingPayment !== null) {
                $prior = Subscription::query()
                    ->where('user_id', $user->id)
                    ->where('plan_id', $existingPayment->plan_id)
                    ->orderByDesc('id')
                    ->first();
                if ($prior !== null) {
                    return $prior;
                }
                $resumeSubscriptionOnly = true;
            }

            $term = $this->resolvePlanTermForPayuFinalize($plan, $pending, $txnid);

            $finalAmt = round((float) ($pending['final_amount'] ?? 0), 2);
            $postedLock = round((float) ($pending['amount'] ?? 0), 2);
            if ($finalAmt <= 0.0 || abs($finalAmt - $postedLock) > 0.02) {
                Log::warning('payu_finalize_rejected_amount_lock_mismatch', [
                    'txnid' => $txnid,
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'final_amount' => $finalAmt,
                    'posted_lock' => $postedLock,
                ]);
                throw new HttpException(422, __('subscriptions.subscribe_failed'));
            }
            $gwAmount = isset($payuPayload['amount']) ? round((float) trim((string) $payuPayload['amount']), 2) : null;
            if ($gwAmount !== null && abs($gwAmount - $finalAmt) > 0.02) {
                Log::warning('payu_finalize_rejected_gateway_amount_mismatch', [
                    'txnid' => $txnid,
                    'user_id' => $user->id,
                    'final_amount' => $finalAmt,
                    'gateway_amount' => $gwAmount,
                ]);
                throw new HttpException(422, __('subscriptions.subscribe_failed'));
            }

            $this->assertPayuPendingHardIntegrity($plan, $pending, $term, $txnid, $payuPayload, $finalAmt);

            $currency = (string) ($pending['currency'] ?? 'INR');
            $paymentMeta = array_merge(
                $this->paymentMetaSnapshotFromPending($pending),
                $this->checkoutCouponSnapshotFromPending($pending),
            );

            if (! $resumeSubscriptionOnly) {
                $paymentAttrs = [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'plan_term_id' => (int) ($pending['plan_term_id'] ?? 0) ?: null,
                    'txnid' => $txnid,
                    'plan_key' => (string) $plan->slug,
                    'billing_key' => (string) ($pending['billing_key'] ?? ''),
                    'amount' => $finalAmt,
                    'amount_paid' => $finalAmt,
                    'currency' => $currency,
                    'status' => PaymentStatus::Success->value,
                    'payment_status' => 'success',
                    'gateway' => 'payu',
                    'payload' => $payuPayload,
                    'meta' => $paymentMeta,
                    'source' => 'subscription_payu',
                    'is_processed' => true,
                    'webhook_is_final' => false,
                ];
                if (Schema::hasColumn('payments', 'payu_txnid')) {
                    $paymentAttrs['payu_txnid'] = $txnid;
                }
                Payment::query()->create($paymentAttrs);
            }

            $now = now();
            $carryQuota = app(QuotaEngineService::class)->applyPlan($user, $plan, $now)['carry_quota'];
            Subscription::deactivateActiveSubscriptionsForUserId((int) $user->id);

            $couponSvc = app(CouponService::class);
            $rawCoupon = trim((string) ($pending['coupon_code'] ?? ''));
            $coupon = $rawCoupon !== '' ? $couponSvc->lockCouponByCode($rawCoupon) : null;

            $duration = (int) ($pending['duration_days_total'] ?? $pending['duration_days'] ?? 0);

            $subscriptionMeta = [];
            $preview = $pending['subscription_meta_preview'] ?? [];
            if (is_array($preview) && $preview !== []) {
                $subscriptionMeta = $preview;
            }

            $subscriptionMeta['checkout_snapshot'] = array_merge(
                PlanQuotaCheckoutSnapshot::forPlan($plan),
                [
                    'plan_name' => (string) ($pending['plan_name'] ?? ''),
                    'billing_key' => (string) ($pending['billing_key'] ?? ''),
                    'plan_term_id' => (int) ($pending['plan_term_id'] ?? 0),
                    'duration_days' => (int) ($pending['duration_days'] ?? 0),
                    'discount_percent' => isset($pending['discount_percent']) && $pending['discount_percent'] !== '' && $pending['discount_percent'] !== null
                        ? (int) $pending['discount_percent']
                        : null,
                    'base_amount' => round((float) ($pending['base_amount'] ?? 0), 2),
                    'final_amount' => $finalAmt,
                    'currency' => $currency,
                    'payu_txnid' => $txnid,
                ],
                $this->checkoutCouponSnapshotFromPending($pending),
            );
            if ($carryQuota !== []) {
                $subscriptionMeta['carry_quota'] = $carryQuota;
            }

            $endsAt = null;
            if ($duration > 0) {
                $endsAt = $now->copy()->addDays($duration);
            }

            $sub = Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_term_id' => $term?->id,
                'plan_price_id' => null,
                'coupon_id' => $coupon?->id,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => $subscriptionMeta,
            ]);

            if ($coupon) {
                $couponSvc->incrementRedemption($coupon);
            }

            if ($coupon && $coupon->type === Coupon::TYPE_FEATURE) {
                $this->applyFeatureCouponGrants($sub, $coupon);
            }

            app(RevenueOrchestratorService::class)->finalizePurchase($user, $plan, $sub);

            return $sub;
        });
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    public function buildPayuPendingPayload(User $user, Plan $plan, array $resolved, string $amountString): array
    {
        $term = $resolved['plan_term'];
        $coupon = $resolved['coupon'];
        $couponApplied = $coupon ? $this->applyCoupon($coupon, (float) $resolved['base_amount']) : null;
        $extraDays = (int) ($couponApplied['extra_duration_days'] ?? 0);
        $durationTotal = (int) $resolved['duration_days'] + $extraDays;
        $preview = $resolved['subscription_meta_preview'] ?? [];
        if (! is_array($preview)) {
            $preview = [];
        }

        $couponDiscount = $couponApplied !== null
            ? round((float) ($couponApplied['discount_amount'] ?? 0), 2)
            : 0.0;
        $finalAfterCoupon = $couponApplied !== null
            ? round((float) ($couponApplied['final_price'] ?? $resolved['final_amount']), 2)
            : round((float) $resolved['final_amount'], 2);

        return [
            'user_id' => (int) $user->id,
            'plan_id' => (int) $plan->id,
            'plan_slug' => (string) $plan->slug,
            'plan_term_id' => $resolved['plan_term_id'],
            'plan_price_id' => $resolved['plan_price_id'],
            'plan_name' => (string) $plan->name,
            'billing_key' => $term?->billing_key,
            'duration_days' => (int) $resolved['duration_days'],
            'extra_duration_days' => $extraDays,
            'duration_days_total' => $durationTotal,
            'discount_percent' => $term?->discount_percent,
            'base_amount' => round((float) $resolved['base_amount'], 2),
            'final_amount' => round((float) $resolved['final_amount'], 2),
            'currency' => 'INR',
            'coupon_code' => $resolved['coupon_code'],
            'coupon_discount' => $couponDiscount,
            'final_amount_after_coupon' => $finalAfterCoupon,
            'amount' => $amountString,
            'subscription_meta_preview' => $preview,
        ];
    }

    /**
     * When the DB term row was removed after checkout, finalize using the locked pending payload only (paid user must retain access).
     *
     * @param  array<string, mixed>  $pending
     */
    private function resolvePlanTermForPayuFinalize(Plan $plan, array $pending, string $txnid): ?PlanTerm
    {
        $tid = (int) ($pending['plan_term_id'] ?? 0);
        if ($tid <= 0) {
            throw new HttpException(422, __('subscriptions.invalid_billing_period'));
        }

        $term = PlanTerm::query()->whereKey($tid)->first();
        if ($term !== null && (int) $term->plan_id !== (int) $plan->id) {
            Log::warning('payu_finalize_rejected_term_wrong_plan', [
                'txnid' => $txnid,
                'plan_id' => $plan->id,
                'plan_term_id' => $tid,
                'term_plan_id' => $term->plan_id,
            ]);
            throw new HttpException(422, __('subscriptions.invalid_billing_period'));
        }

        if ($term === null) {
            Log::warning('payu_finalize_plan_term_missing_using_pending_snapshot', [
                'txnid' => $txnid,
                'user_id' => $pending['user_id'] ?? null,
                'plan_id' => $plan->id,
                'plan_term_id' => $tid,
                'billing_key' => $pending['billing_key'] ?? null,
            ]);
        }

        return $term;
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function assertPayuPendingHardIntegrity(
        Plan $plan,
        array $pending,
        ?PlanTerm $term,
        string $txnid,
        array $payuPayload,
        float $finalAmt,
    ): void {
        $pendingPlanId = (int) ($pending['plan_id'] ?? 0);
        if ($pendingPlanId <= 0 || $pendingPlanId !== (int) $plan->id) {
            Log::warning('payu_finalize_rejected_plan_id_mismatch', [
                'txnid' => $txnid,
                'route_plan_id' => $plan->id,
                'pending_plan_id' => $pendingPlanId,
            ]);
            throw new HttpException(422, __('subscriptions.subscribe_failed'));
        }

        $bk = (string) ($pending['billing_key'] ?? '');
        if ($bk === '') {
            Log::warning('payu_finalize_rejected_empty_billing_key', ['txnid' => $txnid]);
            throw new HttpException(422, __('subscriptions.subscribe_failed'));
        }

        if ($term !== null) {
            if ($bk !== (string) $term->billing_key) {
                Log::warning('payu_finalize_rejected_billing_key_mismatch', [
                    'txnid' => $txnid,
                    'pending_billing_key' => $bk,
                    'term_billing_key' => $term->billing_key,
                ]);
                throw new HttpException(422, __('subscriptions.subscribe_failed'));
            }
            $lockedBase = round((float) ($pending['base_amount'] ?? -1), 2);
            $termFinal = round((float) $term->final_price, 2);
            if (abs($termFinal - $lockedBase) > 0.02) {
                Log::warning('payu_finalize_rejected_base_amount_mismatch', [
                    'txnid' => $txnid,
                    'locked_base' => $lockedBase,
                    'term_final_price' => $termFinal,
                ]);
                throw new HttpException(422, __('subscriptions.subscribe_failed'));
            }
        }

        $snapFinal = round((float) ($pending['final_amount_after_coupon'] ?? $pending['final_amount'] ?? -1), 2);
        if (abs($snapFinal - $finalAmt) > 0.02) {
            Log::warning('payu_finalize_rejected_final_amount_snapshot_mismatch', [
                'txnid' => $txnid,
                'computed_final' => $finalAmt,
                'snapshot_final' => $snapFinal,
            ]);
            throw new HttpException(422, __('subscriptions.subscribe_failed'));
        }
    }

    /**
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>
     */
    private function paymentMetaSnapshotFromPending(array $pending): array
    {
        return [
            'plan_name' => (string) ($pending['plan_name'] ?? ''),
            'billing_key' => (string) ($pending['billing_key'] ?? ''),
            'duration_days' => (int) ($pending['duration_days'] ?? 0),
            'base_amount' => round((float) ($pending['base_amount'] ?? 0), 2),
            'discount_percent' => isset($pending['discount_percent']) && $pending['discount_percent'] !== '' && $pending['discount_percent'] !== null
                ? (int) $pending['discount_percent']
                : null,
            'final_amount' => round((float) ($pending['final_amount'] ?? 0), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, mixed>|null  $couponApplied
     * @return array{coupon_code: ?string, coupon_discount: float, final_amount_after_coupon: float}
     */
    private function checkoutCouponSnapshotFromResolved(array $resolved, ?array $couponApplied): array
    {
        $raw = $resolved['coupon_code'] ?? null;
        $code = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
        $disc = $couponApplied !== null
            ? round((float) ($couponApplied['discount_amount'] ?? 0), 2)
            : 0.0;
        $finalAfter = $couponApplied !== null
            ? round((float) ($couponApplied['final_price'] ?? $resolved['final_amount']), 2)
            : round((float) $resolved['final_amount'], 2);

        return [
            'coupon_code' => $code,
            'coupon_discount' => $disc,
            'final_amount_after_coupon' => $finalAfter,
        ];
    }

    /**
     * @param  array<string, mixed>  $pending
     * @return array{coupon_code: ?string, coupon_discount: float, final_amount_after_coupon: float}
     */
    private function checkoutCouponSnapshotFromPending(array $pending): array
    {
        $raw = $pending['coupon_code'] ?? null;
        $code = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
        $disc = round((float) ($pending['coupon_discount'] ?? 0), 2);
        $finalAfter = round((float) ($pending['final_amount_after_coupon'] ?? $pending['final_amount'] ?? 0), 2);

        return [
            'coupon_code' => $code,
            'coupon_discount' => $disc,
            'final_amount_after_coupon' => $finalAfter,
        ];
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
        $sub->loadMissing('plan');
        $grace = PlanSubscriptionTerms::gracePeriodDays($sub->plan);
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
     *
     * @see self::resolveAuthoritativeSubscriptionForAccess() Single implementation used across the app.
     */
    public function getActiveSubscription(User $user, ?CarbonInterface $at = null): ?Subscription
    {
        return $this->resolveAuthoritativeSubscriptionForAccess($user, $at);
    }

    /**
     * Authoritative "current" subscription for access, quota payloads, entitlements, and meta gates.
     * Ordering: {@code orderByDesc(starts_at)} among rows matching {@see Subscription::scopeEffectivelyActiveForAccessAt}.
     */
    private function resolveAuthoritativeSubscriptionForAccess(User $user, ?CarbonInterface $at = null): ?Subscription
    {
        $moment = $at ?? now();

        $sub = Subscription::queryAuthoritativeAccessForUser($user, $moment)->first();

        if ($sub !== null && config('app.debug')) {
            $legacyLatestId = Subscription::query()
                ->where('user_id', $user->id)
                ->effectivelyActiveForAccessAt($moment)
                ->latest('id')
                ->value('id');
            if ($legacyLatestId !== null && (int) $legacyLatestId !== (int) $sub->id) {
                Log::warning('subscription_selection_disagreement', [
                    'user_id' => (int) $user->id,
                    'authoritative_subscription_id' => (int) $sub->id,
                    'legacy_latest_id_subscription_id' => (int) $legacyLatestId,
                ]);
            }
        }

        return $sub;
    }

    /**
     * Mark subscriptions as expired after the grace window has passed (batch update).
     */
    public function expireSubscriptions(): int
    {
        $now = now();
        $driver = DB::connection()->getDriverName();
        $expiryExpr = match ($driver) {
            'mysql', 'mariadb' => 'DATE_ADD(subscriptions.ends_at, INTERVAL COALESCE(plans.grace_period_days, 0) DAY)',
            'sqlite' => "datetime(subscriptions.ends_at, '+' || COALESCE(plans.grace_period_days, 0) || ' days')",
            'pgsql' => "subscriptions.ends_at + (COALESCE(plans.grace_period_days, 0) || ' days')::interval",
            default => 'subscriptions.ends_at',
        };

        return Subscription::query()
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('subscriptions.ends_at')
            ->whereRaw($expiryExpr.' <= ?', [$now->toDateTimeString()])
            ->update([
                'subscriptions.status' => Subscription::STATUS_EXPIRED,
                'subscriptions.updated_at' => $now,
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

            $plan->loadMissing('quotaPolicies');
            $checkoutSnapshot = array_merge(
                PlanQuotaCheckoutSnapshot::forPlan($plan),
                [
                    'plan_name' => (string) $plan->name,
                    'billing_key' => (string) $term->billing_key,
                    'plan_term_id' => (int) $term->id,
                    'duration_days' => $duration,
                    'base_amount' => null,
                    'final_amount' => null,
                    'currency' => 'INR',
                ],
            );

            return Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_term_id' => $term->id,
                'plan_price_id' => null,
                'coupon_id' => null,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [
                    'checkout_snapshot' => $checkoutSnapshot,
                ],
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

                $plan->loadMissing('quotaPolicies');
                $meta = is_array($existing->meta) ? $existing->meta : [];
                $prevCheckout = is_array($meta['checkout_snapshot'] ?? null) ? $meta['checkout_snapshot'] : [];
                $meta['checkout_snapshot'] = array_merge(
                    $prevCheckout,
                    PlanQuotaCheckoutSnapshot::forPlan($plan),
                    [
                        'plan_name' => (string) $plan->name,
                        'billing_key' => (string) $term->billing_key,
                        'plan_term_id' => (int) $term->id,
                        'duration_days' => $duration,
                    ],
                );

                $existing->update([
                    'plan_term_id' => $term->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $newEnds,
                    'updated_at' => now(),
                    'meta' => $meta,
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
                return $sub->plan()->with(['features', 'quotaPolicies'])->firstOrFail();
            }
        }

        return $this->defaultFreePlan($user);
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
     * Effective plan limit (+ {@code meta.carry_quota}) for gates and member UX.
     *
     * @return int -1 = unlimited, 0 = blocked
     */
    private function resolveQuotaLimitFromPlanAndCarry(User $user, string $key): int
    {
        $normalized = app(FeatureUsageService::class)->normalizeFeatureKey($key);

        if (! in_array($normalized, PlanQuotaPolicyKeys::ordered(), true)) {
            $plan = $this->getEffectivePlan($user);
            $plan->loadMissing('features');
            $raw = $plan->featureValue($normalized);
            if ($raw === null || $raw === '') {
                return $this->defaultLimitForKey($normalized);
            }
            $base = $this->parseLimitInt((string) $raw);
            if ($base < 0) {
                return -1;
            }
            if ($base === 0) {
                return 0;
            }

            return $base + $this->carriedQuotaBonus($user, $key);
        }

        $payloads = PlanQuotaUiSource::policyPayloadsForUser($user);
        if (! isset($payloads[$normalized]) || ! is_array($payloads[$normalized])) {
            throw QuotaPolicySourceViolation::missingPolicyRow(
                'resolveQuotaLimitFromPlanAndCarry.user_id='.(int) $user->id,
                $normalized
            );
        }
        $base = PlanQuotaPolicyMirror::subscriptionLimitIntFromQuotaPayload($normalized, $payloads[$normalized]);
        if ($base < 0) {
            return -1;
        }
        if ($base === 0) {
            return 0;
        }

        return $base + $this->carriedQuotaBonus($user, $key);
    }

    /**
     * @return int -1 = unlimited, 0 = blocked
     */
    public function getFeatureLimit(User $user, string $key): int
    {
        if ($user->isAnyAdmin()) {
            return -1;
        }

        return $this->resolveQuotaLimitFromPlanAndCarry($user, $key);
    }

    /**
     * Same numeric quota as {@see getFeatureLimit} for members, but for staff accounts uses the
     * subscribed plan (+ carry) instead of unlimited — so usage strips match the catalog / plan card.
     * Use only for display; gates still use {@see getFeatureLimit} and admin early-exits on asserts.
     */
    public function getQuotaLimitForUsageDisplay(User $user, string $key): int
    {
        return $this->resolveQuotaLimitFromPlanAndCarry($user, $key);
    }

    /**
     * Carry-only bonus already merged into {@see getFeatureLimit} / display limits (subscription meta {@code carry_quota}).
     */
    public function getQuotaCarryBonus(User $user, string $key): int
    {
        return $this->carriedQuotaBonus($user, $key);
    }

    /**
     * @return array<string, int>
     */
    public function resolveCarryQuotaSnapshotForCheckout(User $user, ?CarbonInterface $at = null): array
    {
        return $this->resolveCarryQuotaFromPreviousSubscription($user, $at ?? now());
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

    private function defaultFreePlan(?User $user = null): Plan
    {
        $p = Plan::defaultFree($user);
        if ($p) {
            return $p->loadMissing(['features', 'quotaPolicies']);
        }

        $any = Plan::query()->where('is_active', true)->orderBy('sort_order')->first();
        if ($any) {
            return $any->loadMissing(['features', 'quotaPolicies']);
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
        if (in_array($key, PlanQuotaPolicyKeys::planFeatureKeysWrittenByPolicies(), true)) {
            $map = PlanQuotaUiSource::mirroredPlanFeatureStringsForPlan($plan, 'truthyFeature');
            if (! array_key_exists($key, $map)) {
                throw QuotaPolicySourceViolation::missingPolicyRow('truthyFeature.plan_id='.(int) $plan->id, $key);
            }
            $v = strtolower(trim($map[$key]));
            if (is_numeric($v)) {
                return ((int) $v) !== 0 || $v === '-1';
            }

            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }

        $raw = $plan->featureValue($key);
        $v = strtolower(trim((string) ($raw !== null && $raw !== '' ? $raw : '')));

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

    private function carriedQuotaBonus(User $user, string $key): int
    {
        $sub = $this->getActiveSubscription($user);
        if (! $sub) {
            return 0;
        }
        $meta = $sub->meta;
        if (! is_array($meta)) {
            return 0;
        }
        $carry = $meta['carry_quota'] ?? null;
        if (! is_array($carry)) {
            return 0;
        }
        $normalized = app(FeatureUsageService::class)->normalizeFeatureKey($key);
        $bonus = $carry[$normalized] ?? 0;

        return max(0, (int) $bonus);
    }

    /**
     * Snapshot remaining quota from the last subscription when renewal/purchase happens
     * in grace or carry window after grace.
     *
     * @return array<string, int>
     */
    private function resolveCarryQuotaFromPreviousSubscription(User $user, \Carbon\CarbonInterface $at): array
    {
        $previous = Subscription::query()
            ->where('user_id', $user->id)
            ->whereNotNull('ends_at')
            ->orderByDesc('starts_at')
            ->with(['plan.features', 'plan.quotaPolicies'])
            ->first();
        if (! $previous || ! $previous->plan || ! $this->isWithinGraceOrCarryWindow($previous, $at)) {
            return [];
        }

        $usage = app(UserFeatureUsageService::class);
        $featurePeriods = [
            PlanFeatureKeys::CHAT_SEND_LIMIT => UserFeatureUsage::PERIOD_DAILY,
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => UserFeatureUsage::PERIOD_MONTHLY,
            self::FEATURE_DAILY_PROFILE_VIEW_LIMIT => UserFeatureUsage::PERIOD_DAILY,
            PlanFeatureKeys::INTEREST_SEND_LIMIT => UserFeatureUsage::PERIOD_DAILY,
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => UserFeatureUsage::PERIOD_MONTHLY,
        ];

        $carry = [];
        foreach ($featurePeriods as $featureKey => $period) {
            $limit = 0;
            $prevSnap = $previous->checkoutSnapshot();
            $qp = is_array($prevSnap['quota_policies'] ?? null) ? $prevSnap['quota_policies'] : null;
            if (is_array($qp) && isset($qp[$featureKey]) && is_array($qp[$featureKey])) {
                $limit = PlanQuotaPolicyMirror::subscriptionLimitIntFromQuotaPayload($featureKey, $qp[$featureKey]);
            } else {
                $previous->plan->loadMissing('quotaPolicies');
                $row = $previous->plan->quotaPolicies->firstWhere('feature_key', $featureKey);
                if ($row instanceof PlanQuotaPolicy) {
                    $limit = PlanQuotaPolicyMirror::subscriptionLimitIntFromQuotaPayload(
                        $featureKey,
                        PlanQuotaPolicyMirror::payloadFromModel($row),
                    );
                } else {
                    throw QuotaPolicySourceViolation::missingPolicyRow(
                        'resolveCarryQuotaFromPreviousSubscription.subscription_id='.(int) $previous->id,
                        $featureKey
                    );
                }
            }
            if ($limit <= 0) {
                continue;
            }
            $usageKey = $featureKey === PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH
                ? UserFeatureUsageKeys::MEDIATOR_REQUEST
                : $featureKey;
            $used = $usage->getUsage((int) $user->id, $usageKey, $period, $at);
            $remaining = max(0, $limit - $used);
            if ($remaining > 0) {
                $carry[$featureKey] = $remaining;
            }
        }

        return $carry;
    }

    private function isWithinGraceOrCarryWindow(Subscription $subscription, \Carbon\CarbonInterface $at): bool
    {
        if ($subscription->ends_at === null || ! $subscription->plan) {
            return false;
        }

        $graceDays = PlanSubscriptionTerms::gracePeriodDays($subscription->plan);
        $graceEndsAt = $subscription->ends_at->copy()->addDays($graceDays);
        if ($at->lessThanOrEqualTo($graceEndsAt)) {
            return true;
        }

        $carryWindowDays = PlanSubscriptionTerms::leftoverQuotaCarryWindowDays($subscription->plan);
        if ($carryWindowDays === null || $carryWindowDays <= 0) {
            return false;
        }
        $carryDeadline = $graceEndsAt->copy()->addDays($carryWindowDays);

        return $at->lessThanOrEqualTo($carryDeadline);
    }
}
