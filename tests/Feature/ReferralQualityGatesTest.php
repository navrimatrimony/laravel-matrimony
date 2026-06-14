<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\EntitlementService;
use App\Services\ReferralService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralQualityGatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);

        config([
            'referral.enabled' => true,
            'referral.rewards_by_plan_slug' => ['gold_quality' => 4],
            'referral.quality_gates.require_profile_active' => true,
        ]);

        \App\Models\AdminSetting::query()->updateOrCreate(
            ['key' => 'referral_quality_require_profile_active'],
            ['value' => '1'],
        );
    }

    public function test_purchase_holds_reward_when_profile_not_active(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'QUALITY01']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_quality',
            'price' => 999,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Subscription::withoutEvents(function () use ($referrer, $plan): void {
            Subscription::query()->create([
                'user_id' => $referrer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        MatrimonyProfile::query()->create([
            'user_id' => $buyer->id,
            'full_name' => 'Buyer Test',
            'lifecycle_state' => 'draft',
            'photo_approved' => false,
            'is_suspended' => false,
        ]);

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        app(ReferralService::class)->applyPurchaseRewardIfEligible($buyer, $plan);

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertFalse($row->reward_applied);
        $this->assertSame(UserReferral::STATUS_QUALITY_PENDING, $row->reward_status);
        $this->assertSame((int) $plan->id, (int) $row->pending_plan_id);
    }

    public function test_reward_releases_when_profile_becomes_active(): void
    {
        $this->mock(EntitlementService::class, function ($mock): void {
            $mock->shouldReceive('resyncFromActiveSubscription')->andReturnNull();
        });

        $referrer = User::factory()->create(['referral_code' => 'QUALITY02']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_quality',
            'price' => 999,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Subscription::withoutEvents(function () use ($referrer, $plan): void {
            Subscription::query()->create([
                'user_id' => $referrer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        $profile = MatrimonyProfile::query()->create([
            'user_id' => $buyer->id,
            'full_name' => 'Buyer Two',
            'lifecycle_state' => 'draft',
            'photo_approved' => false,
            'is_suspended' => false,
        ]);

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'reward_status' => UserReferral::STATUS_QUALITY_PENDING,
            'pending_plan_id' => $plan->id,
            'pending_reward' => ['bonus_days' => 4, 'feature_bonus' => []],
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        $locationId = $this->createTestResidenceLocationId();

        $profile->forceFill([
            'lifecycle_state' => 'active',
            'location_id' => $locationId,
        ])->save();

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertTrue($row->reward_applied);
        $this->assertSame(UserReferral::STATUS_APPLIED, $row->reward_status);
    }

    private function createTestResidenceLocationId(): int
    {
        $puneCity = City::query()->where('name', 'Pune City')->firstOrFail();

        return (int) Location::query()->create([
            'name' => 'Quality Gate Test Suburb',
            'slug' => 'quality-gate-suburb-'.uniqid(),
            'hierarchy' => 'village',
            'tag' => 'suburban',
            'parent_id' => $puneCity->parent_id,
            'is_active' => true,
        ])->id;
    }
}
