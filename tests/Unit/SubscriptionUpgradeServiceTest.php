<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionUpgradeService;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SubscriptionUpgradeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_now_rejects_without_active_paid_subscription(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $gold = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $termId = (int) $gold->terms()->where('is_visible', true)->value('id');

        $this->expectException(HttpException::class);
        app(SubscriptionUpgradeService::class)->upgradeNow($user, $gold, $termId, null, null);
    }

    public function test_upgrade_now_returns_merged_carry_preview_from_quota_engine(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $silver = Plan::query()->where('slug', 'silver_male')->firstOrFail();
        $gold = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $silverTerm = $silver->terms()->where('is_visible', true)->firstOrFail();
        $goldTerm = $gold->terms()->where('is_visible', true)->firstOrFail();

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $silver->id,
            'plan_term_id' => $silverTerm->id,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'status' => Subscription::STATUS_ACTIVE,
            'meta' => [
                'checkout_snapshot' => [
                    'plan_name' => $silver->name,
                    'billing_key' => $silverTerm->billing_key,
                    'plan_term_id' => $silverTerm->id,
                    'base_amount' => 1000.00,
                    'final_amount' => 1000.00,
                    'coupon_discount' => 0.0,
                ],
            ],
        ]);

        $out = app(SubscriptionUpgradeService::class)->upgradeNow($user, $gold, (int) $goldTerm->id, null, null);

        $this->assertSame(SubscriptionUpgradeService::TYPE_IMMEDIATE_UPGRADE, $out['type']);
        $this->assertArrayHasKey('carry_quota_from_engine', $out);
        $this->assertArrayHasKey('carry_quota_merged_preview', $out);
        $this->assertSame($out['carry_quota_from_engine'], $out['carry_quota_merged_preview']);
        $this->assertArrayHasKey('prepared_checkout', $out);
        $this->assertNotNull($out['time_remaining_preview']['fraction_of_period_remaining']);
        $this->assertGreaterThan(0.0, $out['time_remaining_preview']['fraction_of_period_remaining']);
    }

    public function test_schedule_downgrade_stub_is_unsupported(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $user = User::factory()->create();
        $plan = Plan::query()->firstOrFail();

        $out = app(SubscriptionUpgradeService::class)->scheduleDowngradeForNextCycle($user, $plan, null);

        $this->assertFalse($out['supported']);
        $this->assertSame(SubscriptionUpgradeService::TYPE_SCHEDULED_DOWNGRADE_NEXT_CYCLE, $out['type']);
    }
}
