<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralPendingExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['referral.enabled' => true]);
    }

    public function test_stale_pending_claim_expires_after_configured_days(): void
    {
        \App\Models\AdminSetting::query()->updateOrCreate(
            ['key' => 'referral_pending_claim_expiry_days'],
            ['value' => '30'],
        );

        $referrer = User::factory()->create();
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_expiry',
            'price' => 999,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'reward_status' => UserReferral::STATUS_PENDING_CLAIM,
            'pending_plan_id' => $plan->id,
            'pending_reward' => ['bonus_days' => 5, 'feature_bonus' => []],
            'pending_claim_at' => now()->subDays(31),
        ]);

        $expired = app(ReferralService::class)->expireStalePendingClaims($referrer);
        $this->assertSame(1, $expired);

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertSame(UserReferral::STATUS_PENDING_EXPIRED, $row->reward_status);
        $this->assertNull($row->pending_plan_id);
        $this->assertSame(0, app(ReferralService::class)->countPendingClaimsForReferrer($referrer));
    }

    public function test_zero_expiry_days_never_expires(): void
    {
        \App\Models\AdminSetting::query()->updateOrCreate(
            ['key' => 'referral_pending_claim_expiry_days'],
            ['value' => '0'],
        );

        $referrer = User::factory()->create();
        $buyer = User::factory()->create();

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'reward_status' => UserReferral::STATUS_PENDING_CLAIM,
            'pending_claim_at' => now()->subDays(400),
        ]);

        $this->assertSame(0, app(ReferralService::class)->expireStalePendingClaims($referrer));
        $this->assertSame(UserReferral::STATUS_PENDING_CLAIM, UserReferral::query()->first()->reward_status);
    }
}
