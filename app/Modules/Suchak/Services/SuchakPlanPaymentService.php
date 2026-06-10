<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanInvoice;
use App\Models\SuchakPlanPayment;
use App\Models\SuchakSubscription;
use App\Models\User;
use App\Support\PayuHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuchakPlanPaymentService
{
    public function __construct(
        private readonly SuchakAccessService $accessService,
        private readonly SuchakActivityLogger $activityLogger,
        private readonly SuchakPolicyService $policyService,
    ) {
    }

    /**
     * @return array{
     *     action: string,
     *     fields: array<string, string>,
     *     payment: SuchakPlanPayment
     * }
     */
    public function startCheckout(
        SuchakAccount $account,
        User $actor,
        SuchakPlan $plan,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $this->accessService->assertOwnerCanOperate(
            $account,
            $actor,
            'Only the owning Suchak account can start a plan payment.',
            'Only verified Suchak accounts can start a plan payment.',
        );
        $this->assertPayuTestModeEnabled();

        $plan->refresh();
        if (! $plan->is_active || ! $plan->is_visible) {
            throw new InvalidArgumentException('Only active visible Suchak plans can be purchased.');
        }
        if (! $plan->hasConfiguredPrice() || (float) $plan->price_amount <= 0.0) {
            throw new InvalidArgumentException('Suchak plan price is not configured for PayU checkout.');
        }
        if (strtoupper((string) $plan->currency) !== 'INR') {
            throw new InvalidArgumentException('Suchak PayU checkout currently supports INR plans only.');
        }

        $merchantKey = (string) config('payu.merchant_key', '');
        $salt = (string) config('payu.merchant_salt', '');
        $checkoutUrl = (string) config('payu.checkout_url', '');
        if ($merchantKey === '' || $salt === '' || $checkoutUrl === '') {
            throw new HttpException(503, 'PayU test configuration is missing.');
        }

        $txnid = 'SUC'.strtoupper(Str::random(18));
        $amount = number_format((float) $plan->price_amount, 2, '.', '');
        $productInfo = $this->productInfoFor($plan);
        $firstname = self::payuFirstName($actor);
        $email = strtolower(trim((string) ($actor->email ?: $account->email ?: 'suchak@example.com')));

        $built = PayuHasher::paymentRequestHash(
            $merchantKey,
            $txnid,
            $amount,
            $productInfo,
            $firstname,
            $email,
            $salt,
            (string) $actor->id,
            (string) $account->id,
            (string) $plan->id,
            'suchak_plan',
            '',
        );

        $payment = SuchakPlanPayment::query()->create([
            'suchak_account_id' => $account->id,
            'suchak_plan_id' => $plan->id,
            'initiated_by_user_id' => $actor->id,
            'txnid' => $txnid,
            'plan_name' => $plan->name,
            'plan_slug' => $plan->slug,
            'billing_period_days' => max(1, (int) $plan->billing_period_days),
            'amount' => $amount,
            'currency' => 'INR',
            'payment_status' => SuchakPlanPayment::STATUS_PENDING,
            'gateway' => 'payu',
            'source' => 'checkout',
            'product_info' => $productInfo,
        ]);

        $this->activityLogger->record([
            'suchak_account_id' => $account->id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => SuchakActivityLog::ACTION_PLAN_PAYMENT_INITIATED,
            'target_type' => 'suchak_plan_payment',
            'target_id' => $payment->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata_json' => [
                'suchak_plan_id' => $plan->id,
                'suchak_plan_slug' => $plan->slug,
                'amount' => $amount,
                'currency' => 'INR',
                'gateway' => 'payu',
                'payment_mode' => $this->policyService->paymentMode(),
            ],
        ]);

        return [
            'action' => $checkoutUrl,
            'fields' => [
                'key' => $built['key'],
                'txnid' => $built['txnid'],
                'amount' => $built['amount'],
                'productinfo' => $built['productinfo'],
                'firstname' => $built['firstname'],
                'email' => $built['email'],
                'surl' => route('suchak.plans.payu.success', [], true),
                'furl' => route('suchak.plans.payu.failure', [], true),
                'udf1' => $built['udf1'],
                'udf2' => $built['udf2'],
                'udf3' => $built['udf3'],
                'udf4' => $built['udf4'],
                'udf5' => $built['udf5'],
                'service_provider' => 'payuindia',
                'hash' => $built['hash'],
            ],
            'payment' => $payment,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function completeSuccessfulCallback(array $data, string $source = 'redirect'): SuchakPlanPayment
    {
        $this->assertPayuResponseHash($data);

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if ($status !== 'success') {
            throw new HttpException(422, 'PayU callback is not successful.');
        }

        return DB::transaction(function () use ($data, $source, $status): SuchakPlanPayment {
            $payment = $this->lockedPaymentFromCallback($data);

            if ($payment->payment_status === SuchakPlanPayment::STATUS_SUCCESS
                && $payment->suchak_subscription_id !== null) {
                $this->ensureInvoice($payment);

                return $payment->fresh(['invoice', 'suchakSubscription', 'suchakPlan']);
            }

            $this->assertCallbackMatchesPayment($payment, $data);

            $subscription = $this->activateSubscription($payment);

            $payment->fill([
                'suchak_subscription_id' => $subscription->id,
                'gateway_txnid' => trim((string) ($data['mihpayid'] ?? '')),
                'payment_status' => SuchakPlanPayment::STATUS_SUCCESS,
                'source' => $source,
                'gateway_status' => $status,
                'gateway_mode' => trim((string) ($data['mode'] ?? '')),
                'response_hash' => strtolower(trim((string) ($data['hash'] ?? ''))),
                'paid_at' => now(),
                'failed_at' => null,
            ])->save();

            $invoice = $this->ensureInvoice($payment);

            $this->activityLogger->record([
                'suchak_account_id' => $payment->suchak_account_id,
                'actor_user_id' => null,
                'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
                'action_type' => SuchakActivityLog::ACTION_PLAN_PAYMENT_COMPLETED,
                'target_type' => 'suchak_plan_payment',
                'target_id' => $payment->id,
                'metadata_json' => [
                    'suchak_plan_id' => $payment->suchak_plan_id,
                    'suchak_subscription_id' => $subscription->id,
                    'suchak_plan_invoice_id' => $invoice->id,
                    'txnid' => $payment->txnid,
                    'source' => $source,
                    'amount' => (string) $payment->amount,
                    'currency' => $payment->currency,
                ],
            ]);

            return $payment->fresh(['invoice', 'suchakSubscription', 'suchakPlan']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function completeFailureCallback(array $data, string $source = 'redirect'): SuchakPlanPayment
    {
        $this->assertPayuResponseHash($data);

        return DB::transaction(function () use ($data, $source): SuchakPlanPayment {
            $payment = $this->lockedPaymentFromCallback($data);

            if ($payment->payment_status === SuchakPlanPayment::STATUS_SUCCESS) {
                return $payment->fresh(['invoice', 'suchakSubscription', 'suchakPlan']);
            }

            $this->assertCallbackMatchesPayment($payment, $data);

            $payment->fill([
                'gateway_txnid' => trim((string) ($data['mihpayid'] ?? '')),
                'payment_status' => SuchakPlanPayment::STATUS_FAILED,
                'source' => $source,
                'gateway_status' => strtolower(trim((string) ($data['status'] ?? 'failed'))),
                'gateway_mode' => trim((string) ($data['mode'] ?? '')),
                'response_hash' => strtolower(trim((string) ($data['hash'] ?? ''))),
                'failed_at' => now(),
            ])->save();

            $this->activityLogger->record([
                'suchak_account_id' => $payment->suchak_account_id,
                'actor_user_id' => null,
                'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
                'action_type' => SuchakActivityLog::ACTION_PLAN_PAYMENT_FAILED,
                'target_type' => 'suchak_plan_payment',
                'target_id' => $payment->id,
                'metadata_json' => [
                    'suchak_plan_id' => $payment->suchak_plan_id,
                    'txnid' => $payment->txnid,
                    'source' => $source,
                    'gateway_status' => $payment->gateway_status,
                    'amount' => (string) $payment->amount,
                    'currency' => $payment->currency,
                    'subscription_activated' => false,
                ],
            ]);

            return $payment->fresh(['invoice', 'suchakSubscription', 'suchakPlan']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handleWebhook(array $data): SuchakPlanPayment
    {
        $status = strtolower(trim((string) ($data['status'] ?? '')));

        if ($status === 'success') {
            return $this->completeSuccessfulCallback($data, 'webhook');
        }

        return $this->completeFailureCallback($data, 'webhook');
    }

    public function successMessage(SuchakPlanPayment $payment): string
    {
        return 'Suchak plan payment recorded for '.$payment->plan_name.'.';
    }

    private function assertPayuTestModeEnabled(): void
    {
        if ($this->policyService->paymentMode() !== 'payu_test_mode') {
            throw new InvalidArgumentException('Suchak PayU test mode is not enabled.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function lockedPaymentFromCallback(array $data): SuchakPlanPayment
    {
        $txnid = trim((string) ($data['txnid'] ?? ''));
        if ($txnid === '') {
            throw new HttpException(422, 'Missing Suchak PayU transaction id.');
        }

        $payment = SuchakPlanPayment::query()
            ->where('txnid', $txnid)
            ->lockForUpdate()
            ->first();

        if (! $payment) {
            Log::warning('suchak_payu_callback_unknown_txnid', ['txnid' => $txnid]);
            throw new HttpException(422, 'Unknown Suchak PayU transaction.');
        }

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertCallbackMatchesPayment(SuchakPlanPayment $payment, array $data): void
    {
        $postedAmount = number_format((float) trim((string) ($data['amount'] ?? '0')), 2, '.', '');
        $expectedAmount = number_format((float) $payment->amount, 2, '.', '');
        if (! hash_equals($expectedAmount, $postedAmount)) {
            throw new HttpException(422, 'Suchak PayU amount mismatch.');
        }

        $productInfo = trim((string) ($data['productinfo'] ?? ''));
        if (! hash_equals((string) $payment->product_info, $productInfo)) {
            throw new HttpException(422, 'Suchak PayU product mismatch.');
        }

        $actorId = (int) trim((string) ($data['udf1'] ?? '0'));
        $accountId = (int) trim((string) ($data['udf2'] ?? '0'));
        $planId = (int) trim((string) ($data['udf3'] ?? '0'));
        $paymentType = trim((string) ($data['udf4'] ?? ''));

        if ($actorId !== (int) $payment->initiated_by_user_id
            || $accountId !== (int) $payment->suchak_account_id
            || $planId !== (int) $payment->suchak_plan_id
            || $paymentType !== 'suchak_plan') {
            throw new HttpException(422, 'Suchak PayU context mismatch.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertPayuResponseHash(array $data): void
    {
        $salt = (string) config('payu.merchant_salt', '');
        $expectedKey = (string) config('payu.merchant_key', '');
        $postedKey = trim((string) ($data['key'] ?? ''));
        $postedHash = strtolower(trim((string) ($data['hash'] ?? '')));

        if ($salt === '' || $expectedKey === '' || $postedKey !== $expectedKey || $postedHash === '') {
            throw new HttpException(400, 'Suchak PayU callback configuration mismatch.');
        }

        $expected = PayuHasher::paymentResponseHash(
            $salt,
            (string) ($data['status'] ?? ''),
            trim((string) ($data['email'] ?? '')),
            trim((string) ($data['firstname'] ?? '')),
            trim((string) ($data['productinfo'] ?? '')),
            trim((string) ($data['amount'] ?? '')),
            trim((string) ($data['txnid'] ?? '')),
            $postedKey,
            (string) ($data['udf1'] ?? ''),
            (string) ($data['udf2'] ?? ''),
            (string) ($data['udf3'] ?? ''),
            (string) ($data['udf4'] ?? ''),
            (string) ($data['udf5'] ?? ''),
        );

        if (! hash_equals($expected, $postedHash)) {
            throw new HttpException(400, 'Suchak PayU callback hash mismatch.');
        }
    }

    private function activateSubscription(SuchakPlanPayment $payment): SuchakSubscription
    {
        $now = now();
        $periodDays = max(1, (int) $payment->billing_period_days);
        $active = SuchakSubscription::query()
            ->where('suchak_account_id', $payment->suchak_account_id)
            ->where('status', SuchakSubscription::STATUS_ACTIVE)
            ->where('starts_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $now);
            })
            ->lockForUpdate()
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();

        $base = $now->copy();
        if ($active
            && (int) $active->suchak_plan_id === (int) $payment->suchak_plan_id
            && $active->ends_at
            && $active->ends_at->greaterThan($now)) {
            $base = $active->ends_at->copy();
        }

        SuchakSubscription::query()
            ->where('suchak_account_id', $payment->suchak_account_id)
            ->where('status', SuchakSubscription::STATUS_ACTIVE)
            ->update([
                'status' => SuchakSubscription::STATUS_CANCELLED,
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);

        return SuchakSubscription::query()->create([
            'suchak_account_id' => $payment->suchak_account_id,
            'suchak_plan_id' => $payment->suchak_plan_id,
            'assigned_by_user_id' => null,
            'status' => SuchakSubscription::STATUS_ACTIVE,
            'starts_at' => $now,
            'ends_at' => $base->copy()->addDays($periodDays),
            'assigned_at' => $now,
            'notes' => 'Activated by PayU txn '.$payment->txnid,
        ]);
    }

    private function ensureInvoice(SuchakPlanPayment $payment): SuchakPlanInvoice
    {
        $existing = SuchakPlanInvoice::query()
            ->where('suchak_plan_payment_id', $payment->id)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        $dt = $payment->paid_at ?? now();
        $year = (int) $dt->format('Y');
        $month = (int) $dt->format('n');
        $fyStart = $month >= 4 ? $year : ($year - 1);
        $fyEnd = $fyStart + 1;
        $fyLabel = substr((string) $fyStart, -2).'-'.substr((string) $fyEnd, -2);
        $lastSeq = (int) SuchakPlanInvoice::query()
            ->where('fy_label', $fyLabel)
            ->lockForUpdate()
            ->max('sequence_no');
        $nextSeq = $lastSeq + 1;

        $invoice = SuchakPlanInvoice::query()->create([
            'suchak_plan_payment_id' => $payment->id,
            'invoice_number' => 'SUCHAK/'.$fyLabel.'/'.str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT),
            'fy_label' => $fyLabel,
            'sequence_no' => $nextSeq,
            'issued_at' => now(),
        ]);

        $this->activityLogger->record([
            'suchak_account_id' => $payment->suchak_account_id,
            'actor_user_id' => null,
            'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
            'action_type' => SuchakActivityLog::ACTION_PLAN_INVOICE_CREATED,
            'target_type' => 'suchak_plan_invoice',
            'target_id' => $invoice->id,
            'metadata_json' => [
                'suchak_plan_payment_id' => $payment->id,
                'invoice_number' => $invoice->invoice_number,
                'txnid' => $payment->txnid,
            ],
        ]);

        return $invoice;
    }

    private function productInfoFor(SuchakPlan $plan): string
    {
        return Str::limit('suchak-plan-'.$plan->slug, 120, '');
    }

    private static function payuFirstName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));
        if ($name === '') {
            return 'Suchak';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        $first = trim((string) ($parts[0] ?? 'Suchak'));

        return $first !== '' ? Str::limit($first, 60, '') : 'Suchak';
    }
}
