<?php

namespace App\Http\Controllers\Payments;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\User;
use App\Support\PayuHasher;
use App\Support\PaymentLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PayuController extends Controller
{
    private const SOURCE_REDIRECT = 'redirect';

    private const SOURCE_WEBHOOK = 'webhook';

    public function start(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $key = (string) config('payu.key');
        $salt = (string) config('payu.salt');
        $action = (string) config('payu.base_url');

        if ($key === '' || $salt === '' || $action === '') {
            abort(503);
        }

        $planId = (string) $request->input('plan_id', '');
        $plan = config('plans.'.$planId);
        if (! is_array($plan)) {
            abort(400);
        }

        $txnid = uniqid('txn_');
        $amount = $plan['amount'];
        $productinfo = $planId;

        $firstname = self::payuFirstName($user);
        $email = strtolower(trim((string) ($user->email ?? '')));
        if ($email === '') {
            $email = 'member@example.com';
        }

        $built = PayuHasher::paymentRequestHash(
            $key,
            $txnid,
            $amount,
            $productinfo,
            $firstname,
            $email,
            $salt,
            $planId,
        );

        $fields = [
            'key' => $built['key'],
            'txnid' => $built['txnid'],
            'amount' => $built['amount'],
            'productinfo' => $built['productinfo'],
            'firstname' => $built['firstname'],
            'email' => $built['email'],
            'udf1' => $built['udf1'],
            'surl' => route('payment.success', [], true),
            'furl' => route('payment.failure', [], true),
            'service_provider' => 'payuindia',
            'hash' => $built['hash'],
        ];

        $mobile = trim((string) ($user->mobile ?? ''));
        if ($mobile !== '') {
            $fields['phone'] = $mobile;
        }

        return response()->view('payments.payu_redirect', [
            'action' => $action,
            'fields' => $fields,
        ]);
    }

    public function success(Request $request)
    {
        $data = $request->all();
        $this->logPaymentCallback($data, self::SOURCE_REDIRECT);

        if (! $this->verifyPayuResponseHash($data)) {
            Log::error('Payment failed validation', [
                'reason' => 'hash_mismatch',
                'txnid' => $data['txnid'] ?? null,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Hash mismatch',
            ], 400);
        }

        $paymentStatus = PaymentStatus::fromPayu((string) ($data['status'] ?? ''));

        if ($paymentStatus !== PaymentStatus::Success) {
            return $this->recordPaymentOutcome($data, self::SOURCE_REDIRECT, $paymentStatus);
        }

        return $this->processSuccessfulPayment($data, self::SOURCE_REDIRECT);
    }

    public function failure(Request $request)
    {
        $data = $request->all();
        $this->logPaymentCallback($data, self::SOURCE_REDIRECT.'_failure');

        $hasHash = isset($data['hash']) && trim((string) $data['hash']) !== '';
        if ($hasHash && ! $this->verifyPayuResponseHash($data)) {
            Log::error('Payment failed validation', [
                'reason' => 'hash_mismatch',
                'channel' => 'failure_callback',
                'txnid' => $data['txnid'] ?? null,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Hash mismatch',
            ], 400);
        }

        return $this->recordPaymentOutcome($data, self::SOURCE_REDIRECT, PaymentStatus::Failed);
    }

    public function webhook(Request $request)
    {
        $data = $request->all();
        PaymentLogger::logEvent('webhook_received', [
            'txnid' => $data['txnid'] ?? null,
            'user_id' => null,
            'plan_id' => null,
            'plan_term_id' => null,
            'gateway_status' => strtolower(trim((string) ($data['status'] ?? ''))),
            'internal_status' => 'webhook_received',
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'source' => 'webhook',
        ]);
        $this->logPaymentCallback($data, self::SOURCE_WEBHOOK);

        if (! $this->verifyPayuResponseHash($data)) {
            PaymentLogger::logEvent('payment_failed', [
                'txnid' => $data['txnid'] ?? null,
                'gateway_status' => 'signature_failed',
                'internal_status' => 'webhook_hash_mismatch',
                'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
                'source' => 'webhook',
            ]);
            Log::error('Payment failed validation', [
                'reason' => 'hash_mismatch',
                'channel' => 'webhook',
                'txnid' => $data['txnid'] ?? null,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Hash mismatch',
            ], 400);
        }

        $paymentStatus = PaymentStatus::fromPayu((string) ($data['status'] ?? ''));

        if ($paymentStatus !== PaymentStatus::Success) {
            Log::info('PayU webhook: non-success status', ['status' => $paymentStatus->value]);

            return $this->recordPaymentOutcome($data, self::SOURCE_WEBHOOK, $paymentStatus);
        }

        return $this->processSuccessfulPayment($data, self::SOURCE_WEBHOOK);
    }

    /**
     * Successful PayU payment: persist row and optionally extend plan (with downgrade guard + webhook priority).
     *
     * @param  array<string, mixed>  $data
     */
    private function processSuccessfulPayment(array $data, string $source): Response
    {
        $amount = trim((string) ($data['amount'] ?? ''));
        $txnid = trim((string) ($data['txnid'] ?? ''));

        $planId = trim((string) ($data['udf1'] ?? ''));
        $plan = $planId !== '' ? config('plans.'.$planId) : null;
        if (! is_array($plan)) {
            Log::error('Payment failed validation', [
                'reason' => 'invalid_plan',
                'plan_id' => $planId,
                'txnid' => $txnid,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid plan',
            ], 400);
        }

        $postedAmountNorm = number_format((float) $amount, 2, '.', '');
        $expectedAmountNorm = number_format((float) $plan['amount'], 2, '.', '');
        if ($postedAmountNorm !== $expectedAmountNorm) {
            Log::error('Payment failed validation', [
                'reason' => 'amount_mismatch',
                'txnid' => $txnid,
                'plan_id' => $planId,
                'expected' => $expectedAmountNorm,
                'received' => $postedAmountNorm,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Amount mismatch',
            ], 400);
        }

        $userId = $this->resolveUserId($data);
        if (! $userId) {
            Log::error('Payment failed validation', [
                'reason' => 'missing_user',
                'txnid' => $txnid,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to record payment',
            ], 422);
        }

        $statusEnum = PaymentStatus::Success;

        $existing = $txnid !== '' ? Payment::query()->where('txnid', $txnid)->first() : null;
        if ($existing && $existing->webhook_is_final && $source === self::SOURCE_REDIRECT) {
            return response()->json([
                'status' => 'success',
                'message' => 'Already finalized by webhook',
            ]);
        }

        try {
            DB::transaction(function () use ($data, $source, $userId, $txnid, $postedAmountNorm, $planId, $plan, $statusEnum): void {
                $locked = $txnid !== ''
                    ? Payment::query()->where('txnid', $txnid)->lockForUpdate()->first()
                    : null;

                $webhookFinal = ($locked && $locked->webhook_is_final) || ($source === self::SOURCE_WEBHOOK);

                Payment::query()->updateOrCreate(
                    ['txnid' => $txnid],
                    [
                        'user_id' => $userId,
                        'plan_key' => $planId,
                        'amount' => $postedAmountNorm,
                        'status' => $statusEnum->value,
                        'payment_status' => 'success',
                        'gateway' => 'payu',
                        'payload' => $data,
                        'source' => $source,
                        'is_processed' => true,
                        'webhook_is_final' => $webhookFinal,
                    ],
                );

                // Legacy PayU endpoint is payment-log only. Plan state must be derived from subscriptions SSOT.
                Log::critical('legacy_payu_plan_mutation_blocked', [
                    'txnid' => $txnid,
                    'user_id' => $userId,
                    'plan_key' => $planId,
                    'reason' => 'legacy_controller_no_longer_mutates_users_plan_columns',
                ]);
            });
        } catch (\Throwable $e) {
            if ($e instanceof QueryException && $this->isDuplicateTxnQueryException($e)) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Already processed',
                ]);
            }
            throw $e;
        }

        Log::info('Payment stored', [
            'txnid' => $txnid,
            'user_id' => $userId,
            'plan_id' => $planId,
            'amount' => $postedAmountNorm,
            'source' => $source,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment successful',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordPaymentOutcome(array $data, string $source, PaymentStatus $paymentStatus): Response
    {
        $txnid = trim((string) ($data['txnid'] ?? ''));
        if ($txnid === '') {
            Log::error('Payment failed validation', ['reason' => 'missing_txnid']);

            return response()->json([
                'status' => 'error',
                'message' => 'Missing transaction id',
            ], 422);
        }

        $userId = $this->resolveUserId($data);
        $amountRaw = trim((string) ($data['amount'] ?? '0'));
        $postedAmountNorm = number_format((float) $amountRaw, 2, '.', '');
        $planKey = trim((string) ($data['udf1'] ?? ''));

        $existing = Payment::query()->where('txnid', $txnid)->first();
        if ($existing && $existing->webhook_is_final && $source === self::SOURCE_REDIRECT) {
            return response()->json([
                'status' => 'success',
                'message' => 'Already finalized by webhook',
            ]);
        }

        $webhookFinal = ($existing && $existing->webhook_is_final) || ($source === self::SOURCE_WEBHOOK);

        if (! $userId) {
            Log::error('Payment failed validation', [
                'reason' => 'missing_user',
                'txnid' => $txnid,
                'payment_status' => $paymentStatus->value,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to record payment',
            ], 422);
        }

        $resolvedSource = $source === self::SOURCE_WEBHOOK ? self::SOURCE_WEBHOOK : self::SOURCE_REDIRECT;
        $paymentStatusValue = match ($paymentStatus) {
            PaymentStatus::Success => 'success',
            PaymentStatus::Pending => 'pending',
            PaymentStatus::Failed => 'failed',
        };
        $isProcessed = $paymentStatus === PaymentStatus::Success;

        Payment::query()->updateOrCreate(
            ['txnid' => $txnid],
            [
                'user_id' => $userId,
                'plan_key' => $planKey !== '' ? $planKey : null,
                'amount' => $postedAmountNorm,
                'status' => $paymentStatus->value,
                'payment_status' => $paymentStatusValue,
                'gateway' => 'payu',
                'payload' => $data,
                'source' => $resolvedSource,
                'is_processed' => $isProcessed,
                'webhook_is_final' => $webhookFinal,
            ],
        );

        Log::info('Payment stored', [
            'txnid' => $txnid,
            'user_id' => $userId,
            'payment_status' => $paymentStatus->value,
            'source' => $source,
        ]);

        if ($paymentStatus === PaymentStatus::Success) {
            return response()->json([
                'status' => 'success',
                'message' => 'Payment recorded',
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => $paymentStatus === PaymentStatus::Pending ? 'Payment pending' : 'Payment failed',
        ], $paymentStatus === PaymentStatus::Pending ? 202 : 400);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logPaymentCallback(array $data, string $source): void
    {
        try {
            PaymentLog::query()->create([
                'txnid' => isset($data['txnid']) ? trim((string) $data['txnid']) : null,
                'source' => $source,
                'payload' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::warning('payment_logs insert failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveUserId(array $data): ?int
    {
        $userId = auth()->id();
        if ($userId) {
            return (int) $userId;
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return null;
        }

        $found = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower($email)])
            ->value('id');

        return $found ? (int) $found : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function verifyPayuResponseHash(array $data): bool
    {
        $salt = (string) config('payu.merchant_salt', '');
        $postedHash = strtolower(trim((string) ($data['hash'] ?? '')));

        $email = trim((string) ($data['email'] ?? ''));
        $firstname = trim((string) ($data['firstname'] ?? ''));
        $productinfo = trim((string) ($data['productinfo'] ?? ''));
        $amount = trim((string) ($data['amount'] ?? ''));
        $txnid = trim((string) ($data['txnid'] ?? ''));
        $key = trim((string) ($data['key'] ?? ''));

        $expected = PayuHasher::paymentResponseHash(
            $salt,
            (string) ($data['status'] ?? ''),
            $email,
            $firstname,
            $productinfo,
            $amount,
            $txnid,
            $key,
            (string) ($data['udf1'] ?? ''),
            (string) ($data['udf2'] ?? ''),
            (string) ($data['udf3'] ?? ''),
            (string) ($data['udf4'] ?? ''),
            (string) ($data['udf5'] ?? ''),
        );

        return hash_equals($expected, $postedHash);
    }

    private function isDuplicateTxnQueryException(QueryException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        return $code === 1062
            || ($e->errorInfo[0] ?? '') === '23000'
            || $code === 19
            || str_contains(strtolower($e->getMessage()), 'duplicate')
            || str_contains(strtolower($e->getMessage()), 'unique constraint failed');
    }

    private static function payuFirstName(User $user): string
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
}
