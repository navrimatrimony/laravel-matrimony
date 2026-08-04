<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\PayuHasher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * CheckoutPro android_surl / android_furl handlers.
 *
 * Must reverse-hash verify and emit {@code PayU.onSuccess}/{@code PayU.onFailure}
 * so the native SDK can return control to the Flutter app.
 *
 * @see https://docs.payu.in/docs/handling-redirect-urls-surlfurl-with-android-sdk
 */
class MobilePayuSdkReturnController extends Controller
{
    public const PENDING_CACHE_PREFIX = 'payu_subscription:';

    public function success(Request $request, SubscriptionService $subscriptions): Response
    {
        $data = $request->all();
        $txnid = trim((string) ($data['txnid'] ?? ''));

        Log::info('payu_sdk_surl_received', [
            'txnid' => $txnid,
            'status' => $data['status'] ?? null,
        ]);

        if (! $this->responseHashMatches($data)) {
            Log::warning('payu_sdk_surl_hash_mismatch', ['txnid' => $txnid]);

            return $this->sdkJsResponse('failure', 'Invalid transaction signature.');
        }

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if ($status !== 'success') {
            return $this->sdkJsResponse('failure', 'Payment status was not success.');
        }

        try {
            $this->finalizeFromGatewayPayload($data, $subscriptions);
        } catch (Throwable $exception) {
            report($exception);
            Log::error('payu_sdk_surl_finalize_failed', [
                'txnid' => $txnid,
                'message' => $exception->getMessage(),
            ]);

            // Still tell SDK success if gateway hash passed — Flutter verify is backup.
            // Prefer failure JS only when we are sure activation cannot proceed.
            if ($this->hasSuccessfulPayment($txnid)) {
                return $this->sdkJsResponse('success', $this->encodePayload($data));
            }

            return $this->sdkJsResponse('failure', 'Payment could not be finalized.');
        }

        return $this->sdkJsResponse('success', $this->encodePayload($data));
    }

    public function failure(Request $request): Response
    {
        $data = $request->all();
        $txnid = trim((string) ($data['txnid'] ?? ''));

        Log::info('payu_sdk_furl_received', [
            'txnid' => $txnid,
            'status' => $data['status'] ?? null,
        ]);

        return $this->sdkJsResponse('failure', $this->encodePayload($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function finalizeFromGatewayPayload(array $data, SubscriptionService $subscriptions): void
    {
        $txnid = trim((string) ($data['txnid'] ?? ''));
        if ($txnid === '') {
            throw new HttpException(422, 'Missing txnid');
        }

        if ($this->hasSuccessfulPayment($txnid)) {
            return;
        }

        $pending = Cache::pull(self::PENDING_CACHE_PREFIX.$txnid);
        if (! is_array($pending)) {
            if ($this->hasSuccessfulPayment($txnid)) {
                return;
            }
            throw new HttpException(422, 'Missing pending checkout');
        }

        $userId = (int) ($pending['user_id'] ?? 0);
        $user = User::query()->find($userId);
        $plan = Plan::query()->find((int) ($pending['plan_id'] ?? 0));
        if (! $user instanceof User || ! $plan instanceof Plan) {
            throw new HttpException(422, 'Invalid pending checkout');
        }

        $expectedAmount = number_format((float) ($pending['amount'] ?? 0), 2, '.', '');
        $postedAmount = number_format((float) trim((string) ($data['amount'] ?? '')), 2, '.', '');
        if ($expectedAmount === '0.00' || ! hash_equals($expectedAmount, $postedAmount)) {
            throw new HttpException(422, 'Amount mismatch');
        }

        $subscriptions->finalizePayuSubscription($user, $plan, $pending, $txnid, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
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

    private function hasSuccessfulPayment(string $txnid): bool
    {
        if ($txnid === '') {
            return false;
        }

        return Payment::query()
            ->where('payment_status', 'success')
            ->where(function ($q) use ($txnid) {
                $q->where('txnid', $txnid);
                if (Schema::hasColumn('payments', 'payu_txnid')) {
                    $q->orWhere('payu_txnid', $txnid);
                }
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encodePayload(array $data): string
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $safe[(string) $key] = (string) $value;
        }

        return json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function sdkJsResponse(string $type, string $payload): Response
    {
        $fn = $type === 'success' ? 'PayU.onSuccess' : 'PayU.onFailure';
        // Escape for JS string literal.
        $escaped = str_replace(
            ['\\', "'", "\n", "\r", '</'],
            ['\\\\', "\\'", '\\n', '', '<\\/'],
            $payload,
        );

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PayU</title></head><body>'
            .'<script type="text/javascript">'
            .$fn."('".$escaped."');"
            .'</script>'
            .'<p>Please wait…</p>'
            .'</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
