<?php

namespace Tests\Feature;

use App\Models\MatchBoostSetting;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\MatchBoostService;
use App\Services\SubscriptionService;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MatchBoostServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_score_never_exceeds_100_and_respects_max_boost(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $settings = MatchBoostSetting::current();
        $settings->update([
            'use_ai' => false,
            'ai_provider' => null,
            'boost_active_weight' => 0,
            'boost_premium_weight' => 2,
            'boost_similarity_weight' => 0,
            'max_boost_limit' => 5,
            'boost_gold_extra' => 100,
            'boost_silver_extra' => 0,
            'active_within_days' => 7,
        ]);
        Cache::flush();

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        MatrimonyProfile::factory()->for($userA)->create();
        MatrimonyProfile::factory()->for($userB)->create();

        $gold = Plan::query()->where('slug', 'gold')->firstOrFail();
        $price = PlanPrice::query()
            ->where('plan_id', $gold->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->firstOrFail();
        app(SubscriptionService::class)->subscribe($userB, $gold, null, $price->id);

        $svc = app(MatchBoostService::class);
        // Rule tier: premium_weight + gold_extra = 102, capped by max_boost_limit 5 → +5; 95+5=100
        $this->assertSame(100, $svc->applyBoost($userA, $userB, 95));

        $settings->update(['max_boost_limit' => 0]);
        Cache::flush();
        $this->assertSame(50, $svc->applyBoost($userA, $userB, 50));
    }
}
