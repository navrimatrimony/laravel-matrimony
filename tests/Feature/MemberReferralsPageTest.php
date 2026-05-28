<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberReferralsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['referral.enabled' => true]);
    }

    public function test_referrals_page_requires_auth(): void
    {
        $this->get(route('referrals.index'))->assertRedirect();
    }

    public function test_referrals_page_shows_summary_and_entries(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'REFLIST01']);
        $referred = User::factory()->create(['name' => 'Priya Sharma']);

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'reward_applied' => false,
            'reward_status' => UserReferral::STATUS_PENDING_CLAIM,
            'pending_plan_id' => Plan::query()->create([
                'name' => 'Gold',
                'slug' => 'gold_list',
                'price' => 500,
                'is_active' => true,
                'sort_order' => 1,
            ])->id,
            'pending_reward' => ['bonus_days' => 5, 'feature_bonus' => []],
        ]);

        $this->actingAs($referrer)
            ->get(route('referrals.index'))
            ->assertOk()
            ->assertSee(__('referrals.title'), false)
            ->assertSee(__('referrals.stat_invited'), false)
            ->assertSee('1', false)
            ->assertSee(__('referrals.stage_pending_claim'), false)
            ->assertSee(__('referrals.rules_heading'), false);
    }
}
