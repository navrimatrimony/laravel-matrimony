<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_plans_catalog(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $this->get(route('plans.index'))
            ->assertOk()
            ->assertSee(__('subscriptions.pricing_page_title'))
            ->assertSee(__('subscriptions.pricing_most_popular'));
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

    public function test_subscribe_creates_active_subscription(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $gold = Plan::query()->where('slug', 'gold')->firstOrFail();
        $price = PlanPrice::query()
            ->where('plan_id', $gold->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->first();
        $this->assertNotNull($price);

        $this->actingAs($user)
            ->post(route('plans.subscribe', $gold), ['plan_price_id' => $price->id])
            ->assertRedirect(route('plans.index'));

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $gold->id,
            'plan_price_id' => $price->id,
            'status' => 'active',
        ]);
    }
}
