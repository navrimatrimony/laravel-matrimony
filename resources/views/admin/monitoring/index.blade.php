@extends('layouts.admin')

@section('content')
<meta http-equiv="refresh" content="30">
@php
    $alertPayload = is_array($alerts) ? $alerts : [];
    $alertList = is_array($alertPayload['alerts'] ?? null) ? $alertPayload['alerts'] : [];
    $hasSev1 = collect($alertList)->contains(fn ($a) => (string) ($a['severity'] ?? '') === 'SEV-1');
    $webhookRequests = (int) ($webhook['webhook_requests_total'] ?? 0);
    $webhookFailures = (int) ($webhook['webhook_failures_total'] ?? 0);
    $webhookSuccessPct = $webhookRequests > 0 ? round((($webhookRequests - $webhookFailures) / $webhookRequests) * 100, 2) : 100;
    $queueLag = (int) (($alertPayload['reliability']['queue_lag_seconds'] ?? 0));
    $failedJobs = (int) (($alertPayload['reliability']['failed_jobs_total'] ?? 0));
    $refundsToday = (int) ($finance['today_refunds'] ?? 0);
    $todaySuccess = (int) ($finance['today_success_payments'] ?? 0);
    $disputesToday = (int) (($finance['today_disputes'] ?? 0));
    $reconMismatches = (int) ($integrity['payment_subscription_mismatch_count'] ?? 0);
@endphp

<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Payment Monitoring</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Auto refresh every 30 seconds</p>
        </div>
    </div>

    @if ($hasSev1)
        <div class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-red-900 dark:border-red-700 dark:bg-red-950/30 dark:text-red-100 font-semibold">
            🚨 Critical Payment Issue Detected
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Live Payments (5m)</h2>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Initiated</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format((int) ($live['payments_initiated_total'] ?? 0)) }}</p>
                </div>
                <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 p-3">
                    <p class="text-xs text-emerald-700 dark:text-emerald-300">Success</p>
                    <p class="text-xl font-bold text-emerald-700 dark:text-emerald-300">{{ number_format((int) ($live['payments_success_total'] ?? 0)) }}</p>
                </div>
                <div class="rounded-lg bg-red-50 dark:bg-red-900/20 p-3">
                    <p class="text-xs text-red-700 dark:text-red-300">Failed</p>
                    <p class="text-xl font-bold text-red-700 dark:text-red-300">{{ number_format((int) ($live['payments_failed_total'] ?? 0)) }}</p>
                </div>
                <div class="rounded-lg p-3 {{ ((float)($live['success_rate_pct'] ?? 0)) >= 85 ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-red-50 dark:bg-red-900/20' }}">
                    <p class="text-xs {{ ((float)($live['success_rate_pct'] ?? 0)) >= 85 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' }}">Success Rate</p>
                    <p class="text-xl font-bold {{ ((float)($live['success_rate_pct'] ?? 0)) >= 85 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' }}">{{ number_format((float) ($live['success_rate_pct'] ?? 0), 2) }}%</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Alerts</h2>
            <div class="space-y-2">
                @forelse ($alertList as $alert)
                    @php
                        $sev = (string) ($alert['severity'] ?? 'SEV-3');
                        $badge = $sev === 'SEV-1'
                            ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200'
                            : ($sev === 'SEV-2'
                                ? 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-200'
                                : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-200');
                    @endphp
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badge }}">{{ $sev }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $alert['at'] ?? '' }}</span>
                        </div>
                        <p class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ (string) ($alert['code'] ?? 'alert') }}</p>
                        <p class="text-xs text-gray-600 dark:text-gray-300">Metric value: {{ json_encode($alert['details'] ?? []) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-emerald-700 dark:text-emerald-300 font-medium">No active alerts</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Data Integrity</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                @php $missingSub=(int)($integrity['missing_subscription_count'] ?? 0); @endphp
                <div class="rounded-lg p-3 {{ $missingSub > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20' }}">
                    <p class="text-xs {{ $missingSub > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">Missing subscriptions</p>
                    <p class="text-xl font-bold {{ $missingSub > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ number_format($missingSub) }}</p>
                </div>
                @php $missingInv=(int)($integrity['missing_invoice_count'] ?? 0); @endphp
                <div class="rounded-lg p-3 {{ $missingInv > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20' }}">
                    <p class="text-xs {{ $missingInv > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">Missing invoices</p>
                    <p class="text-xl font-bold {{ $missingInv > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ number_format($missingInv) }}</p>
                </div>
                @php $mismatch=(int)($integrity['payment_subscription_mismatch_count'] ?? 0); @endphp
                <div class="rounded-lg p-3 {{ $mismatch > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20' }}">
                    <p class="text-xs {{ $mismatch > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">Mismatch count</p>
                    <p class="text-xl font-bold {{ $mismatch > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ number_format($mismatch) }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Webhook & Queue</h2>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg p-3 {{ $webhookSuccessPct >= 95 ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-yellow-50 dark:bg-yellow-900/20' }}">
                    <p class="text-xs {{ $webhookSuccessPct >= 95 ? 'text-emerald-700 dark:text-emerald-300' : 'text-yellow-700 dark:text-yellow-300' }}">Webhook success %</p>
                    <p class="text-xl font-bold {{ $webhookSuccessPct >= 95 ? 'text-emerald-700 dark:text-emerald-300' : 'text-yellow-700 dark:text-yellow-300' }}">{{ number_format($webhookSuccessPct, 2) }}%</p>
                </div>
                <div class="rounded-lg p-3 {{ $webhookFailures > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20' }}">
                    <p class="text-xs {{ $webhookFailures > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">Failure count</p>
                    <p class="text-xl font-bold {{ $webhookFailures > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ number_format($webhookFailures) }}</p>
                </div>
                <div class="rounded-lg p-3 {{ $queueLag > 120 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20' }}">
                    <p class="text-xs {{ $queueLag > 120 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">Queue lag (sec)</p>
                    <p class="text-xl font-bold {{ $queueLag > 120 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ number_format($queueLag) }}</p>
                </div>
                <div class="rounded-lg p-3 {{ $failedJobs > 0 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20' }}">
                    <p class="text-xs {{ $failedJobs > 0 ? 'text-yellow-700 dark:text-yellow-300' : 'text-emerald-700 dark:text-emerald-300' }}">Failed jobs</p>
                    <p class="text-xl font-bold {{ $failedJobs > 0 ? 'text-yellow-700 dark:text-yellow-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ number_format($failedJobs) }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-5 shadow-sm lg:col-span-2">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Finance Snapshot (Today)</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Refunds today</p>
                    <p class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($refundsToday) }}</p>
                </div>
                <div class="rounded-lg p-3 {{ $disputesToday > 0 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20' }}">
                    <p class="text-xs {{ $disputesToday > 0 ? 'text-yellow-700 dark:text-yellow-300' : 'text-emerald-700 dark:text-emerald-300' }}">Disputes today</p>
                    <p class="text-xl font-bold {{ $disputesToday > 0 ? 'text-yellow-700 dark:text-yellow-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ number_format($disputesToday) }}</p>
                </div>
                <div class="rounded-lg p-3 {{ $reconMismatches > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20' }}">
                    <p class="text-xs {{ $reconMismatches > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">Reconciliation mismatches</p>
                    <p class="text-xl font-bold {{ $reconMismatches > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">{{ number_format($reconMismatches) }}</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Successful payments today: {{ number_format($todaySuccess) }}</p>
        </div>
    </div>
</div>
@endsection

