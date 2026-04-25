@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
            <a href="{{ route('user.settings.index') }}" class="text-sm font-medium text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400">
                ← {{ __('user_plan.settings_back') }}
            </a>
        </div>

        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('user_plan.my_plan_hub_title') }}</h1>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('user_plan.settings_my_plan_intro') }}</p>
        </div>

        <div class="border-b border-gray-200 dark:border-gray-700 mb-8">
            <nav class="-mb-px flex flex-wrap gap-4" aria-label="{{ __('user_plan.my_plan_hub_title') }}">
                <a
                    href="{{ route('user.settings.my-plan', ['tab' => 'overview']) }}"
                    @class([
                        'inline-flex items-center border-b-2 px-1 py-3 text-sm font-semibold transition',
                        'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' => $tab === 'overview',
                        'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== 'overview',
                    ])
                >
                    {{ __('user_plan.tab_overview') }}
                </a>
                <a
                    href="{{ route('user.settings.my-plan', ['tab' => 'history']) }}"
                    @class([
                        'inline-flex items-center border-b-2 px-1 py-3 text-sm font-semibold transition',
                        'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' => $tab === 'history',
                        'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== 'history',
                    ])
                >
                    {{ __('user_plan.tab_history') }}
                </a>
            </nav>
        </div>

        @if ($tab === 'overview')
            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg border border-gray-200 dark:border-gray-700 p-6 sm:p-8">
                @if (! empty($upgradeUi['upgrade_available']))
                    <div class="mb-8 rounded-xl border border-indigo-200 bg-indigo-50/80 px-4 py-4 dark:border-indigo-900/60 dark:bg-indigo-950/30">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-indigo-900 dark:text-indigo-100">{{ __('subscription_upgrade.banner_title') }}</p>
                                <p class="mt-1 text-sm text-indigo-900/85 dark:text-indigo-100/85">{{ __('subscription_upgrade.banner_body') }}</p>
                            </div>
                            <a
                                href="{{ $upgradeUi['upgrade_cta_route'] ?? route('plans.index') }}"
                                data-upgrade-cta="1"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 dark:bg-indigo-500 dark:hover:bg-indigo-400"
                            >
                                {{ __('subscription_upgrade.cta_pricing') }}
                            </a>
                        </div>
                    </div>
                @endif

                @include('partials.quota-plan-summary-panel', ['quotaSummary' => $quotaSummary])
                @include('partials.revenue-recent-benefits', ['recentBenefits' => $recentBenefits ?? []])
            </div>
        @else
            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/60 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3">{{ __('user_plan.history_plan') }}</th>
                                <th class="px-4 py-3">{{ __('user_plan.history_start') }}</th>
                                <th class="px-4 py-3">{{ __('user_plan.history_end') }}</th>
                                <th class="px-4 py-3">{{ __('user_plan.history_status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($subscriptions as $sub)
                                <tr>
                                    <td class="px-4 py-3">{{ $sub->plan?->name ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $sub->starts_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $sub->ends_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $sub->status }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">—</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Payment & Invoices</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/60 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3">Txn</th>
                                <th class="px-4 py-3">Plan</th>
                                <th class="px-4 py-3">Amount</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Invoice</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse (($payments ?? collect()) as $payment)
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $payment->txnid }}</td>
                                    <td class="px-4 py-3">{{ $payment->plan?->name ?? ($payment->plan_key ?? '—') }}</td>
                                    <td class="px-4 py-3">₹{{ number_format((float) ($payment->amount_paid ?? $payment->amount ?? 0), 2) }}</td>
                                    <td class="px-4 py-3">{{ $payment->payment_status ?? $payment->status }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('payments.invoice', ['txnid' => $payment->txnid]) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">View invoice</a>
                                        <span class="text-gray-300 mx-1">|</span>
                                        <a href="{{ route('payments.invoice.pdf', ['txnid' => $payment->txnid]) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">PDF</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">—</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
