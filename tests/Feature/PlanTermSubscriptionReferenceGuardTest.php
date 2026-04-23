<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlanTermSubscriptionReferenceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_admin_term_rows_throws_when_active_subscription_references_existing_term(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $plan = Plan::query()->where('slug', 'silver_male')->firstOrFail();
        $term = PlanTerm::query()->where('plan_id', $plan->id)->orderBy('sort_order')->firstOrFail();
        $user = User::factory()->create();

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_term_id' => $term->id,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'meta' => [
                'checkout_snapshot' => [
                    'plan_term_id' => (int) $term->id,
                    'plan_name' => (string) $plan->name,
                    'billing_key' => (string) $term->billing_key,
                ],
            ],
        ]);

        $newRows = [
            ['billing_key' => PlanTerm::BILLING_MONTHLY, 'price' => 100.0, 'discount_percent' => null, 'is_visible' => true],
            ['billing_key' => PlanTerm::BILLING_QUARTERLY, 'price' => 270.0, 'discount_percent' => null, 'is_visible' => false],
        ];

        try {
            PlanTerm::syncAdminTermRows($plan->fresh(), $newRows);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors()['plan_terms'] ?? []);
        }
    }
}
