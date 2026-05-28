<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\ReferralRewardLedger;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralMemberFunnelPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['referral.enabled' => true]);
    }

    public function test_upgraded_stage_when_invitee_has_paid_plan(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'FUNNEL01']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_funnel',
            'price' => 999,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Subscription::withoutEvents(function () use ($buyer, $plan): void {
            Subscription::query()->create([
                'user_id' => $buyer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [],
            ]);
        });

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_APPROVED,
            'pending_reward' => [
                'bonus_days' => 5,
                'feature_bonus' => ['chat_send_limit' => 3, 'contact_view_limit' => 1],
            ],
        ]);

        $entries = app(ReferralService::class)->listEntriesForReferrer($referrer);

        $this->assertSame('upgraded', $entries[0]['stage']);
        $this->assertStringContainsString('chat', strtolower((string) $entries[0]['quota_hint']));
    }

    public function test_referrals_nav_link_visible_when_engine_enabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('referrals.index'), false)
            ->assertSee(__('nav.my_referrals'), false);
    }

    public function test_reward_earned_shows_breakdown_from_ledger(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'FUNNEL03']);
        $buyer = User::factory()->create();

        $referral = UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => true,
            'reward_status' => UserReferral::STATUS_APPLIED,
            'review_status' => UserReferral::REVIEW_APPROVED,
            'pending_reward' => null,
        ]);

        ReferralRewardLedger::query()->create([
            'user_referral_id' => $referral->id,
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'action_type' => 'auto_applied',
            'bonus_days' => 10,
            'feature_bonus' => [
                'chat_send_limit' => 10,
                'contact_view_limit' => 1,
            ],
            'reason' => 'Test reward',
            'meta' => ['plan_name' => 'Starter Female'],
        ]);

        $entries = app(ReferralService::class)->listEntriesForReferrer($referrer);

        $this->assertSame('reward_earned', $entries[0]['stage']);
        $this->assertNotEmpty($entries[0]['reward_detail_lines']);
        $this->assertStringContainsString('10', implode(' ', $entries[0]['reward_detail_lines']));
        $this->assertSame('Starter Female', $entries[0]['reward_plan_name']);

        $this->actingAs($referrer)
            ->get(route('referrals.index'))
            ->assertOk()
            ->assertSee(__('referrals.reward_received_heading'), false)
            ->assertSee('Starter Female', false)
            ->assertSee(__('referrals.quota_label_chat'), false);
    }

    public function test_referrals_page_shows_upgraded_stage_label(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'FUNNEL02']);
        $buyer = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Silver',
            'slug' => 'silver_funnel',
            'price' => 499,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Subscription::withoutEvents(function () use ($buyer, $plan): void {
            Subscription::query()->create([
                'user_id' => $buyer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
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

        $this->actingAs($referrer)
            ->get(route('referrals.index'))
            ->assertOk()
            ->assertSee(__('referrals.stage_upgraded'), false);
    }
}
