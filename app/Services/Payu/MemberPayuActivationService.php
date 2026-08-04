<?php

namespace App\Services\Payu;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\PayuHasher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Member PayU activation pipeline (CheckoutPro + shared finalize SSOT).
 *
 * Extension point order:
 * 1. Reverse-hash check (when a response hash is present / required)
 * 2. Optional PayU {@see PayuVerifyPaymentClient} when configured
 * 3. {@see SubscriptionService::finalizePayuSubscription()}
 */
class MemberPayuActivationService
{
    public const PENDING_CACHE_PREFIX = 'payu_subscription:';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PayuVerifyPaymentClient $verifyPayment,
    ) {}

    /**
     * @param  array<string, mixed>  $payuPayload  Gateway / SDK fields (txnid, status, hash, amount, …)
     * @return array{
     *     subscription: Subscription|null,
     *     payment: Payment|null,
     *     already_activated: bool,
     *     verify_payment: array<string, mixed>
     * }
     */
    public function activateSuccessfulPayment(array $payuPayload, ?User $actingUser = null): array
    {
        $txnid = trim((string) ($payuPayload['txnid'] ?? ''));
        if ($txnid === '') {
            throw new HttpException(422, 'Missing txnid.');
        }

        $status = strtolower(trim((string) ($payuPayload['status'] ?? '')));
        if ($status !== '' && $status !== 'success') {
            throw new HttpException(422, 'Payment status is not success.');
        }

        $existing = $this->findSuccessfulPayment($txnid, $actingUser?->id);
        if ($existing instanceof Payment) {
            if ($actingUser instanceof User && (int) $existing->user_id !== (int) $actingUser->id) {
                throw new HttpException(403, 'Payment does not belong to this member.');
            }

            $subscription = Subscription::query()
                ->where('user_id', $existing->user_id)
                ->where('plan_id', $existing->plan_id)
                ->orderByDesc('id')
                ->first();

            return [
                'subscription' => $subscription,
                'payment' => $existing,
                'already_activated' => true,
                'verify_payment' => [
                    'ok' => true,
                    'configured' => $this->verifyPayment->isConfigured(),
                    'skipped' => true,
                    'status' => 'success',
                    'amount' => null,
                    'raw' => null,
                    'message' => 'Already activated; verify_payment skipped.',
                ],
            ];
        }

        $this->assertReverseHashGate($payuPayload);

        $verifyResult = $this->verifyPayment->verifyTransaction($txnid);
        if ($this->verifyPayment->isConfigured() && ! $verifyResult['ok']) {
            throw new HttpException(422, $verifyResult['message'] ?: 'PayU verify_payment failed.');
        }

        $pending = Cache::pull(self::PENDING_CACHE_PREFIX.$txnid);
        if (! is_array($pending)) {
            $existing = $this->findSuccessfulPayment($txnid, $actingUser?->id);
            if ($existing instanceof Payment) {
                $subscription = Subscription::query()
                    ->where('user_id', $existing->user_id)
                    ->where('plan_id', $existing->plan_id)
                    ->orderByDesc('id')
                    ->first();

                return [
                    'subscription' => $subscription,
                    'payment' => $existing,
                    'already_activated' => true,
                    'verify_payment' => $verifyResult,
                ];
            }

            throw new HttpException(422, 'Checkout session expired or missing.');
        }

        if ($this->pendingExpired($pending)) {
            throw new HttpException(422, 'Checkout session expired.');
        }

        $pendingUserId = (int) ($pending['user_id'] ?? 0);
        if ($actingUser instanceof User && $pendingUserId !== (int) $actingUser->id) {
            throw new HttpException(403, 'Checkout session mismatch.');
        }

        $user = $actingUser instanceof User
            ? $actingUser
            : User::query()->find($pendingUserId);
        $plan = Plan::query()->find((int) ($pending['plan_id'] ?? 0));
        if (! $user instanceof User || ! $plan instanceof Plan) {
            throw new HttpException(422, 'Invalid pending checkout.');
        }

        $expectedAmount = number_format((float) ($pending['amount'] ?? 0), 2, '.', '');
        $postedAmount = number_format((float) trim((string) ($payuPayload['amount'] ?? '')), 2, '.', '');
        if ($expectedAmount === '0.00') {
            throw new HttpException(422, 'Invalid pending amount.');
        }
        if ($postedAmount !== '0.00' && ! hash_equals($expectedAmount, $postedAmount)) {
            throw new HttpException(422, 'Amount mismatch.');
        }

        if (is_string($verifyResult['amount'] ?? null) && trim((string) $verifyResult['amount']) !== '') {
            $remoteAmount = number_format((float) $verifyResult['amount'], 2, '.', '');
            if (! hash_equals($expectedAmount, $remoteAmount)) {
                Log::warning('payu_verify_payment_amount_mismatch', [
                    'txnid' => $txnid,
                    'pending' => $expectedAmount,
                    'remote' => $remoteAmount,
                ]);
                throw new HttpException(422, 'PayU verify_payment amount mismatch.');
            }
        }

        $subscription = $this->subscriptions->finalizePayuSubscription(
            $user,
            $plan,
            $pending,
            $txnid,
            $payuPayload,
        );

        $payment = $this->findSuccessfulPayment($txnid, (int) $user->id);

        return [
            'subscription' => $subscription,
            'payment' => $payment,
            'already_activated' => false,
            'verify_payment' => $verifyResult,
        ];
    }

    /**
     * @param  array<string, mixed>  $payuPayload
     */
    private function assertReverseHashGate(array $payuPayload): void
    {
        $postedHash = strtolower(trim((string) ($payuPayload['hash'] ?? '')));
        $verifyConfigured = $this->verifyPayment->isConfigured();

        if ($postedHash === '') {
            // When verify_payment is on, still prefer a reverse hash if PayU sent one;
            // empty hash is only acceptable as a temporary bridge if verify_payment will run.
            if (! $verifyConfigured) {
                throw new HttpException(422, 'Payment signature missing.');
            }

            return;
        }

        if (! $this->responseHashMatches($payuPayload)) {
            throw new HttpException(422, 'Payment signature mismatch.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function responseHashMatches(array $data): bool
    {
        $salt = (string) config('payu.merchant_salt', '');
        $expectedKey = (string) config('payu.merchant_key', '');
        $key = trim((string) ($data['key'] ?? ''));
        $postedHash = strtolower(trim((string) ($data['hash'] ?? '')));

        if ($salt === '' || $expectedKey === '' || $key !== $expectedKey || $postedHash === '') {
            return false;
        }

        $computed = PayuHasher::paymentResponseHash(
            $salt,
            (string) ($data['status'] ?? ''),
            trim((string) ($data['email'] ?? '')),
            trim((string) ($data['firstname'] ?? '')),
            trim((string) ($data['productinfo'] ?? '')),
            trim((string) ($data['amount'] ?? '')),
            trim((string) ($data['txnid'] ?? '')),
            $key,
            (string) ($data['udf1'] ?? ''),
            (string) ($data['udf2'] ?? ''),
            (string) ($data['udf3'] ?? ''),
            (string) ($data['udf4'] ?? ''),
            (string) ($data['udf5'] ?? ''),
        );

        return hash_equals($computed, $postedHash);
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    public function pendingExpired(array $pending): bool
    {
        $expiresAt = trim((string) ($pending['pending_expires_at'] ?? ''));
        if ($expiresAt === '') {
            return false;
        }

        try {
            return now()->greaterThan(\Illuminate\Support\Carbon::parse($expiresAt));
        } catch (\Throwable) {
            return true;
        }
    }

    private function findSuccessfulPayment(string $txnid, ?int $userId = null): ?Payment
    {
        $query = Payment::query()
            ->where('payment_status', 'success')
            ->where(function ($q) use ($txnid) {
                $q->where('txnid', $txnid);
                if (Schema::hasColumn('payments', 'payu_txnid')) {
                    $q->orWhere('payu_txnid', $txnid);
                }
            });

        if ($userId !== null && $userId > 0) {
            $query->where('user_id', $userId);
        }

        return $query->first();
    }
}
