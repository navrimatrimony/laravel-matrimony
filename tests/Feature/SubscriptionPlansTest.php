<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_plans(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $this->get(route('plans.index'))->assertRedirect();
    }

    public function test_authenticated_user_can_view_plans(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('plans.index'))
            ->assertOk();
    }

    public function test_subscribe_creates_active_subscription(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $gold = Plan::query()->where('slug', 'gold')->firstOrFail();

        $this->actingAs($user)
            ->post(route('plans.subscribe', $gold))
            ->assertRedirect(route('plans.index'));

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'plan_id' => $gold->id,
            'status' => 'active',
        ]);
    }
}
