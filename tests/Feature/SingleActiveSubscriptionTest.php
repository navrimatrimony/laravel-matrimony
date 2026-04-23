<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanTerm;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SingleActiveSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_a_second_active_subscription_cancels_the_first(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $basic = Plan::query()->where('slug', 'basic_male')->firstOrFail();
        $silver = Plan::query()->where('slug', 'silver_male')->firstOrFail();

        $first = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $basic->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $silver->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertSame(Subscription::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertSame(1, Subscription::query()->where('user_id', $user->id)->where('status', Subscription::STATUS_ACTIVE)->count());
    }

    #[Test]
    public function subscribe_service_leaves_only_one_active_row(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $basic = Plan::query()->where('slug', 'basic_male')->firstOrFail();
        $silver = Plan::query()->where('slug', 'silver_male')->firstOrFail();
        $term = PlanTerm::query()
            ->where('plan_id', $silver->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->firstOrFail();

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $basic->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        app(SubscriptionService::class)->subscribe($user, $silver, (int) $term->id, null);

        $this->assertSame(1, Subscription::query()->where('user_id', $user->id)->where('status', Subscription::STATUS_ACTIVE)->count());
        $this->assertSame($silver->id, Subscription::query()->where('user_id', $user->id)->where('status', Subscription::STATUS_ACTIVE)->value('plan_id'));
    }
}
