<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakPlan;
use App\Models\SuchakPlanInvoice;
use App\Models\SuchakPlanPayment;
use App\Models\SuchakPolicy;
use App\Models\SuchakSubscription;
use App\Models\User;
use App\Support\PayuHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuchakPlanPaymentPayuReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_payu_checkout_reuses_existing_hash_form_and_creates_pending_payment(): void
    {
        $this->enablePayuTestMode();
        [$user] = $this->verifiedSuchakActor();
        $plan = $this->paidSuchakPlan(['billing_period_days' => 45]);

        $response = $this->actingAs($user)
            ->post(route('suchak.plans.payu.start', $plan));

        $response
            ->assertOk()
            ->assertViewIs('payments.payu_redirect')
            ->assertViewHas('action', 'https://test.payu.in/_payment')
            ->assertViewHas('fields');

        $fields = $response->viewData('fields');
        $this->assertSame('test_merchant_key', $fields['key']);
        $this->assertStringStartsWith('SUC', $fields['txnid']);
        $this->assertSame('1500.00', $fields['amount']);
        $this->assertSame('suchak-plan-suchak-growth-day-31', $fields['productinfo']);
        $this->assertSame('suchak_plan', $fields['udf4']);

        $this->assertDatabaseHas('suchak_plan_payments', [
            'txnid' => $fields['txnid'],
            'suchak_plan_id' => $plan->id,
            'payment_status' => SuchakPlanPayment::STATUS_PENDING,
            'amount' => '1500.00',
            'billing_period_days' => 45,
        ]);
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_suchak_payu_success_activates_subscription_creates_invoice_and_is_idempotent(): void
    {
        $this->enablePayuTestMode();
        [$user, $account] = $this->verifiedSuchakActor();
        $plan = $this->paidSuchakPlan(['billing_period_days' => 45]);
        $fields = $this->startCheckoutFields($user, $plan);
        $payload = $this->callbackPayload($fields, 'success');

        $this->app['auth']->guard()->logout();

        $this->post(route('suchak.plans.payu.success'), $payload)
            ->assertRedirect(route('suchak.dashboard'));

        $payment = SuchakPlanPayment::query()->where('txnid', $fields['txnid'])->firstOrFail();
        $subscription = SuchakSubscription::query()->firstOrFail();
        $invoice = SuchakPlanInvoice::query()->firstOrFail();

        $this->assertSame(SuchakPlanPayment::STATUS_SUCCESS, $payment->payment_status);
        $this->assertSame((int) $subscription->id, (int) $payment->suchak_subscription_id);
        $this->assertSame((int) $account->id, (int) $subscription->suchak_account_id);
        $this->assertSame((int) $plan->id, (int) $subscription->suchak_plan_id);
        $this->assertSame(SuchakSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(now()->addDays(45)->toDateString(), $subscription->ends_at?->toDateString());
        $this->assertSame((int) $payment->id, (int) $invoice->suchak_plan_payment_id);
        $this->assertStringStartsWith('SUCHAK/', $invoice->invoice_number);
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('subscriptions')->count());

        $this->post(route('suchak.plans.payu.success'), $payload)
            ->assertRedirect(route('suchak.dashboard'));
        $this->postJson(route('suchak.plans.payu.webhook'), $payload)
            ->assertOk()
            ->assertJson([
                'status' => SuchakPlanPayment::STATUS_SUCCESS,
                'txnid' => $fields['txnid'],
            ]);

        $this->assertSame(1, SuchakPlanPayment::query()->count());
        $this->assertSame(1, SuchakSubscription::query()->count());
        $this->assertSame(1, SuchakPlanInvoice::query()->count());
    }

    public function test_suchak_payu_failure_does_not_activate_subscription_or_invoice(): void
    {
        $this->enablePayuTestMode();
        [$user] = $this->verifiedSuchakActor();
        $plan = $this->paidSuchakPlan();
        $fields = $this->startCheckoutFields($user, $plan);
        $payload = $this->callbackPayload($fields, 'failure');

        $this->app['auth']->guard()->logout();

        $this->post(route('suchak.plans.payu.failure'), $payload)
            ->assertRedirect(route('suchak.dashboard'));

        $payment = SuchakPlanPayment::query()->where('txnid', $fields['txnid'])->firstOrFail();

        $this->assertSame(SuchakPlanPayment::STATUS_FAILED, $payment->payment_status);
        $this->assertNull($payment->suchak_subscription_id);
        $this->assertSame(0, SuchakSubscription::query()->count());
        $this->assertSame(0, SuchakPlanInvoice::query()->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(): array
    {
        $user = User::factory()->create(['email' => 'suchak@example.test']);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user, $account];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function paidSuchakPlan(array $overrides = []): SuchakPlan
    {
        return SuchakPlan::factory()->create(array_merge([
            'name' => 'Suchak Growth Day 31',
            'slug' => 'suchak-growth-day-31',
            'price_amount' => '1500.00',
            'currency' => 'INR',
            'billing_period_days' => 30,
            'is_active' => true,
            'is_visible' => true,
        ], $overrides));
    }

    /**
     * @return array<string, string>
     */
    private function startCheckoutFields(User $user, SuchakPlan $plan): array
    {
        $response = $this->actingAs($user)
            ->post(route('suchak.plans.payu.start', $plan));

        $response->assertOk()->assertViewHas('fields');

        return $response->viewData('fields');
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function callbackPayload(array $fields, string $status): array
    {
        $payload = array_merge($fields, [
            'status' => $status,
            'mihpayid' => 'TEST_SUCHAK_GATEWAY',
            'mode' => 'TEST',
        ]);
        $payload['hash'] = PayuHasher::paymentResponseHash(
            (string) config('payu.merchant_salt'),
            $status,
            $payload['email'],
            $payload['firstname'],
            $payload['productinfo'],
            $payload['amount'],
            $payload['txnid'],
            $payload['key'],
            $payload['udf1'],
            $payload['udf2'],
            $payload['udf3'],
            $payload['udf4'],
            $payload['udf5'],
        );

        return $payload;
    }

    private function enablePayuTestMode(): void
    {
        config([
            'payu.merchant_key' => 'test_merchant_key',
            'payu.merchant_salt' => 'test_merchant_salt',
            'payu.checkout_url' => 'https://test.payu.in/_payment',
        ]);

        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => 'suchak_payment_mode'],
            [
                'policy_value' => 'payu_test_mode',
                'value_type' => SuchakPolicy::TYPE_STRING,
                'description' => 'Test PayU mode for Day 31 Suchak payments.',
                'is_active' => true,
            ],
        );
    }
}
