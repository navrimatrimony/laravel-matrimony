<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PaymentLogger;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Payment finalization facade (SSOT): delegates to {@see SubscriptionService::finalizePayuSubscription()}.
 */
class PaymentService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function finalize(string $txnid, string $source = 'retry_worker'): ?Subscription
    {
        $txnid = trim($txnid);
        if ($txnid === '') {
            return null;
        }

        $paymentQuery = Payment::query()->where('txnid', $txnid);
        if (Schema::hasColumn('payments', 'payu_txnid')) {
            $paymentQuery->orWhere('payu_txnid', $txnid);
        }
        $payment = $paymentQuery->first();
        if (! $payment) {
            PaymentLogger::logEvent('payment_failed', [
                'txnid' => $txnid,
                'source' => $source,
                'internal_status' => 'payment_not_found',
            ]);

            return null;
        }
        if ((string) $payment->payment_status !== 'success') {
            PaymentLogger::logEvent('payment_failed', [
                'txnid' => $txnid,
                'user_id' => $payment->user_id,
                'plan_id' => $payment->plan_id,
                'plan_term_id' => $payment->plan_term_id,
                'source' => $source,
                'internal_status' => 'payment_not_success',
                'gateway_status' => (string) $payment->payment_status,
                'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
            ]);

            return null;
        }

        $existingSub = Subscription::query()
            ->where('user_id', (int) $payment->user_id)
            ->where('plan_id', (int) $payment->plan_id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();
        if ($existingSub) {
            PaymentLogger::logEvent('payment_finalized', [
                'txnid' => $payment->txnid,
                'user_id' => $payment->user_id,
                'plan_id' => $payment->plan_id,
                'plan_term_id' => $payment->plan_term_id,
                'source' => $source,
                'internal_status' => 'idempotent_hit',
                'gateway_status' => (string) $payment->payment_status,
                'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
            ]);

            return $existingSub;
        }

        $user = User::query()->find((int) $payment->user_id);
        $plan = Plan::query()->find((int) $payment->plan_id);
        if (! $user || ! $plan) {
            PaymentLogger::logEvent('payment_failed', [
                'txnid' => $payment->txnid,
                'user_id' => $payment->user_id,
                'plan_id' => $payment->plan_id,
                'source' => $source,
                'internal_status' => 'user_or_plan_missing',
            ]);

            return null;
        }

        $pending = $this->pendingFromPayment($payment, $plan);
        $payuPayload = is_array($payment->payload) ? $payment->payload : [];
        if ($payuPayload === []) {
            $payuPayload = [
                'txnid' => (string) $payment->txnid,
                'amount' => (string) $pending['amount'],
                'status' => 'success',
            ];
        }

        try {
            $sub = $this->subscriptions->finalizePayuSubscription($user, $plan, $pending, (string) $payment->txnid, $payuPayload);
        } catch (HttpException $e) {
            PaymentLogger::logEvent('payment_failed', [
                'txnid' => $payment->txnid,
                'user_id' => $payment->user_id,
                'plan_id' => $payment->plan_id,
                'plan_term_id' => $payment->plan_term_id,
                'source' => $source,
                'internal_status' => 'finalize_rejected_'.$e->getStatusCode(),
                'gateway_status' => (string) $payment->payment_status,
                'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
            ]);
            throw $e;
        }

        PaymentLogger::logEvent('payment_finalized', [
            'txnid' => $payment->txnid,
            'user_id' => $payment->user_id,
            'plan_id' => $payment->plan_id,
            'plan_term_id' => $payment->plan_term_id,
            'source' => $source,
            'internal_status' => 'finalized',
            'gateway_status' => (string) $payment->payment_status,
            'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
        ]);
        PaymentLogger::logEvent('subscription_activated', [
            'txnid' => $payment->txnid,
            'user_id' => $payment->user_id,
            'plan_id' => $payment->plan_id,
            'plan_term_id' => $payment->plan_term_id,
            'source' => $source,
            'internal_status' => 'active',
            'amount' => (float) ($payment->amount_paid ?? $payment->amount ?? 0),
        ]);

        return $sub;
    }

    /**
     * @return array<string,mixed>
     */
    private function pendingFromPayment(Payment $payment, Plan $plan): array
    {
        $meta = is_array($payment->meta) ? $payment->meta : [];
        $term = null;
        if ($payment->plan_term_id) {
            $term = PlanTerm::query()->find((int) $payment->plan_term_id);
        }
        $baseAmount = (float) ($meta['base_amount'] ?? $payment->amount_paid ?? $payment->amount ?? 0);
        $finalAmount = (float) ($meta['final_amount_after_coupon'] ?? $payment->amount_paid ?? $payment->amount ?? 0);
        $durationDays = (int) ($meta['duration_days'] ?? $term?->duration_days ?? $plan->duration_days ?? 30);
        $couponDiscount = (float) ($meta['coupon_discount'] ?? 0);
        $couponCode = isset($meta['coupon_code']) ? trim((string) $meta['coupon_code']) : '';
        $checkoutSnapshot = is_array($meta['checkout_snapshot'] ?? null) ? $meta['checkout_snapshot'] : [];
        $preview = [];
        if ($checkoutSnapshot !== []) {
            $preview['checkout_snapshot'] = $checkoutSnapshot;
        }

        return [
            'user_id' => (int) $payment->user_id,
            'plan_id' => (int) $plan->id,
            'plan_slug' => (string) $plan->slug,
            'plan_term_id' => $payment->plan_term_id ? (int) $payment->plan_term_id : null,
            'plan_price_id' => null,
            'plan_name' => (string) ($meta['plan_name'] ?? $plan->name ?? 'Plan'),
            'billing_key' => (string) ($meta['billing_key'] ?? $payment->billing_key ?? $term?->billing_key ?? ''),
            'duration_days' => $durationDays,
            'extra_duration_days' => 0,
            'duration_days_total' => $durationDays,
            'discount_percent' => $term?->discount_percent,
            'base_amount' => round($baseAmount, 2),
            'final_amount' => round($finalAmount, 2),
            'currency' => (string) ($payment->currency ?: 'INR'),
            'coupon_code' => $couponCode !== '' ? $couponCode : null,
            'coupon_discount' => round($couponDiscount, 2),
            'final_amount_after_coupon' => round($finalAmount, 2),
            'amount' => number_format($finalAmount, 2, '.', ''),
            'subscription_meta_preview' => $preview,
        ];
    }
}

