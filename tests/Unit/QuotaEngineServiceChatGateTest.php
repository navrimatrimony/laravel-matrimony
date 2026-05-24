<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\User;
use App\Services\QuotaEngineService;
use App\Services\SubscriptionService;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: {@see QuotaEngineService::canUseFeature} for chat must not call
 * {@see \App\Services\FeatureUsageService::canUse} (which re-enters the quota engine and recurses until timeout).
 */
class QuotaEngineServiceChatGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_access_feature_for_chat_send_limit_resolves_without_recursion(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create();
        $plan = Plan::query()->where('slug', 'free_male')->firstOrFail();
        app(SubscriptionService::class)->subscribe($user, $plan, null, null);

        $engine = app(QuotaEngineService::class);

        $this->assertIsBool($engine->canAccessFeature($user, 'chat_send_limit', []));
        $this->assertIsBool($engine->canAccessFeature($user, 'chat', [
            'conversation_id' => 1,
            'sender_profile_id' => 1,
        ]));
        $this->assertIsBool($engine->canUseFeature($user, 'chat_send', []));
    }
}
