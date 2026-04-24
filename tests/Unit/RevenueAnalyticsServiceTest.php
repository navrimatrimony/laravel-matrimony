<?php

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ReferralRewardLedger;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RevenueAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_daily_revenue_groups_by_date(): void
    {
        $user = User::factory()->create();
        $day = Carbon::now()->subDays(3)->startOfDay();
        foreach (['TXN_A', 'TXN_B'] as $i => $txnid) {
            $p = Payment::query()->create([
                'user_id' => $user->id,
                'plan_id' => null,
                'plan_term_id' => null,
                'txnid' => $txnid,
                'amount' => $i === 0 ? 100 : 50,
                'amount_paid' => $i === 0 ? 100 : 50,
                'currency' => 'INR',
                'status' => 'success',
                'payment_status' => PaymentStatus::Success->value,
                'gateway' => 'payu',
            ]);
            $p->forceFill([
                'created_at' => $day->copy()->addHours(2 + $i),
                'updated_at' => $day->copy()->addHours(2 + $i),
            ])->saveQuietly();
        }

        $svc = app(RevenueAnalyticsService::class);
        $rows = $svc->getDailyRevenue($day->copy()->startOfDay(), $day->copy()->endOfDay());

        $this->assertCount(1, $rows);
        $this->assertSame($day->toDateString(), $rows[0]['date']);
        $this->assertEqualsWithDelta(150.0, $rows[0]['total_amount'], 0.01);
    }

    public function test_get_referral_trend_counts_by_date(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $day = Carbon::now()->subDays(2)->startOfDay();
        $row = ReferralRewardLedger::query()->create([
            'user_referral_id' => null,
            'referrer_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'performed_by_admin_id' => null,
            'action_type' => 'auto_applied',
            'bonus_days' => 1,
            'feature_bonus' => null,
            'reason' => null,
            'meta' => null,
        ]);
        $row->forceFill([
            'created_at' => $day->copy()->addHour(),
            'updated_at' => $day->copy()->addHour(),
        ])->saveQuietly();

        $svc = app(RevenueAnalyticsService::class);
        $rows = $svc->getReferralTrend($day->copy()->startOfDay(), $day->copy()->endOfDay());

        $this->assertCount(1, $rows);
        $this->assertSame($day->toDateString(), $rows[0]['date']);
        $this->assertSame(1, $rows[0]['count']);
    }

    public function test_get_daily_subscriptions_and_coupon_trend(): void
    {
        $user = User::factory()->create();
        $plan = Plan::query()->create([
            'name' => 'Analytics Unit Plan',
            'slug' => 'analytics-unit-'.uniqid('', true),
            'price' => 1,
            'discount_percent' => 0,
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 0,
            'highlight' => false,
            'marketing_badge' => null,
            'applies_to_gender' => 'all',
            'gst_inclusive' => true,
            'grace_period_days' => 0,
            'leftover_quota_carry_window_days' => null,
        ]);
        $coupon = Coupon::query()->create([
            'code' => 'UNITREV',
            'type' => Coupon::TYPE_PERCENT,
            'value' => 5,
            'max_redemptions' => null,
            'redemptions_count' => 0,
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => true,
            'min_purchase_amount' => null,
            'applicable_plan_ids' => null,
            'applicable_duration_types' => null,
            'description' => null,
            'feature_payload' => null,
        ]);

        $day = Carbon::now()->subDay()->startOfDay();
        $sub = Subscription::withoutEvents(function () use ($user, $plan, $coupon, $day) {
            return Subscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_term_id' => null,
                'plan_price_id' => null,
                'coupon_id' => $coupon->id,
                'starts_at' => $day->copy()->addHour(),
                'ends_at' => $day->copy()->addMonth(),
                'status' => Subscription::STATUS_ACTIVE,
                'meta' => null,
            ]);
        });
        $sub->forceFill([
            'created_at' => $day->copy()->addHours(2),
            'updated_at' => $day->copy()->addHours(2),
        ])->saveQuietly();

        $svc = app(RevenueAnalyticsService::class);
        $subs = $svc->getDailySubscriptions($day->copy()->startOfDay(), $day->copy()->endOfDay());
        $this->assertCount(1, $subs);
        $this->assertSame($day->toDateString(), $subs[0]['date']);
        $this->assertSame(1, $subs[0]['count']);

        $couponRows = $svc->getCouponUsageTrend($day->copy()->startOfDay(), $day->copy()->endOfDay());
        $this->assertCount(1, $couponRows);
        $this->assertSame($day->toDateString(), $couponRows[0]['date']);
        $this->assertSame(1, $couponRows[0]['count']);
    }
}
