@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('user_plan.page_title') }}</h1>
            </div>
            <a href="{{ route('user.plan-history') }}" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">
                {{ __('user_plan.plan_history_title') }}
            </a>
        </div>

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
    </div>
</div>
@endsection
