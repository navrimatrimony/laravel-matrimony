<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\EntitlementService;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralGrowthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'referral.enabled' => true,
            'referral.growth.renewal_micro_bonus' => [
                'enabled' => true,
                'bonus_days' => 2,
            ],
        ]);
    }

    public function test_registration_stores_utm_attribution_from_share_link(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'UTMGROW1']);

        $this->post(route('register'), [
            'name' => 'UTM Invitee',
            'mobile' => '9876543211',
            'password' => 'password',
            'password_confirmation' => 'password',
            'registering_for' => 'self',
            'invite_code' => 'UTMGROW1',
            'utm_source' => 'member_referral',
            'utm_medium' => 'whatsapp',
            'utm_campaign' => 'invite',
            'utm_content' => 'whatsapp',
        ])->assertRedirect();

        $buyer = User::query()->where('mobile', '9876543211')->first();
        $this->assertNotNull($buyer);

        $this->assertDatabaseHas('user_referrals', [
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'utm_source' => 'member_referral',
            'utm_medium' => 'whatsapp',
            'utm_campaign' => 'invite',
            'utm_content' => 'whatsapp',
        ]);
    }

    public function test_referral_register_url_includes_channel_utm_tags(): void
    {
        $url = app(ReferralService::class)->referralRegisterUrl('grow01', 'whatsapp');

        $this->assertStringContainsString('ref=GROW01', $url);
        $this->assertStringContainsString('utm_medium=whatsapp', $url);
        $this->assertStringContainsString('utm_content=whatsapp', $url);
        $this->assertStringContainsString('utm_source=member_referral', $url);
    }

    public function test_renewal_micro_bonus_extends_referrer_once_on_second_paid_purchase(): void
    {
        $this->mock(EntitlementService::class, function ($mock): void {
            $mock->shouldReceive('resyncFromActiveSubscription')->andReturnNull();
        });

        AdminSetting::setValue('referral_renewal_micro_bonus_enabled', '1');
        AdminSetting::setValue('referral_renewal_micro_bonus_days', '2');

        config(['referral.rewards_by_plan_slug' => ['gold_renewal_micro' => 5]]);

        $referrer = User::factory()->create(['referral_code' => 'RENEW01']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_renewal_micro',
            'price' => 999,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $endsAt = now()->addDays(10);
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

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => true,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        app(ReferralService::class)->applyPurchaseRewardIfEligible($buyer, $plan);

        $sub = Subscription::query()->where('user_id', $referrer->id)->first();
        $this->assertTrue($sub->ends_at->greaterThan($endsAt));

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertNotNull($row->renewal_micro_bonus_applied_at);

        $this->assertDatabaseHas('referral_reward_ledgers', [
            'user_referral_id' => $row->id,
            'action_type' => 'renewal_micro_applied',
            'bonus_days' => 2,
        ]);

        $beforeSecond = $sub->ends_at;
        app(ReferralService::class)->applyPurchaseRewardIfEligible($buyer, $plan);
        $sub->refresh();
        $this->assertTrue($sub->ends_at->equalTo($beforeSecond));
    }

    public function test_renewal_micro_bonus_skipped_on_first_paid_purchase_only(): void
    {
        $this->mock(EntitlementService::class, function ($mock): void {
            $mock->shouldReceive('resyncFromActiveSubscription')->andReturnNull();
        });

        AdminSetting::setValue('referral_renewal_micro_bonus_enabled', '1');
        AdminSetting::setValue('referral_renewal_micro_bonus_days', '2');

        config(['referral.rewards_by_plan_slug' => ['gold_renewal_skip' => 4]]);

        $referrer = User::factory()->create(['referral_code' => 'RENEW02']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_renewal_skip',
            'price' => 999,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Subscription::withoutEvents(function () use ($referrer, $plan): void {
            Subscription::query()->create([
                'user_id' => $referrer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(10),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        app(ReferralService::class)->applyPurchaseRewardIfEligible($buyer, $plan);

        $row = UserReferral::query()->where('referred_user_id', $buyer->id)->first();
        $this->assertTrue($row->reward_applied);
        $this->assertNull($row->renewal_micro_bonus_applied_at);
        $this->assertDatabaseMissing('referral_reward_ledgers', [
            'user_referral_id' => $row->id,
            'action_type' => 'renewal_micro_applied',
        ]);
    }
}
