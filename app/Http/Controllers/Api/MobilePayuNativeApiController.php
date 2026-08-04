<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RevenueOrchestratorService;
use App\Services\SubscriptionService;
use App\Support\PayuHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Member CheckoutPro native start / dynamic hash / verify.
 *
 * Pending cache key matches web subscribe: {@code payu_subscription:{txnid}}.
 */
class MobilePayuNativeApiController extends Controller
{
    public const PENDING_CACHE_PREFIX = 'payu_subscription:';

    private const PENDING_TTL_MINUTES = 60;

    public function checkoutNative(
        Request $request,
        Plan $plan,
        RevenueOrchestratorService $revenue,
        SubscriptionService $subscriptions,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $data = $request->validate([
            'plan_term_id' => ['nullable', 'integer'],
        ]);
        $planTermId = array_key_exists('plan_term_id', $data) && $data['plan_term_id'] !== null
            ? (int) $data['plan_term_id']
            : null;

        $plan->loadMissing(['features', 'terms', 'quotaPolicies']);
        if (! $this->isMobileBuyablePlan($user, $plan)) {
            return $this->error('This plan is not available for checkout.', 422, 'plan_not_available');
        }

        if ($this->payuConfigMissing()) {
            return $this->error('Payment gateway is not configured. Please contact support.', 422, 'payment_config_missing');
        }

        $phone = preg_replace('/\D+/', '', trim((string) ($user->mobile ?? ''))) ?? '';
        if (strlen($phone) < 10) {
            return $this->error('A valid mobile number is required for payment.', 422, 'phone_required');
        }

        try {
            $prepared = $revenue->prepareCheckout($user, $plan, $planTermId, null);
        } catch (HttpException $exception) {
            return $this->error($exception->getMessage(), $this->httpStatus($exception), 'checkout_validation_failed');
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(__('subscriptions.subscribe_failed'), 422, 'checkout_unavailable');
        }

        $resolved = is_array($prepared['resolved'] ?? null) ? $prepared['resolved'] : [];
        $finalAmount = isset($resolved['final_amount']) ? (float) $resolved['final_amount'] : 0.0;
        if ($finalAmount <= 0.0) {
            return $this->error(__('subscriptions.subscribe_failed'), 422, 'checkout_amount_invalid');
        }

        $merchantKey = (string) config('payu.merchant_key', '');
        $salt = (string) config('payu.merchant_salt', '');
        $txnid = 'SUB'.strtoupper(Str::random(18));
        $productinfo = (string) $plan->slug;
        $firstname = $this->payuFirstName($user);
        $email = strtolower(trim((string) ($user->email ?? '')));
        if ($email === '') {
            $email = 'member@example.com';
        }

        $udf1 = (string) $user->id;
        $udf2 = '';
        $udf3 = '';
        $udf4 = 'member_native';
        $udf5 = '';

        $amount = number_format($finalAmount, 2, '.', '');
        $pending = $subscriptions->buildPayuPendingPayload($user, $plan, $resolved, $amount);
        $pending['checkout_channel'] = 'checkoutpro_native';

        Cache::put(
            self::PENDING_CACHE_PREFIX.$txnid,
            $pending,
            now()->addMinutes(self::PENDING_TTL_MINUTES),
        );

        $environment = $this->payuSdkEnvironment();
        $androidSurl = route('payu.sdk.success', [], true);
        $androidFurl = route('payu.sdk.failure', [], true);

        Log::info('mobile_payu_native_checkout_started', [
            'user_id' => (int) $user->id,
            'plan_id' => (int) $plan->id,
            'plan_term_id' => $resolved['plan_term_id'] ?? null,
            'txnid' => $txnid,
            'amount' => $amount,
            'environment' => $environment,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Native checkout prepared.',
            'checkout' => [
                'channel' => 'checkoutpro_native',
                'opens_external_browser' => false,
                'txnid' => $txnid,
                'plan_id' => (int) $plan->id,
                'plan_term_id' => isset($resolved['plan_term_id']) ? (int) $resolved['plan_term_id'] : null,
                'plan_name' => (string) ($plan->name ?? ''),
                'amount' => [
                    'currency' => 'INR',
                    'base_amount' => isset($resolved['base_amount']) ? round((float) $resolved['base_amount'], 2) : null,
                    'final_amount' => round((float) $finalAmount, 2),
                    'amount_string' => $amount,
                ],
                'duration_days' => isset($resolved['duration_days']) ? (int) $resolved['duration_days'] : null,
                'payu' => [
                    'key' => $merchantKey,
                    'txnid' => $txnid,
                    'amount' => $amount,
                    'productinfo' => $productinfo,
                    'firstname' => $firstname,
                    'email' => $email,
                    'phone' => $phone,
                    'udf1' => $udf1,
                    'udf2' => $udf2,
                    'udf3' => $udf3,
                    'udf4' => $udf4,
                    'udf5' => $udf5,
                    'android_surl' => $androidSurl,
                    'android_furl' => $androidFurl,
                    'ios_surl' => $androidSurl,
                    'ios_furl' => $androidFurl,
                    'environment' => $environment,
                    'user_credential' => $merchantKey.':'.$user->id,
                ],
            ],
            // Salt is intentionally omitted.
            'salt_present_server_side' => $salt !== '',
        ]);
    }

