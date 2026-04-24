<?php

namespace Tests\Feature;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Models\Subscription;
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

    public function test_pricing_catalog_shows_marketing_badge_ribbon_when_highlight_set(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Ribbon Test Plan',
            'slug' => 'ribbon-test-plan',
            'price' => 99,
            'discount_percent' => 0,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 0,
            'highlight' => true,
            'marketing_badge' => 'best_seller',
            'applies_to_gender' => 'all',
            'gst_inclusive' => true,
            'grace_period_days' => 0,
            'leftover_quota_carry_window_days' => null,
        ]);

        PlanTerm::query()->create([
            'plan_id' => $plan->id,
            'billing_key' => PlanTerm::BILLING_MONTHLY,
            'duration_days' => 30,
            'price' => 99,
            'discount_percent' => 0,
            'is_visible' => true,
            'sort_order' => PlanTerm::defaultSortOrder(PlanTerm::BILLING_MONTHLY),
        ]);

        foreach (\App\Support\PlanQuotaPolicyKeys::ordered() as $fk) {
            PlanQuotaPolicy::query()->create([
                'plan_id' => $plan->id,
                'feature_key' => $fk,
                'is_enabled' => false,
                'refresh_type' => \App\Models\PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST,
                'limit_value' => 0,
                'daily_sub_cap' => null,
                'per_day_usage_limit_enabled' => false,
                'overuse_mode' => \App\Models\PlanQuotaPolicy::OVERUSE_BLOCK,
                'pack_price_paise' => null,
                'pack_message_count' => null,
                'pack_validity_days' => null,
                'policy_meta' => null,
            ]);
        }

        $label = __('subscriptions.admin_plan_marketing_opt_best_seller');

        $this->get(route('plans.index'))
            ->assertOk()
            ->assertSee($label, false);
    }

    public function test_authenticated_user_can_view_plans(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = $this->createUserWithMatrimonyGender('male');

        $this->actingAs($user)
            ->get(route('plans.index'))
            ->assertOk()
            ->assertSee(__('subscriptions.pricing_cta_upgrade'));
    }

    public function test_subscribe_does_not_create_subscription_before_payu(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = $this->createUserWithMatrimonyGender('male');
        $paidPlan = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $term = PlanTerm::query()
            ->where('plan_id', $paidPlan->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->first();
        $this->assertNotNull($term);

        $this->actingAs($user)
            ->post(route('plans.subscribe'), [
                'plan' => $paidPlan->slug,
                'plan_term_id' => $term->id,
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
        $user = $this->createUserWithMatrimonyGender('male');
        $paidPlan = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $term = PlanTerm::query()
            ->where('plan_id', $paidPlan->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->first();
        $this->assertNotNull($term);

        $checkout = $this->actingAs($user)
            ->post(route('plans.subscribe'), [
                'plan' => $paidPlan->slug,
                'plan_term_id' => $term->id,
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
            (string) ($fields['udf1'] ?? ''),
            (string) ($fields['udf2'] ?? ''),
            (string) ($fields['udf3'] ?? ''),
            (string) ($fields['udf4'] ?? ''),
            (string) ($fields['udf5'] ?? ''),
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
            'udf1' => $fields['udf1'] ?? '',
            'udf2' => $fields['udf2'] ?? '',
            'udf3' => $fields['udf3'] ?? '',
            'udf4' => $fields['udf4'] ?? '',
            'udf5' => $fields['udf5'] ?? '',
        ])->assertRedirect(route('plans.index'));

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $paidPlan->id,
            'plan_term_id' => $term->id,
            'status' => 'active',
        ]);

        $payment = Payment::query()->where('txnid', $fields['txnid'])->first();
        $this->assertNotNull($payment);
        $this->assertSame((int) $paidPlan->id, (int) $payment->plan_id);
        $this->assertSame((int) $term->id, (int) $payment->plan_term_id);
        $this->assertSame((string) $term->billing_key, (string) $payment->billing_key);
        $this->assertSame('INR', (string) $payment->currency);
        $meta = $payment->meta;
        $this->assertIsArray($meta);
        $this->assertSame((string) $paidPlan->name, $meta['plan_name'] ?? null);
        $this->assertSame((string) $term->billing_key, $meta['billing_key'] ?? null);
        $this->assertSame((int) $term->duration_days, (int) ($meta['duration_days'] ?? 0));
        $this->assertEqualsWithDelta((float) $term->final_price, (float) ($meta['base_amount'] ?? 0), 0.02);
        $amountPaid = round((float) $payment->amount_paid, 2);
        $this->assertEqualsWithDelta($amountPaid, (float) ($meta['final_amount'] ?? 0), 0.02);
        $this->assertArrayHasKey('coupon_discount', $meta);
        $this->assertEqualsWithDelta(0.0, (float) ($meta['coupon_discount'] ?? -1), 0.02);
        $this->assertEqualsWithDelta($amountPaid, (float) ($meta['final_amount_after_coupon'] ?? 0), 0.02);
        $this->assertNull($meta['coupon_code'] ?? null);

        $sub = Subscription::query()->where('user_id', $user->id)->where('plan_id', $paidPlan->id)->where('status', 'active')->first();
        $this->assertNotNull($sub);
        $snap = $sub->meta['checkout_snapshot'] ?? null;
        $this->assertIsArray($snap);
        $this->assertSame((string) $paidPlan->name, $snap['plan_name'] ?? null);
        $this->assertSame($fields['txnid'], $snap['payu_txnid'] ?? null);
        $this->assertEqualsWithDelta($amountPaid, (float) ($snap['final_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($snap['coupon_discount'] ?? -1), 0.02);
        $this->assertEqualsWithDelta($amountPaid, (float) ($snap['final_amount_after_coupon'] ?? 0), 0.02);

        if (\Illuminate\Support\Facades\Schema::hasColumn('payments', 'payu_txnid')) {
            $this->assertSame($fields['txnid'], (string) $payment->payu_txnid);
        }
    }

    private static function seedMasterGendersForPlansTests(): void
    {
        MasterGender::query()->firstOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true],
        );
        MasterGender::query()->firstOrCreate(
            ['key' => 'female'],
            ['label' => 'Female', 'is_active' => true],
        );
    }

    private function createUserWithMatrimonyGender(string $genderKey): User
    {
        self::seedMasterGendersForPlansTests();
        $user = User::factory()->create();
        $genderId = MasterGender::query()->where('key', $genderKey)->where('is_active', true)->value('id');
        $this->assertNotNull($genderId);
        MatrimonyProfile::factory()->for($user)->create([
            'gender_id' => $genderId,
            'lifecycle_state' => 'active',
        ]);

        return $user->fresh();
    }
}
