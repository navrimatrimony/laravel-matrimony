<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanQuotaCheckoutSnapshot;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanTermSubscriptionReferenceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_admin_term_rows_upserts_in_place_when_subscription_references_term(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $plan = Plan::query()->where('slug', 'silver_male')->firstOrFail();
        $monthly = PlanTerm::query()
            ->where('plan_id', $plan->id)
            ->where('billing_key', PlanTerm::BILLING_MONTHLY)
            ->firstOrFail();
        $quarterly = PlanTerm::query()
            ->where('plan_id', $plan->id)
            ->where('billing_key', PlanTerm::BILLING_QUARTERLY)
            ->firstOrFail();
        $monthlyId = (int) $monthly->id;
        $quarterlyId = (int) $quarterly->id;

        $user = User::factory()->create();

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $monthlyId,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'meta' => [
                'checkout_snapshot' => array_merge(
                    PlanQuotaCheckoutSnapshot::forPlan($plan),
                    [
                        'plan_term_id' => $monthlyId,
                        'plan_name' => (string) $plan->name,
                        'billing_key' => PlanTerm::BILLING_MONTHLY,
                    ],
                ),
            ],
        ]);

        PlanTerm::syncAdminTermRows($plan->fresh(), [
            [
                'billing_key' => PlanTerm::BILLING_MONTHLY,
                'price' => 999.0,
                'selling_price' => 799.0,
                'quota_bonus_percent' => 10,
                'is_visible' => true,
            ],
        ]);

        $monthlyFresh = PlanTerm::query()->findOrFail($monthlyId);
        $this->assertSame($monthlyId, (int) $monthlyFresh->id);
        $this->assertSame(PlanTerm::BILLING_MONTHLY, (string) $monthlyFresh->billing_key);
        $this->assertSame(999.0, (float) $monthlyFresh->price);
        $this->assertSame(799.0, (float) $monthlyFresh->selling_price);
        $this->assertSame(10, (int) $monthlyFresh->quota_bonus_percent);
        $this->assertTrue((bool) $monthlyFresh->is_visible);

        $quarterlyFresh = PlanTerm::query()->findOrFail($quarterlyId);
        $this->assertSame($quarterlyId, (int) $quarterlyFresh->id);
        $this->assertFalse((bool) $quarterlyFresh->is_visible);

        $this->assertTrue(
            Subscription::query()
                ->where('plan_term_id', $monthlyId)
                ->where('meta->checkout_snapshot->plan_term_id', $monthlyId)
                ->exists()
        );
    }

    public function test_sync_admin_term_rows_does_not_invent_five_yearly_or_lifetime(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $plan = Plan::query()->where('slug', 'silver_female')->firstOrFail();

        PlanTerm::query()->create([
            'plan_id' => $plan->id,
            'billing_key' => PlanTerm::BILLING_FIVE_YEARLY,
            'duration_days' => 1825,
            'price' => 0,
            'selling_price' => 0,
            'is_visible' => true,
            'sort_order' => 50,
        ]);

        PlanTerm::syncAdminTermRows($plan->fresh(), [
            [
                'billing_key' => PlanTerm::BILLING_MONTHLY,
                'price' => 100.0,
                'selling_price' => 90.0,
                'is_visible' => true,
            ],
            [
                'billing_key' => PlanTerm::BILLING_QUARTERLY,
                'price' => 250.0,
                'selling_price' => 200.0,
                'is_visible' => true,
            ],
            [
                'billing_key' => PlanTerm::BILLING_HALF_YEARLY,
                'price' => 450.0,
                'selling_price' => 350.0,
                'is_visible' => true,
            ],
            [
                'billing_key' => PlanTerm::BILLING_YEARLY,
                'price' => 800.0,
                'selling_price' => 600.0,
                'is_visible' => true,
            ],
            // Tamper attempt — must be ignored (not upserted as a new product period).
            [
                'billing_key' => PlanTerm::BILLING_LIFETIME,
                'price' => 9999.0,
                'selling_price' => 9999.0,
                'is_visible' => true,
            ],
        ]);

        $keys = PlanTerm::query()
            ->where('plan_id', $plan->id)
            ->where('is_visible', true)
            ->pluck('billing_key')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['half_yearly', 'monthly', 'quarterly', 'yearly'],
            $keys
        );

        $five = PlanTerm::query()
            ->where('plan_id', $plan->id)
            ->where('billing_key', PlanTerm::BILLING_FIVE_YEARLY)
            ->first();
        $this->assertNotNull($five);
        $this->assertFalse((bool) $five->is_visible);

        $this->assertFalse(
            PlanTerm::query()
                ->where('plan_id', $plan->id)
                ->where('billing_key', PlanTerm::BILLING_LIFETIME)
                ->exists()
        );
    }

    public function test_product_billing_keys_are_one_three_six_twelve_months_only(): void
    {
        $this->assertSame(
            [
                PlanTerm::BILLING_MONTHLY,
                PlanTerm::BILLING_QUARTERLY,
                PlanTerm::BILLING_HALF_YEARLY,
                PlanTerm::BILLING_YEARLY,
            ],
            PlanTerm::productBillingKeys()
        );
        $this->assertSame(30, PlanTerm::durationDaysFor(PlanTerm::BILLING_MONTHLY));
        $this->assertSame(90, PlanTerm::durationDaysFor(PlanTerm::BILLING_QUARTERLY));
        $this->assertSame(180, PlanTerm::durationDaysFor(PlanTerm::BILLING_HALF_YEARLY));
        $this->assertSame(365, PlanTerm::durationDaysFor(PlanTerm::BILLING_YEARLY));
    }

    public function test_deleting_a_referenced_term_is_still_blocked(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $plan = Plan::query()->where('slug', 'silver_male')->firstOrFail();
        $term = PlanTerm::query()
            ->where('plan_id', $plan->id)
            ->where('billing_key', PlanTerm::BILLING_MONTHLY)
            ->firstOrFail();
        $user = User::factory()->create();

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $term->id,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'meta' => [
                'checkout_snapshot' => array_merge(
                    PlanQuotaCheckoutSnapshot::forPlan($plan),
                    [
                        'plan_term_id' => (int) $term->id,
                        'billing_key' => PlanTerm::BILLING_MONTHLY,
                    ],
                ),
            ],
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $term->delete();
    }
}
