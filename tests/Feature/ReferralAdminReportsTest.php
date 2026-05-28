<?php

namespace Tests\Feature;

use App\Models\MatrimonyProfile;
use App\Models\Plan;
use App\Models\ReferralRewardLedger;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralAdminReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reports_bundle_counts_funnel_stages(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'RPTFUN01']);
        $readyBuyer = User::factory()->create();
        $pendingBuyer = User::factory()->create();

        MatrimonyProfile::withoutEvents(function () use ($readyBuyer): void {
            MatrimonyProfile::factory()->create([
                'user_id' => $readyBuyer->id,
                'lifecycle_state' => 'active',
                'is_suspended' => false,
            ]);
        });

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $readyBuyer->id,
            'reward_applied' => true,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $pendingBuyer->id,
            'reward_applied' => false,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        $bundle = app(ReferralService::class)->adminReportsBundle();

        $this->assertSame(2, $bundle['summary']['total']);
        $this->assertSame(1, $bundle['summary']['profile_ready']);
        $this->assertSame(1, $bundle['summary']['rewarded']);
        $this->assertSame(1, $bundle['summary']['pending']);
        $this->assertSame(50.0, $bundle['summary']['conversion_rate']);
        $this->assertSame(2, $bundle['funnel']['invited']);
        $this->assertSame(50.0, $bundle['funnel']['rates']['profile_ready']);
        $this->assertSame(50.0, $bundle['funnel']['rates']['rewarded']);
    }

    public function test_admin_reports_economics_sums_revenue_discount_and_referrer_cost(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'RPTECO01']);
        $buyer = User::factory()->create();

        $plan = Plan::query()->create([
            'name' => 'Gold',
            'slug' => 'gold_reports_eco',
            'price' => 3000,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        UserReferral::query()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'reward_applied' => true,
            'review_status' => UserReferral::REVIEW_APPROVED,
        ]);

        Subscription::withoutEvents(function () use ($buyer, $plan): void {
            Subscription::query()->create([
                'user_id' => $buyer->id,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => [
                    'amount_paid' => 2700,
                    'referred_checkout' => ['discount_amount' => 300],
                ],
            ]);
        });

        ReferralRewardLedger::query()->create([
            'user_referral_id' => UserReferral::query()->value('id'),
            'referrer_id' => $referrer->id,
            'referred_user_id' => $buyer->id,
            'action_type' => 'auto_applied',
            'bonus_days' => 5,
            'meta' => [],
        ]);

        $economics = app(ReferralService::class)->adminReportsBundle()['economics'];

        $this->assertSame(2700.0, $economics['referred_first_paid_revenue']);
        $this->assertSame(300.0, $economics['invite_checkout_discount']);
        $this->assertSame(5, $economics['referrer_reward_bonus_days']);
        $this->assertGreaterThan(0, $economics['referrer_reward_cost_estimate']);
        $this->assertSame(
            round(2700 - 300 - $economics['referrer_reward_cost_estimate'], 2),
            $economics['net_margin_estimate'],
        );
    }

    public function test_admin_reports_tab_renders_funnel_and_economics(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.referrals.index', ['tab' => 'reports']));

        $response->assertOk();
        $response->assertSee(__('admin_monetization.referral_funnel_heading'), false);
        $response->assertSee(__('admin_monetization.referral_economics_heading'), false);
    }

    public function test_admin_reports_export_includes_summary_metrics(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.referrals.export', ['tab' => 'reports']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('funnel_invited', $response->streamedContent());
        $this->assertStringContainsString('economics_net_margin_estimate', $response->streamedContent());
    }
}
