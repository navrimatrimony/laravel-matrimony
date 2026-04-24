<?php

namespace Tests\Feature;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayuTestSuccessSimulateRouteTest extends TestCase
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

    public function test_simulate_route_finishes_checkout_and_redirects_to_dashboard(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true],
        );
        $user = User::factory()->create();
        $maleGenderId = MasterGender::query()->where('key', 'male')->value('id');
        $this->assertNotNull($maleGenderId);
        MatrimonyProfile::factory()->for($user)->create([
            'gender_id' => $maleGenderId,
            'lifecycle_state' => 'active',
        ]);
        $paidPlan = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $term = PlanTerm::query()
            ->where('plan_id', $paidPlan->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->first();
        $this->assertNotNull($term);

        $this->actingAs($user)
            ->get(route('test.payment.success', ['planId' => $paidPlan->id]))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $paidPlan->id,
            'plan_term_id' => $term->id,
            'status' => 'active',
        ]);

        $testPayments = Payment::query()
            ->where('user_id', $user->id)
            ->where('payment_status', 'success')
            ->where('txnid', 'like', 'TEST%')
            ->count();
        $this->assertSame(1, $testPayments);

        $this->actingAs($user)
            ->get(route('test.payment.success', ['planId' => $paidPlan->id]))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(
            1,
            Subscription::query()
                ->where('user_id', $user->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->count(),
        );
        $this->assertSame(
            2,
            Payment::query()
                ->where('user_id', $user->id)
                ->where('payment_status', 'success')
                ->where('txnid', 'like', 'TEST%')
                ->count(),
        );
    }
}
