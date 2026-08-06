<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
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
                'checkout_snapshot' => [
                    'plan_term_id' => $monthlyId,
                    'plan_name' => (string) $plan->name,
                    'billing_key' => PlanTerm::BILLING_MONTHLY,
                ],
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
            'meta' => null,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $term->delete();
    }
}
