<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

/**
 * Central entry point for revenue **coordination** only.
 *
 * Checkout amounts and coupon application remain in {@see SubscriptionService::resolvePaidPlanCheckout}
 * (which delegates coupon math to {@see CouponService} via {@see SubscriptionService::applyCoupon}).
 * Wallet debits remain in {@see UserWalletService} (plan checkout does not use wallet today).
 * Referral side-effects remain in {@see ReferralService::applyPurchaseRewardIfEligible}.
 */
class RevenueOrchestratorService
{
    /** Plan / PayU checkout does not debit wallet; reserved for future wiring. */
    public const WALLET_PLAN_CHECKOUT_ENABLED = false;

    /** Reserved for future admin-defined stacking; no effect today. */
    public const BONUS_STACKING_MODE_NONE = 'none';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly ReferralService $referrals,
    ) {}

    /**
     * Single coordinated checkout preparation (no duplicate coupon rules).
     *
     * @return array{
     *     resolved: array<string, mixed>,
     *     orchestration: array<string, mixed>
     * }
     */
    public function prepareCheckout(
        User $user,
        Plan $plan,
        ?int $planTermId = null,
        ?int $planPriceId = null,
        ?string $couponCode = null,
    ): array {
        $resolved = $this->subscriptions->resolvePaidPlanCheckout(
            $user,
            $plan,
            $planTermId,
            $planPriceId,
            $couponCode !== null && trim($couponCode) !== '' ? trim($couponCode) : null,
        );

        return [
            'resolved' => $resolved,
            'orchestration' => [
                'wallet' => [
                    'applies_to_plan_checkout' => self::WALLET_PLAN_CHECKOUT_ENABLED,
                    'deduction_rupees' => 0.0,
                ],
                'bonus_stacking' => [
                    'mode' => self::BONUS_STACKING_MODE_NONE,
                ],
            ],
        ];
    }

    /**
     * Post–subscription-create coordination: referral reward + read-only purchase summary.
     * Call order must match prior inline behavior (after coupon redemption / feature grants on {@code $subscription}).
     *
     * @return array<string, mixed>
     */
    public function finalizePurchase(User $user, Plan $plan, Subscription $subscription): array
    {
        $this->referrals->applyPurchaseRewardIfEligible($user, $plan);

        $fresh = $subscription->fresh();
        $meta = $fresh && is_array($fresh->meta) ? $fresh->meta : [];

        return [
            'referral_dispatched' => true,
            'buyer_carry_quota' => is_array($meta['carry_quota'] ?? null) ? $meta['carry_quota'] : [],
            'checkout_snapshot' => $fresh ? $fresh->checkoutSnapshot() : [],
        ];
    }
}
