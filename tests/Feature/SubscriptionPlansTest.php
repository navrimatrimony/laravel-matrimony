<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Support\PayuHasher;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlansTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payu.merchant_key' => 'test_merchant_key',
            'payu.merchant_salt' => 'test_merchant_salt',
            'payu.checkout_url' => 'https://test.payu.in/_payment',
        ]);
    }

    public function test_guest_can_view_plans_catalog(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $this->get(route('plans.index'))
            ->assertOk()
            ->assertSee(__('subscriptions.pricing_page_title'));
    }

    public function test_authenticated_user_can_view_plans(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('plans.index'))
            ->assertOk()
            ->assertSee(__('subscriptions.pricing_cta_upgrade'));
    }

    public function test_subscribe_does_not_create_subscription_before_payu(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $paidPlan = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $price = PlanPrice::query()
            ->where('plan_id', $paidPlan->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->first();
        $this->assertNotNull($price);

        $this->actingAs($user)
            ->post(route('plans.subscribe'), [
                'plan' => $paidPlan->slug,
                'plan_price_id' => $price->id,
            ])
            ->assertOk()
            ->assertViewIs('payments.payu_redirect')
            ->assertSee('https://test.payu.in/_payment', false);

        $this->assertDatabaseMissing('subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $paidPlan->id,
        ]);
    }

    public function test_payu_success_callback_creates_subscription(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $paidPlan = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $price = PlanPrice::query()
            ->where('plan_id', $paidPlan->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->first();
        $this->assertNotNull($price);

        $checkout = $this->actingAs($user)
            ->post(route('plans.subscribe'), [
                'plan' => $paidPlan->slug,
                'plan_price_id' => $price->id,
            ]);
        $checkout->assertOk();
        /** @var array<string, string> $fields */
        $fields = $checkout->viewData('fields');

        $salt = (string) config('payu.merchant_salt');
        $status = 'success';
        $hash = PayuHasher::paymentResponseHash(
            $salt,
            $status,
            $fields['email'],
            $fields['firstname'],
            $fields['productinfo'],
            $fields['amount'],
            $fields['txnid'],
            $fields['key'],
        );

        $this->post(route('payu.success'), [
            'key' => $fields['key'],
            'txnid' => $fields['txnid'],
            'amount' => $fields['amount'],
            'productinfo' => $fields['productinfo'],
            'firstname' => $fields['firstname'],
            'email' => $fields['email'],
            'status' => $status,
            'hash' => $hash,
        ])->assertRedirect(route('plans.index'));

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $paidPlan->id,
            'plan_price_id' => $price->id,
            'status' => 'active',
        ]);
    }
}