    public function hash(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $data = $request->validate([
            'hashName' => ['required', 'string', 'max:120'],
            'hashString' => ['required', 'string', 'max:4000'],
            'hashType' => ['nullable', 'string', 'max:40'],
            'postSalt' => ['nullable', 'string', 'max:2000'],
            'txnid' => ['nullable', 'string', 'max:40'],
        ]);

        $salt = (string) config('payu.merchant_salt', '');
        if ($salt === '') {
            return $this->error('Payment gateway is not configured. Please contact support.', 422, 'payment_config_missing');
        }

        $txnid = trim((string) ($data['txnid'] ?? ''));
        if ($txnid !== '') {
            $pending = Cache::get(self::PENDING_CACHE_PREFIX.$txnid);
            if (! is_array($pending) || (int) ($pending['user_id'] ?? 0) !== (int) $user->id) {
                return $this->error('Checkout session not found.', 422, 'checkout_session_missing');
            }
        }

        try {
            $hash = PayuHasher::checkoutProDynamicHash(
                (string) $data['hashString'],
                $salt,
                $data['hashType'] ?? null,
                (string) $data['hashName'],
                $data['postSalt'] ?? null,
                config('payu.merchant_secret'),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422, 'hash_config_missing');
        }

        $hashName = (string) $data['hashName'];

        return response()->json([
            'success' => true,
            'message' => 'Hash generated.',
            'hashName' => $hashName,
            'hash' => $hash,
            // CheckoutPro expects { hashName: hashValue }
            'hashResponse' => [
                $hashName => $hash,
            ],
        ]);
    }

    public function verify(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $data = $request->validate([
            'txnid' => ['required', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'hash' => ['nullable', 'string', 'max:256'],
            'amount' => ['nullable', 'string', 'max:40'],
            'productinfo' => ['nullable', 'string', 'max:120'],
            'firstname' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:190'],
            'key' => ['nullable', 'string', 'max:64'],
            'udf1' => ['nullable', 'string', 'max:120'],
            'udf2' => ['nullable', 'string', 'max:120'],
            'udf3' => ['nullable', 'string', 'max:120'],
            'udf4' => ['nullable', 'string', 'max:120'],
            'udf5' => ['nullable', 'string', 'max:120'],
            'mihpayid' => ['nullable', 'string', 'max:120'],
            'mode' => ['nullable', 'string', 'max:40'],
            'outcome' => ['nullable', 'string', 'in:success,failure,cancelled,error'],
            'sdk_response' => ['nullable', 'array'],
        ]);

        $txnid = trim((string) $data['txnid']);
        $outcome = strtolower(trim((string) ($data['outcome'] ?? '')));
        $status = strtolower(trim((string) ($data['status'] ?? '')));

        if ($outcome === 'cancelled' || $outcome === 'error') {
            return response()->json([
                'success' => true,
                'message' => 'Payment was not completed.',
                'payment' => [
                    'txnid' => $txnid,
                    'status' => $outcome,
                    'activated' => false,
                ],
            ]);
        }

        if ($outcome === 'failure' || ($status !== '' && $status !== 'success')) {
            return response()->json([
                'success' => true,
                'message' => 'Payment failed.',
                'payment' => [
                    'txnid' => $txnid,
                    'status' => 'failure',
                    'activated' => false,
                ],
            ]);
        }

        $existing = $this->findSuccessfulPayment($txnid, (int) $user->id);
        if ($existing instanceof Payment) {
            $subscription = Subscription::query()
                ->where('user_id', $user->id)
                ->where('plan_id', $existing->plan_id)
                ->orderByDesc('id')
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Payment already verified.',
                'payment' => [
                    'txnid' => $txnid,
                    'status' => 'success',
                    'activated' => $subscription instanceof Subscription,
                    'plan_id' => (int) $existing->plan_id,
                ],
            ]);
        }

