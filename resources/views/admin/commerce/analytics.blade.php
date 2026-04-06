@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.analytics_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_commerce.analytics_intro') }}</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin_commerce.metric_total_users') }}</p>
            <p class="mt-2 text-3xl font-bold text-gray-900 dark:text-white tabular-nums">{{ number_format($totalUsers) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin_commerce.metric_active_subscriptions') }}</p>
            <p class="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">{{ number_format($activeSubscriptions) }}</p>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-600">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.metric_feature_usage') }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                    <tr>
                        <th class="text-left py-3 px-5">Feature key</th>
                        <th class="text-right py-3 px-5">Total used</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($featureUsageTotals as $row)
                        <tr>
                            <td class="py-3 px-5 font-mono text-gray-800 dark:text-gray-200">{{ $row->feature_key }}</td>
                            <td class="py-3 px-5 text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ number_format((int) $row->total_used) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="py-8 px-5 text-center text-gray-500 dark:text-gray-400">No usage rows yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
