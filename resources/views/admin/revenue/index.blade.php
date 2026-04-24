@extends('layouts.admin')

@section('content')
<div class="space-y-8">
    <div>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.revenue_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_commerce.revenue_intro') }}</p>
        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">{{ __('admin_commerce.revenue_sources_note') }}</p>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.revenue_analytics_heading') }}</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-4">{{ __('admin_commerce.revenue_analytics_range_note') }}</p>
        <form method="get" action="{{ route('admin.revenue.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="revenue-from" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('admin_commerce.revenue_filter_from') }}</label>
                <input id="revenue-from" type="date" name="from" value="{{ $filterFrom }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm">
            </div>
            <div>
                <label for="revenue-to" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('admin_commerce.revenue_filter_to') }}</label>
                <input id="revenue-to" type="date" name="to" value="{{ $filterTo }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm">
            </div>
            <button type="submit" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">{{ __('admin_commerce.revenue_filter_apply') }}</button>
            <a href="{{ route('admin.revenue.index') }}" class="inline-flex items-center rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('admin_commerce.revenue_filter_clear') }}</a>
        </form>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_metric_subscriptions') }}</p>
            <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white tabular-nums">{{ number_format($totalSubscriptions) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_metric_total_revenue') }}</p>
            <p class="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">₹{{ number_format($totalRevenue, 2) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_metric_coupon_usage') }}</p>
            <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white tabular-nums">{{ number_format($couponUsageCount) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_metric_referral_ledger') }}</p>
            <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white tabular-nums">{{ number_format($referralRewardsCount) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-600">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.revenue_table_daily_revenue') }}</h2>
            </div>
            <div class="overflow-x-auto max-h-80 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600 sticky top-0 bg-white dark:bg-gray-800">
                        <tr>
                            <th class="text-left py-3 px-5">{{ __('admin_commerce.revenue_col_date') }}</th>
                            <th class="text-right py-3 px-5">{{ __('admin_commerce.revenue_col_amount') }}</th>
                            <th class="text-left py-3 px-5 w-32">{{ __('admin_commerce.revenue_col_bar') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($dailyRevenue as $row)
                            <tr>
                                <td class="py-2 px-5 font-mono text-gray-800 dark:text-gray-200">{{ $row['date'] }}</td>
                                <td class="py-2 px-5 text-right tabular-nums text-gray-900 dark:text-white">₹{{ number_format($row['total_amount'], 2) }}</td>
                                <td class="py-2 px-5">
                                    <div class="h-2 w-full rounded bg-gray-200 dark:bg-gray-600 overflow-hidden">
                                        <div class="h-2 rounded bg-emerald-500" style="width: {{ $row['bar_width_pct'] }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-6 px-5 text-center text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_analytics_empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-600">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.revenue_table_daily_subscriptions') }}</h2>
            </div>
            <div class="overflow-x-auto max-h-80 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600 sticky top-0 bg-white dark:bg-gray-800">
                        <tr>
                            <th class="text-left py-3 px-5">{{ __('admin_commerce.revenue_col_date') }}</th>
                            <th class="text-right py-3 px-5">{{ __('admin_commerce.revenue_col_count') }}</th>
                            <th class="text-left py-3 px-5 w-32">{{ __('admin_commerce.revenue_col_bar') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($dailySubscriptions as $row)
                            <tr>
                                <td class="py-2 px-5 font-mono text-gray-800 dark:text-gray-200">{{ $row['date'] }}</td>
                                <td class="py-2 px-5 text-right tabular-nums text-gray-900 dark:text-white">{{ number_format($row['count']) }}</td>
                                <td class="py-2 px-5">
                                    <div class="h-2 w-full rounded bg-gray-200 dark:bg-gray-600 overflow-hidden">
                                        <div class="h-2 rounded bg-indigo-500" style="width: {{ $row['bar_width_pct'] }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-6 px-5 text-center text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_analytics_empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-600">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.revenue_table_coupon_trend') }}</h2>
            </div>
            <div class="overflow-x-auto max-h-80 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600 sticky top-0 bg-white dark:bg-gray-800">
                        <tr>
                            <th class="text-left py-3 px-5">{{ __('admin_commerce.revenue_col_date') }}</th>
                            <th class="text-right py-3 px-5">{{ __('admin_commerce.revenue_col_count') }}</th>
                            <th class="text-left py-3 px-5 w-32">{{ __('admin_commerce.revenue_col_bar') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($couponTrend as $row)
                            <tr>
                                <td class="py-2 px-5 font-mono text-gray-800 dark:text-gray-200">{{ $row['date'] }}</td>
                                <td class="py-2 px-5 text-right tabular-nums text-gray-900 dark:text-white">{{ number_format($row['count']) }}</td>
                                <td class="py-2 px-5">
                                    <div class="h-2 w-full rounded bg-gray-200 dark:bg-gray-600 overflow-hidden">
                                        <div class="h-2 rounded bg-amber-500" style="width: {{ $row['bar_width_pct'] }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-6 px-5 text-center text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_analytics_empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-600">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.revenue_table_referral_trend') }}</h2>
            </div>
            <div class="overflow-x-auto max-h-80 overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600 sticky top-0 bg-white dark:bg-gray-800">
                        <tr>
                            <th class="text-left py-3 px-5">{{ __('admin_commerce.revenue_col_date') }}</th>
                            <th class="text-right py-3 px-5">{{ __('admin_commerce.revenue_col_count') }}</th>
                            <th class="text-left py-3 px-5 w-32">{{ __('admin_commerce.revenue_col_bar') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($referralTrend as $row)
                            <tr>
                                <td class="py-2 px-5 font-mono text-gray-800 dark:text-gray-200">{{ $row['date'] }}</td>
                                <td class="py-2 px-5 text-right tabular-nums text-gray-900 dark:text-white">{{ number_format($row['count']) }}</td>
                                <td class="py-2 px-5">
                                    <div class="h-2 w-full rounded bg-gray-200 dark:bg-gray-600 overflow-hidden">
                                        <div class="h-2 rounded bg-violet-500" style="width: {{ $row['bar_width_pct'] }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-6 px-5 text-center text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_analytics_empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-600">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.revenue_users_heading') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_commerce.revenue_users_help') }}</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                    <tr>
                        <th class="text-left py-3 px-5">{{ __('admin_commerce.revenue_col_user') }}</th>
                        <th class="text-left py-3 px-5">{{ __('admin_commerce.revenue_col_active_plan') }}</th>
                        <th class="text-right py-3 px-5">{{ __('admin_commerce.revenue_col_spent') }}</th>
                        <th class="text-right py-3 px-5">{{ __('admin_commerce.revenue_col_referral_ledger') }}</th>
                        <th class="text-right py-3 px-5">{{ __('admin_commerce.revenue_col_coupon_subs') }}</th>
                        <th class="text-right py-3 px-5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($memberRows as $mr)
                        <tr>
                            <td class="py-3 px-5">
                                <div class="font-medium text-gray-900 dark:text-white">#{{ $mr['user']->id }}</div>
                                <div class="text-gray-600 dark:text-gray-300">{{ $mr['user']->name ?? '—' }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-[14rem]">{{ $mr['user']->email ?? '—' }}</div>
                            </td>
                            <td class="py-3 px-5 text-gray-800 dark:text-gray-200">{{ ($mr['active_plan'] ?? null) !== null && $mr['active_plan'] !== '' ? $mr['active_plan'] : '—' }}</td>
                            <td class="py-3 px-5 text-right tabular-nums font-medium text-gray-900 dark:text-white">₹{{ number_format($mr['spent'], 2) }}</td>
                            <td class="py-3 px-5 text-right tabular-nums text-gray-800 dark:text-gray-200">{{ number_format($mr['referral_ledger_count']) }}</td>
                            <td class="py-3 px-5 text-right tabular-nums text-gray-800 dark:text-gray-200">{{ number_format($mr['subscription_coupon_count']) }}</td>
                            <td class="py-3 px-5 text-right">
                                <a href="{{ route('admin.users.plan', $mr['user']) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs font-medium">{{ __('admin_commerce.revenue_link_plan') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 px-5 text-center text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_users_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="px-5 py-3 border-t border-gray-200 dark:border-gray-600">{{ $users->links() }}</div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-600">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.revenue_coupon_per_code') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_commerce.revenue_coupon_per_code_help') }}</p>
            </div>
            <div class="overflow-x-auto max-h-[28rem] overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600 sticky top-0 bg-white dark:bg-gray-800">
                        <tr>
                            <th class="text-left py-3 px-5">{{ __('admin_commerce.coupon_code') }}</th>
                            <th class="text-right py-3 px-5">{{ __('admin_commerce.coupon_redemptions') }}</th>
                            <th class="text-center py-3 px-5">{{ __('admin_monetization.col_active') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($couponPerCode as $c)
                            <tr>
                                <td class="py-3 px-5 font-mono text-gray-800 dark:text-gray-200">{{ $c->code }}</td>
                                <td class="py-3 px-5 text-right tabular-nums">{{ number_format((int) $c->redemptions_count) }}</td>
                                <td class="py-3 px-5 text-center text-xs">{{ $c->is_active ? __('admin_monetization.toggle_on') : __('admin_monetization.toggle_off') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-8 px-5 text-center text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_no_coupons') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm overflow-hidden lg:col-span-1">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-600">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.revenue_coupon_history') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_commerce.revenue_coupon_history_help') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                        <tr>
                            <th class="text-left py-3 px-5">{{ __('admin_commerce.revenue_col_when') }}</th>
                            <th class="text-left py-3 px-5">{{ __('admin_commerce.revenue_col_user') }}</th>
                            <th class="text-left py-3 px-5">{{ __('admin_commerce.coupon_code') }}</th>
                            <th class="text-left py-3 px-5">{{ __('admin_commerce.revenue_col_plan') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($couponHistory as $sub)
                            <tr>
                                <td class="py-3 px-5 text-gray-600 dark:text-gray-300 whitespace-nowrap">{{ $sub->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="py-3 px-5">
                                    @if ($sub->user)
                                        <span class="text-gray-900 dark:text-white">#{{ $sub->user->id }}</span>
                                        <span class="block text-xs text-gray-500 truncate max-w-[10rem]">{{ $sub->user->email ?? '—' }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-3 px-5 font-mono text-gray-800 dark:text-gray-200">{{ $sub->coupon?->code ?? '—' }}</td>
                                <td class="py-3 px-5 text-gray-800 dark:text-gray-200">{{ $sub->plan?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-8 px-5 text-center text-gray-500 dark:text-gray-400">{{ __('admin_commerce.revenue_coupon_history_empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($couponHistory->hasPages())
                <div class="px-5 py-3 border-t border-gray-200 dark:border-gray-600">{{ $couponHistory->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
