<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\PayuHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionController extends Controller
{
    private const PENDING_CACHE_PREFIX = 'payu_subscription:';

    public function subscribe(Request $request, SubscriptionService $subscriptions)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $validated = $request->validate([
            'plan' => ['required', 'string', 'max:128'],
            'plan_term_id' => ['nullable', 'integer'],
            'plan_price_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ]);

        $plan = Plan::query()->where('slug', $validated['plan'])->first();
        if (! $plan) {
            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.plan_inactive'));
        }

        $merchantKey = (string) config('payu.merchant_key', '');
        $salt = (string) config('payu.merchant_salt', '');
        $checkoutUrl = (string) config('payu.checkout_url', '');

        if ($merchantKey === '' || $salt === '' || $checkoutUrl === '') {
            Log::warning('PayU subscribe blocked: missing merchant configuration');

            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $rawTerm = $request->input('plan_term_id');
        $planTermId = ($rawTerm === null || $rawTerm === '') ? null : (int) $rawTerm;
        $rawPrice = $request->input('plan_price_id');
        $planPriceId = ($rawPrice === null || $rawPrice === '') ? null : (int) $rawPrice;
        $couponCode = $validated['coupon_code'] ?? null;

        Log::info('Subscribe clicked', [
            'user_id' => $user->id,
            'plan_slug' => $plan->slug,
            'plan_term_id' => $planTermId,
            'plan_price_id' => $planPriceId,
            'has_coupon' => is_string($couponCode) && trim($couponCode) !== '',
        ]);

        try {
            $resolved = $subscriptions->resolvePaidPlanCheckout(
                $user,
                $plan,
                $planTermId,
                $planPriceId,
                is_string($couponCode) ? $couponCode : null,
            );
        } catch (HttpException $e) {
            return redirect()
                ->route('plans.index')
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $finalAmount = (float) $resolved['final_amount'];
        if ($finalAmount <= 0.0) {
            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $txnid = 'SUB'.strtoupper(Str::random(18));
        // productinfo: exact DB slug for hash and form (no trim — avoids mismatch vs hash).
        $productinfo = (string) $plan->slug;
        $firstname = self::payuFirstName($user);
        $email = strtolower(trim((string) ($user->email ?? '')));
        if ($email === '') {
            $email = 'member@example.com';
        }

        $surl = route('payu.success', [], true);
        $furl = route('payu.failure', [], true);

        $udf1 = (string) $user->id;
        $udf2 = '';
        $udf3 = '';
        $udf4 = '';
        $udf5 = '';

        $built = PayuHasher::paymentRequestHash(
            $merchantKey,
            $txnid,
            $finalAmount,
            $productinfo,
            $firstname,
            $email,
            $salt,
            $udf1,
            $udf2,
            $udf3,
            $udf4,
            $udf5,
        );

        Log::info('PAYU_DEBUG_COMPARE', [
            'hash_string' => $built['hash_string'],
            'pipe_count' => $built['pipe_count'],
            'expected_pipe_count' => PayuHasher::EXPECTED_REQUEST_PIPE_COUNT,
            'pipe_match' => $built['pipe_count'] === PayuHasher::EXPECTED_REQUEST_PIPE_COUNT,
            'form_fields' => [
                'key' => $built['key'],
                'txnid' => $built['txnid'],
                'amount' => $built['amount'],
                'productinfo' => $built['productinfo'],
                'firstname' => $built['firstname'],
                'email' => $built['email'],
                'udf1' => $built['udf1'],
                'udf2' => $built['udf2'],
                'udf3' => $built['udf3'],
                'udf4' => $built['udf4'],
                'udf5' => $built['udf5'],
            ],
            'our_hash' => $built['hash'],
        ]);

        Cache::put(
            self::PENDING_CACHE_PREFIX.$txnid,
            [
                'user_id' => (int) $user->id,
                'plan_id' => (int) $plan->id,
                'plan_slug' => $built['productinfo'],
                'plan_term_id' => $resolved['plan_term_id'],
                'plan_price_id' => $resolved['plan_price_id'],
                'coupon_code' => $resolved['coupon_code'],
                'amount' => $built['amount'],
            ],
            now()->addMinutes(60),
        );

        $fields = [
            'key' => $built['key'],
            'txnid' => $built['txnid'],
            'amount' => $built['amount'],
            'productinfo' => $built['productinfo'],
            'firstname' => $built['firstname'],
            'email' => $built['email'],
            'surl' => $surl,
            'furl' => $furl,
            'udf1' => $built['udf1'],
            'udf2' => $built['udf2'],
            'udf3' => $built['udf3'],
            'udf4' => $built['udf4'],
            'udf5' => $built['udf5'],
            'service_provider' => 'payuindia',
            'hash' => $built['hash'],
        ];

        $mobile = trim((string) ($user->mobile ?? ''));
        if ($mobile !== '') {
            $fields['phone'] = $mobile;
        }

        return response()->view('payments.payu_redirect', [
            'action' => $checkoutUrl,
            'fields' => $fields,
        ]);
    }

    public function success(Request $request, SubscriptionService $subscriptions)
    {
        $data = $request->all();
        Log::info('PayU subscription success callback', [
            'txnid' => $data['txnid'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if ($status !== 'success') {
            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $txnid = trim((string) ($data['txnid'] ?? ''));
        $amount = trim((string) ($data['amount'] ?? ''));
        $productinfo = trim((string) ($data['productinfo'] ?? ''));
        $firstname = trim((string) ($data['firstname'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $postedHash = strtolower(trim((string) ($data['hash'] ?? '')));
        $key = trim((string) ($data['key'] ?? ''));

        if ($txnid === '' || $amount === '' || $postedHash === '') {
            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $salt = (string) config('payu.merchant_salt', '');
        $expectedKey = (string) config('payu.merchant_key', '');
        if ($salt === '' || $expectedKey === '' || $key !== $expectedKey) {
            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $computed = PayuHasher::paymentResponseHash(
            $salt,
            (string) ($data['status'] ?? ''),
            $email,
            $firstname,
            $productinfo,
            $amount,
            $txnid,
            $key,
        );

        if (! hash_equals($computed, $postedHash)) {
            Log::warning('PayU subscription success: hash mismatch', ['txnid' => $txnid]);

            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $pending = Cache::pull(self::PENDING_CACHE_PREFIX.$txnid);
        if (! is_array($pending)) {
            Log::warning('PayU subscription success: missing pending checkout', ['txnid' => $txnid]);

            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $expectedAmount = (string) ($pending['amount'] ?? '');
        $expectedAmountNorm = $expectedAmount !== '' ? number_format((float) $expectedAmount, 2, '.', '') : '';
        $postedAmountNorm = $amount !== '' ? number_format((float) $amount, 2, '.', '') : '';
        if ($expectedAmountNorm === '' || $postedAmountNorm === '' || ! hash_equals($expectedAmountNorm, $postedAmountNorm)) {
            Log::warning('PayU subscription success: amount mismatch', [
                'txnid' => $txnid,
                'expected' => $expectedAmountNorm,
                'received' => $postedAmountNorm,
            ]);

            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $slug = (string) ($pending['plan_slug'] ?? '');
        if ($slug !== '' && $productinfo !== '' && $slug !== $productinfo) {
            Log::warning('PayU subscription success: productinfo mismatch', [
                'txnid' => $txnid,
                'expected_slug' => $slug,
                'received_productinfo' => $productinfo,
            ]);

            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $user = User::query()->find((int) ($pending['user_id'] ?? 0));
        $plan = Plan::query()->find((int) ($pending['plan_id'] ?? 0));
        if (! $user || ! $plan) {
            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        $planTermId = isset($pending['plan_term_id']) && $pending['plan_term_id'] !== null
            ? (int) $pending['plan_term_id'] : null;
        $planPriceId = isset($pending['plan_price_id']) && $pending['plan_price_id'] !== null
            ? (int) $pending['plan_price_id'] : null;
        $coupon = isset($pending['coupon_code']) && is_string($pending['coupon_code'])
            ? $pending['coupon_code']
            : null;

        try {
            $subscriptions->subscribe($user, $plan, $planTermId, $planPriceId, $coupon);
        } catch (HttpException $e) {
            return redirect()
                ->route('plans.index')
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('plans.index')
                ->with('error', __('subscriptions.subscribe_failed'));
        }

        return redirect()
            ->route('plans.index')
            ->with('success', __('subscriptions.subscribe_success', ['plan' => $plan->name]));
    }

    public function failure(Request $request)
    {
        Log::info('PayU subscription failure callback', [
            'txnid' => $request->input('txnid'),
            'status' => $request->input('status'),
        ]);

        return redirect()
            ->route('plans.index')
            ->with('error', __('subscriptions.subscribe_failed'));
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
