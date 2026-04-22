<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Support\PayuHasher;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PayuController extends Controller
{
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
        $status = strtolower(trim((string) ($data['status'] ?? '')));

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

        if ($status !== 'success') {
            Log::error('Payment failed validation', [
                'reason' => 'non_success_status',
                'status' => $status,
                'txnid' => $data['txnid'] ?? null,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Payment failed',
            ], 400);
        }

        return $this->processSuccessfulPayment($data);
    }

    public function failure(Request $request)
    {
        Log::warning('PayU failure callback', $request->all());

        return response('Payment Failed', 200);
    }

    public function webhook(Request $request)
    {
        $data = $request->all();
        $status = strtolower(trim((string) ($data['status'] ?? '')));

        if (! $this->verifyPayuResponseHash($data)) {
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

        if ($status !== 'success') {
            Log::info('PayU webhook: ignored status', ['status' => $status]);

            return response()->json([
                'status' => 'success',
                'message' => 'Ignored',
            ]);
        }

        return $this->processSuccessfulPayment($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function processSuccessfulPayment(array $data): Response
    {
        $status = strtolower(trim((string) ($data['status'] ?? '')));
        $email = trim((string) ($data['email'] ?? ''));
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

        $userId = auth()->id();
        if (! $userId && $email !== '') {
            $userId = User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])
                ->value('id');
        }

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

        try {
            DB::transaction(function () use ($data, $status, $userId, $txnid, $amount, $planId, $plan): void {
                try {
                    Payment::create([
                        'user_id' => $userId,
                        'txnid' => $txnid,
                        'amount' => $amount,
                        'status' => $status,
                        'gateway' => 'payu',
                        'payload' => $data,
                    ]);
                } catch (QueryException $e) {
                    if ($this->isDuplicateTxnQueryException($e)) {
                        throw new \RuntimeException('payu_duplicate_txnid');
                    }
                    throw $e;
                }

                $user = User::query()->find($userId);
                if ($user) {
                    $user->plan = $planId;
                    if ($user->plan_expires_at && $user->plan_expires_at > now()) {
                        $user->plan_expires_at = $user->plan_expires_at->copy()->addDays((int) $plan['days']);
                    } else {
                        $user->plan_expires_at = now()->addDays((int) $plan['days']);
                    }
                    $user->save();
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'payu_duplicate_txnid') {
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
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment successful',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function verifyPayuResponseHash(array $data): bool
    {
        $salt = (string) config('payu.salt');
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
        );

        return hash_equals($expected, $postedHash);
    }

    private function isDuplicateTxnQueryException(QueryException $e): bool
    {
        // MySQL 1062 / SQLSTATE 23000; SQLite constraint (typical tests).
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
