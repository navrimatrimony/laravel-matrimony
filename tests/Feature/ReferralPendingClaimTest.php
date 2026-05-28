<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\EntitlementService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralPendingClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.rewards_by_plan_slug' => [
                'gold_test' => 5,
            ],
        ]);
    }

    private function seedPaidPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Test Gold',
            'slug' => 'gold_test',
            'price' => 999,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_reward_queues_when_referrer_has_no_active_plan(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'REFPEND01']);
        $buyer = User::factory()->create();
        $plan = $this->seedPaidPlan();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
        ]);

        app(ReferralService::class)->applyPurchaseRewardIfEligible($buyer, $plan);

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertNotNull($row);
        $this->assertFalse($row->reward_applied);
        $this->assertSame(UserReferral::STATUS_PENDING_CLAIM, $row->reward_status);
        $this->assertSame((int) $plan->id, (int) $row->pending_plan_id);
        $this->assertIsArray($row->pending_reward);
    }

    public function test_pending_reward_claims_when_referrer_subscribes(): void
    {
        $this->mock(EntitlementService::class, function ($mock): void {
            $mock->shouldReceive('resyncFromActiveSubscription')->andReturnNull();
        });

        $referrer = User::factory()->create(['referral_code' => 'REFPEND02']);
        $buyer = User::factory()->create();
        $plan = $this->seedPaidPlan();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'reward_status' => UserReferral::STATUS_PENDING_CLAIM,
            'pending_plan_id' => $plan->id,
            'pending_reward' => [
                'bonus_days' => 3,
                'feature_bonus' => [],
                'plan_slug' => (string) $plan->slug,
                'plan_name' => (string) $plan->name,
            ],
        ]);

        $endsAt = now()->addDays(30);
        Subscription::withoutEvents(function () use ($referrer, $plan, $endsAt): void {
            Subscription::query()->create([
                'user_id' => $referrer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => $endsAt,
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        app(ReferralService::class)->claimPendingReferralRewards($referrer);

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertTrue($row->reward_applied);
        $this->assertSame(UserReferral::STATUS_APPLIED, $row->reward_status);
        $this->assertNull($row->pending_plan_id);

        $sub = Subscription::query()->where('user_id', $referrer->id)->first();
        $this->assertTrue($sub->ends_at->greaterThan($endsAt));
    }

    public function test_count_pending_claims_for_referrer(): void
    {
        $referrer = User::factory()->create();
        $buyer = User::factory()->create();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'reward_status' => UserReferral::STATUS_PENDING_CLAIM,
        ]);

        $this->assertSame(1, app(ReferralService::class)->countPendingClaimsForReferrer($referrer));
    }
}