        $payuPayload = array_merge(
            is_array($data['sdk_response'] ?? null) ? $data['sdk_response'] : [],
            [
                'txnid' => $txnid,
                'status' => $data['status'] ?? 'success',
                'hash' => $data['hash'] ?? '',
                'amount' => $data['amount'] ?? '',
                'productinfo' => $data['productinfo'] ?? '',
                'firstname' => $data['firstname'] ?? '',
                'email' => $data['email'] ?? '',
                'key' => $data['key'] ?? (string) config('payu.merchant_key', ''),
                'udf1' => $data['udf1'] ?? (string) $user->id,
                'udf2' => $data['udf2'] ?? '',
                'udf3' => $data['udf3'] ?? '',
                'udf4' => $data['udf4'] ?? '',
                'udf5' => $data['udf5'] ?? '',
                'mihpayid' => $data['mihpayid'] ?? null,
                'mode' => $data['mode'] ?? null,
            ],
        );

        $postedHash = strtolower(trim((string) ($payuPayload['hash'] ?? '')));
        if ($postedHash !== '') {
            if (! $this->responseHashMatches($payuPayload)) {
                return $this->error('Payment signature mismatch.', 422, 'response_hash_mismatch');
            }
        }

        $pending = Cache::pull(self::PENDING_CACHE_PREFIX.$txnid);
        if (! is_array($pending)) {
            // SURL may have finalized already; re-check payment.
            $existing = $this->findSuccessfulPayment($txnid, (int) $user->id);
            if ($existing instanceof Payment) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment already verified.',
                    'payment' => [
                        'txnid' => $txnid,
                        'status' => 'success',
                        'activated' => true,
                        'plan_id' => (int) $existing->plan_id,
                    ],
                ]);
            }

            return $this->error('Checkout session expired. If money was deducted, contact support with txnid '.$txnid.'.', 422, 'checkout_session_missing');
        }

        if ((int) ($pending['user_id'] ?? 0) !== (int) $user->id) {
            return $this->error('Checkout session mismatch.', 403, 'checkout_user_mismatch');
        }

        $plan = Plan::query()->find((int) ($pending['plan_id'] ?? 0));
        if (! $plan instanceof Plan) {
            return $this->error(__('subscriptions.subscribe_failed'), 422, 'plan_missing');
        }

        try {
            $subscription = $subscriptions->finalizePayuSubscription(
                $user,
                $plan,
                $pending,
                $txnid,
                $payuPayload,
            );
        } catch (HttpException $exception) {
            return $this->error($exception->getMessage(), $this->httpStatus($exception), 'finalize_failed');
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(__('subscriptions.subscribe_failed'), 422, 'finalize_failed');
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment verified and subscription activated.',
            'payment' => [
                'txnid' => $txnid,
                'status' => 'success',
                'activated' => true,
                'plan_id' => (int) $plan->id,
                'subscription_id' => (int) $subscription->id,
            ],
        ]);
    }

    private function responseHashMatches(array $data): bool
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

    private function findSuccessfulPayment(string $txnid, int $userId): ?Payment
    {
        return Payment::query()
            ->where('user_id', $userId)
            ->where('payment_status', 'success')
            ->where(function ($q) use ($txnid) {
                $q->where('txnid', $txnid);
                if (Schema::hasColumn('payments', 'payu_txnid')) {
                    $q->orWhere('payu_txnid', $txnid);
                }
            })
            ->first();
    }

    private function isMobileBuyablePlan(User $user, Plan $plan): bool
    {
        return (bool) $plan->is_active
            && (bool) $plan->is_visible
            && ! Plan::isFreeCatalogSlug((string) $plan->slug)
            && Plan::profileGenderAllowsPlan($user, $plan);
    }

    private function payuConfigMissing(): bool
    {
        return trim((string) config('payu.merchant_key', '')) === ''
            || trim((string) config('payu.merchant_salt', '')) === ''
            || trim((string) config('payu.checkout_url', '')) === '';
    }

    /**
     * CheckoutPro: "0" = production, "1" = test.
     */
    private function payuSdkEnvironment(): string
    {
        $base = strtolower((string) config('payu.checkout_url', ''));
        if (str_contains($base, 'test.payu.in') || str_contains($base, 'sandbox')) {
            return '1';
        }

        return '0';
    }

    private function payuFirstName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));
        if ($name === '') {
            return 'Member';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        $first = trim((string) ($parts[0] ?? 'Member'));
        if ($first === '') {
            return 'Member';
        }

        return Str::limit($first, 60, '');
    }

    private function httpStatus(HttpException $exception): int
    {
        $status = $exception->getStatusCode();

        return $status >= 400 && $status < 600 ? $status : 422;
    }

    private function error(string $message, int $status, ?string $blockedReason = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];
        if ($blockedReason !== null) {
            $payload['blocked_reason'] = $blockedReason;
        }

        return response()->json($payload, $status);
    }
}
