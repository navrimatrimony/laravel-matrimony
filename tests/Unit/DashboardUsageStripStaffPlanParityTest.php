<?php

namespace Tests\Unit;

use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\FeatureUsageService;
use App\Support\PlanFeatureKeys;
use Database\Seeders\PlanStandardFeatureKeysSeeder;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardUsageStripStaffPlanParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Staff accounts used to get unlimited (-1) from {@see SubscriptionService::getFeatureLimit} for every
     * quota; the compact usage strip must still show the subscribed plan so it matches /plans cards.
     */
    public function test_usage_strip_shows_plan_limits_for_super_admin_not_infinity(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
        MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

        $basic = Plan::query()->where('slug', 'basic')->firstOrFail();
        $sub = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $basic->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        app(EntitlementService::class)->assignFromSubscription($sub->fresh());

        $summary = app(FeatureUsageService::class)->getDashboardUsageSummary($user->fresh());
        $this->assertNotNull($summary);
        $this->assertFalse($summary['bypass'] ?? true);

        $rows = collect($summary['rows'])->keyBy('key');

        $this->assertSame(25, $rows['chat_sends']['limit']);
        $this->assertSame(200, $rows['profile_opens']['limit']);
        $this->assertSame(15, $rows['interest_sends']['limit']);

        $this->assertTrue($rows['contact_reveals']['is_unlimited']);
        $this->assertNull($rows['contact_reveals']['limit']);
    }

    public function test_mediator_strip_matches_plan_for_staff_when_plan_defines_mediator(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);
        $this->seed(PlanStandardFeatureKeysSeeder::class);

        $user = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
        MatrimonyProfile::factory()->for($user)->create(['lifecycle_state' => 'active']);

        $gold = Plan::query()->where('slug', 'gold')->firstOrFail();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $gold->id,
            'plan_term_id' => null,
            'plan_price_id' => null,
            'coupon_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        app(EntitlementService::class)->assignFromSubscription(
            Subscription::query()->where('user_id', $user->id)->latest('id')->first()
        );

        $summary = app(FeatureUsageService::class)->getDashboardUsageSummary($user->fresh());
        $rows = collect($summary['rows'])->keyBy('key');

        $this->assertSame(
            app(\App\Services\SubscriptionService::class)->getQuotaLimitForUsageDisplay($user->fresh(), PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH),
            $rows['mediator_requests']['limit']
        );
        $this->assertFalse($rows['mediator_requests']['is_unlimited']);
    }
}
