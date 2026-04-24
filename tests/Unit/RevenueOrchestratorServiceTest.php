<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\User;
use App\Services\RevenueOrchestratorService;
use App\Services\SubscriptionService;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueOrchestratorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_checkout_delegates_resolution_to_subscription_service(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $plan = Plan::query()->where('slug', 'silver_male')->firstOrFail();
        $termId = (int) $plan->terms()->where('is_visible', true)->value('id');

        $direct = app(SubscriptionService::class)->resolvePaidPlanCheckout($user, $plan, $termId, null, null);
        $prepared = app(RevenueOrchestratorService::class)->prepareCheckout($user, $plan, $termId, null, null);

        $this->assertSame($direct['final_amount'], $prepared['resolved']['final_amount']);
        $this->assertSame($direct['base_amount'], $prepared['resolved']['base_amount']);
        $this->assertFalse($prepared['orchestration']['wallet']['applies_to_plan_checkout']);
        $this->assertSame('none', $prepared['orchestration']['bonus_stacking']['mode']);
    }
}
