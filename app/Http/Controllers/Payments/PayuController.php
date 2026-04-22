<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Support\PayuHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        $salt = (string) config('payu.salt');
        $postedHash = strtolower(trim((string) ($data['hash'] ?? '')));

        $status = strtolower(trim((string) ($data['status'] ?? '')));
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

        if (! hash_equals($expected, $postedHash)) {
            Log::error('PayU success callback: hash mismatch', [
                'txnid' => $data['txnid'] ?? null,
                'expected' => $expected,
                'received' => $postedHash,
            ]);

            return response('Hash mismatch', 400);
        }

        if ($status === 'success') {
            $planId = trim((string) ($data['udf1'] ?? ''));
            $plan = $planId !== '' ? config('plans.'.$planId) : null;
            if (! is_array($plan)) {
                Log::error('PayU success: invalid plan', ['plan_id' => $planId, 'txnid' => $txnid]);

                return response('Invalid plan', 400);
            }

            $postedAmountNorm = number_format((float) $amount, 2, '.', '');
            $expectedAmountNorm = number_format((float) $plan['amount'], 2, '.', '');
            if ($postedAmountNorm !== $expectedAmountNorm) {
                Log::error('PayU success: amount mismatch', [
                    'txnid' => $txnid,
                    'plan_id' => $planId,
                    'expected' => $expectedAmountNorm,
                    'received' => $postedAmountNorm,
                ]);

                return response('Amount mismatch', 400);
            }

            if ($txnid !== '' && Payment::query()->where('txnid', $txnid)->exists()) {
                return response('Already processed', 200);
            }

            $userId = auth()->id();
            if (! $userId && $email !== '') {
                $userId = User::query()
                    ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])
                    ->value('id');
            }

            if (! $userId) {
                Log::warning('PayU success: missing user for payment record', ['txnid' => $txnid]);

                return response('Unable to record payment', 422);
            }

            Payment::create([
                'user_id' => $userId,
                'txnid' => $txnid,
                'amount' => $amount,
                'status' => $status,
                'gateway' => 'payu',
                'payload' => $data,
            ]);

            Log::info('PayU payment verified', ['txnid' => $txnid]);

            $user = auth()->user() ?? User::query()->find($userId);
            if ($user) {
                $user->plan = $planId;
                $user->plan_expires_at = now()->addDays((int) $plan['days']);
                $user->save();
            }

            return response('Payment successful', 200);
        }

        return response('Payment failed', 400);
    }

    public function failure(Request $request)
    {
        Log::warning('PayU failure callback', $request->all());

        return response('Payment Failed', 200);
    }

    public function webhook(Request $request)
    {
        $data = $request->all();

        \Log::info('PayU Webhook:', $data);

        return response()->json(['status' => 'ok']);
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
