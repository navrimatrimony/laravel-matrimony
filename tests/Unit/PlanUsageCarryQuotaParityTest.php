<?php

namespace Tests\Unit;

use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserFeatureUsage;
use App\Services\EntitlementService;
use App\Services\FeatureUsageService;
use App\Services\InterestSendLimitService;
use App\Services\UserFeatureUsageService;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanUsageCarryQuotaParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_interest_send_limit_includes_carry_and_dashboard_row_matches(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create(['is_admin' => false]);
        MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

        $basic = Plan::query()->where('slug', 'basic_male')->firstOrFail();
        $sub = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $basic->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'meta' => [
                'carry_quota' => [
                    PlanFeatureKeys::INTEREST_SEND_LIMIT => 3,
                ],
            ],
        ]);

        app(EntitlementService::class)->assignFromSubscription($sub->fresh());

        $interestSvc = app(InterestSendLimitService::class);
        $this->assertSame(18, $interestSvc->effectiveDailyLimit($user->fresh()));

        app(UserFeatureUsageService::class)->incrementUsage(
            (int) $user->id,
            UserFeatureUsageKeys::INTEREST_SEND_LIMIT,
            5,
            UserFeatureUsage::PERIOD_DAILY,
        );

        $summary = app(FeatureUsageService::class)->getDashboardUsageSummary($user->fresh());
        $this->assertNotNull($summary);
        $row = collect($summary['rows'])->firstWhere('key', 'interest_sends');
        $this->assertNotNull($row);
        $this->assertSame(18, $row['limit']);
        $this->assertSame(5, $row['used']);
        $this->assertSame(13, $row['remaining']);
    }

    public function test_mediator_monthly_limit_includes_carry_and_dashboard_matches(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create(['is_admin' => false]);
        MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

        $gold = Plan::query()->where('slug', 'gold_male')->firstOrFail();
        $sub = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $gold->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
            'meta' => [
                'carry_quota' => [
                    PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => 2,
                ],
            ],
        ]);

        app(EntitlementService::class)->assignFromSubscription($sub->fresh());

        $featureUsage = app(FeatureUsageService::class);
        $this->assertSame(17, $featureUsage->getEffectiveMediatorMonthlyLimit($user->fresh()));

        app(UserFeatureUsageService::class)->incrementUsage(
            (int) $user->id,
            UserFeatureUsageKeys::MEDIATOR_REQUEST,
            2,
            UserFeatureUsage::PERIOD_MONTHLY,
        );

        $summary = $featureUsage->getDashboardUsageSummary($user->fresh());
        $this->assertNotNull($summary);
        $row = collect($summary['rows'])->firstWhere('key', 'mediator_requests');
        $this->assertNotNull($row);
        $this->assertSame(17, $row['limit']);
        $this->assertSame(2, $row['used']);
        $this->assertSame(15, $row['remaining']);
    }
}
