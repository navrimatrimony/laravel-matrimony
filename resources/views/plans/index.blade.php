@extends('layouts.app')

@section('content')
<div class="py-10">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('subscriptions.plans_title') }}</h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400 text-sm">{{ __('subscriptions.plans_intro') }}</p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-500">
                {{ __('subscriptions.current_plan') }}:
                <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $effectivePlan->name }}</span>
            </p>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                {{ session('error') }}
            </div>
        @endif

        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($plans as $plan)
                @php
                    $isCurrent = (int) $plan->id === (int) $effectivePlan->id;
                    $base = (float) $plan->price;
                    $final = $plan->final_price;
                    $disc = $plan->hasActiveDiscount();
                @endphp
                <div class="relative flex flex-col rounded-2xl border bg-white dark:bg-gray-800 shadow-sm p-6 {{ $plan->highlight ? 'ring-2 ring-amber-400 dark:ring-amber-500 border-amber-200 dark:border-amber-900' : 'border-gray-200 dark:border-gray-700' }}">
                    @if ($plan->highlight)
                        <span class="absolute -top-3 left-4 inline-flex items-center rounded-full bg-amber-500 px-3 py-0.5 text-xs font-bold text-white shadow">
                            {{ __('subscriptions.best_value') }}
                        </span>
                    @endif
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ $plan->name }}</h2>
                    <div class="mt-3 text-2xl font-bold">
                        @if ($disc && $base > 0)
                            <span class="line-through text-red-500 dark:text-red-400 text-lg mr-2">₹{{ number_format($base, 0) }}</span>
                            <span class="text-green-600 dark:text-green-400">₹{{ number_format($final, 0) }}</span>
                        @else
                            <span class="text-green-600 dark:text-green-400">₹{{ number_format($final, 0) }}</span>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if ((int) $plan->duration_days === 0)
                            {{ __('subscriptions.duration_unlimited') }}
                        @else
                            {{ __('subscriptions.duration_days', ['count' => $plan->duration_days]) }}
                        @endif
                    </p>

                    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300 flex-1">
                        @php
                            $fmtLimit = function (?string $v): string {
                                $v = trim((string) $v);
                                if ($v === '' || $v === null) {
                                    return '—';
                                }
                                if ((int) $v === -1 || strcasecmp($v, 'unlimited') === 0) {
                                    return __('subscriptions.unlimited');
                                }

                                return $v;
                            };
                        @endphp
                        <li><span class="font-medium">{{ __('subscriptions.feature_daily_chat') }}:</span> {{ $fmtLimit($plan->featureValue(\App\Services\SubscriptionService::FEATURE_DAILY_CHAT_SEND_LIMIT)) }}</li>
                        <li><span class="font-medium">{{ __('subscriptions.feature_monthly_interests') }}:</span> {{ $fmtLimit($plan->featureValue(\App\Services\SubscriptionService::FEATURE_MONTHLY_INTEREST_SEND_LIMIT)) }}</li>
                        <li><span class="font-medium">{{ __('subscriptions.feature_daily_profile_views') }}:</span> {{ $fmtLimit($plan->featureValue(\App\Services\SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT)) }}</li>
                        <li><span class="font-medium">{{ __('subscriptions.feature_contact') }}:</span>
                            @if ($plan->featureValue(\App\Services\SubscriptionService::FEATURE_CONTACT_NUMBER_ACCESS, '0') === '1' || in_array(strtolower((string) $plan->featureValue(\App\Services\SubscriptionService::FEATURE_CONTACT_NUMBER_ACCESS, '0')), ['true', 'yes'], true))
                                {{ __('subscriptions.yes') }}
                            @else
                                {{ __('subscriptions.no') }}
                            @endif
                        </li>
                        <li><span class="font-medium">{{ __('subscriptions.feature_chat_images') }}:</span>
                            @if ($plan->featureValue(\App\Services\SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES, '0') === '1')
                                {{ __('subscriptions.yes') }}
                            @else
                                {{ __('subscriptions.no') }}
                            @endif
                        </li>
                    </ul>

                    <div class="mt-6">
                        @if ($isCurrent)
                            <span class="inline-flex w-full justify-center rounded-lg bg-gray-100 dark:bg-gray-700 px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                                {{ __('subscriptions.current_plan') }}
                            </span>
                        @else
                            <form method="POST" action="{{ route('plans.subscribe', $plan) }}">
                                @csrf
                                <button type="submit" class="w-full rounded-lg bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-semibold text-white">
                                    {{ __('subscriptions.subscribe') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
