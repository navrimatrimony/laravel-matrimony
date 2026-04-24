<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\ReferralRewardLedger;
use App\Models\Subscription;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-only revenue time-series: DB aggregations only (no subscription selection / coupon rules).
 */
class RevenueAnalyticsService
{
    /**
     * @return list<array{date: string, total_amount: float}>
     */
    public function getDailyRevenue(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        [$start, $end] = $this->resolveRange($from, $to);

        return Payment::query()
            ->where('payment_status', PaymentStatus::Success->value)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as report_date, SUM(amount_paid) as total_amount')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('report_date')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->report_date,
                'total_amount' => (float) $r->total_amount,
            ])
            ->all();
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    public function getDailySubscriptions(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        [$start, $end] = $this->resolveRange($from, $to);

        return Subscription::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as report_date, COUNT(*) as count')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('report_date')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->report_date,
                'count' => (int) $r->count,
            ])
            ->all();
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    public function getCouponUsageTrend(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        [$start, $end] = $this->resolveRange($from, $to);

        return Subscription::query()
            ->whereNotNull('coupon_id')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as report_date, COUNT(*) as count')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('report_date')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->report_date,
                'count' => (int) $r->count,
            ])
            ->all();
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    public function getReferralTrend(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        [$start, $end] = $this->resolveRange($from, $to);

        return ReferralRewardLedger::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as report_date, COUNT(*) as count')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('report_date')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->report_date,
                'count' => (int) $r->count,
            ])
            ->all();
    }

    /**
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    private function resolveRange(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        if ($from === null && $to === null) {
            $end = Carbon::now()->endOfDay();
            $start = Carbon::now()->subDays(30)->startOfDay();

            return [$start, $end];
        }

        if ($from === null && $to !== null) {
            $end = Carbon::instance($to)->endOfDay();
            $start = $end->copy()->subDays(30)->startOfDay();

            return [$start, $end];
        }

        if ($from !== null && $to === null) {
            $start = Carbon::instance($from)->startOfDay();
            $end = Carbon::now()->endOfDay();

            return [$start, $end];
        }

        $start = Carbon::instance($from)->startOfDay();
        $end = Carbon::instance($to)->endOfDay();
        if ($start->gt($end)) {
            return [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }
}
