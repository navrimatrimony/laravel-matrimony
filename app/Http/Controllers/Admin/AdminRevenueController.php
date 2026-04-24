<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\ReferralRewardLedger;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RevenueAnalyticsService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Phase-4 admin revenue control: read-only exposure of persisted monetization data.
 * Aggregates are direct DB reads; active plan uses {@see SubscriptionService::getActiveSubscription} (no duplicated selection rules).
 * Phase-5 time-series use {@see RevenueAnalyticsService} (DB aggregation only).
 */
class AdminRevenueController extends Controller
{
    public function index(
        Request $request,
        SubscriptionService $subscriptions,
        RevenueAnalyticsService $revenueAnalytics,
    ): View {
        $fromRaw = trim((string) $request->query('from', ''));
        $toRaw = trim((string) $request->query('to', ''));
        $fromForValidate = $fromRaw !== '' ? $fromRaw : null;
        $toForValidate = $toRaw !== '' ? $toRaw : null;

        $rules = [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
        if ($fromForValidate !== null) {
            $rules['to'][] = 'after_or_equal:from';
        }

        Validator::make(
            ['from' => $fromForValidate, 'to' => $toForValidate],
            $rules,
        )->validate();

        $fromCarbon = $fromForValidate !== null ? Carbon::parse($fromForValidate)->startOfDay() : null;
        $toCarbon = $toForValidate !== null ? Carbon::parse($toForValidate)->endOfDay() : null;

        $dailyRevenue = self::withBarWidthPercent($revenueAnalytics->getDailyRevenue($fromCarbon, $toCarbon), 'total_amount');
        $dailySubscriptions = self::withBarWidthPercent($revenueAnalytics->getDailySubscriptions($fromCarbon, $toCarbon), 'count');
        $couponTrend = self::withBarWidthPercent($revenueAnalytics->getCouponUsageTrend($fromCarbon, $toCarbon), 'count');
        $referralTrend = self::withBarWidthPercent($revenueAnalytics->getReferralTrend($fromCarbon, $toCarbon), 'count');

        $filterFrom = $fromRaw;
        $filterTo = $toRaw;

        $totalSubscriptions = (int) Subscription::query()->count();

        $totalRevenue = (float) (Payment::query()
            ->where('payment_status', PaymentStatus::Success->value)
            ->sum('amount_paid') ?? 0);

        $couponUsageCount = (int) (Coupon::query()->sum('redemptions_count') ?? 0);

        $referralRewardsCount = (int) ReferralRewardLedger::query()->count();

        $couponPerCode = Coupon::query()
            ->orderBy('code')
            ->get(['id', 'code', 'redemptions_count', 'is_active']);

        $couponHistory = Subscription::query()
            ->with(['user:id,name,email', 'coupon:id,code', 'plan:id,name,slug'])
            ->whereNotNull('coupon_id')
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'coupon_history')
            ->withQueryString();

        $users = User::query()
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        /** @var list<int> $ids */
        $ids = $users->pluck('id')->all();

        $spentByUserId = $ids === [] ? collect() : Payment::query()
            ->select('user_id', DB::raw('SUM(amount_paid) as total'))
            ->where('payment_status', PaymentStatus::Success->value)
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $referralLedgerCountByReferrerId = $ids === [] ? collect() : ReferralRewardLedger::query()
            ->select('referrer_id', DB::raw('COUNT(*) as c'))
            ->whereIn('referrer_id', $ids)
            ->groupBy('referrer_id')
            ->pluck('c', 'referrer_id');

        $subscriptionWithCouponCountByUserId = $ids === [] ? collect() : Subscription::query()
            ->select('user_id', DB::raw('COUNT(*) as c'))
            ->whereNotNull('coupon_id')
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->pluck('c', 'user_id');

        $activePlanLabels = [];
        foreach ($users as $user) {
            $active = $subscriptions->getActiveSubscription($user);
            $activePlanLabels[(int) $user->id] = $active?->plan?->name;
        }

        $memberRows = [];
        foreach ($users as $user) {
            $uid = (int) $user->id;
            $memberRows[] = [
                'user' => $user,
                'active_plan' => $activePlanLabels[$uid] ?? null,
                'spent' => (float) ($spentByUserId[$uid] ?? 0),
                'referral_ledger_count' => (int) ($referralLedgerCountByReferrerId[$uid] ?? 0),
                'subscription_coupon_count' => (int) ($subscriptionWithCouponCountByUserId[$uid] ?? 0),
            ];
        }

        return view('admin.revenue.index', compact(
            'totalSubscriptions',
            'totalRevenue',
            'couponUsageCount',
            'referralRewardsCount',
            'couponPerCode',
            'couponHistory',
            'users',
            'memberRows',
            'dailyRevenue',
            'dailySubscriptions',
            'couponTrend',
            'referralTrend',
            'filterFrom',
            'filterTo',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function withBarWidthPercent(array $rows, string $valueKey): array
    {
        $max = 0.0;
        foreach ($rows as $r) {
            $max = max($max, (float) ($r[$valueKey] ?? 0));
        }
        if ($max <= 0.0) {
            return array_map(static fn (array $r): array => $r + ['bar_width_pct' => 0.0], $rows);
        }

        return array_map(static function (array $r) use ($max, $valueKey): array {
            $v = (float) ($r[$valueKey] ?? 0);

            return $r + ['bar_width_pct' => round(($v / $max) * 100, 2)];
        }, $rows);
    }
}
