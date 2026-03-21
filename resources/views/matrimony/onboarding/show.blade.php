@extends('layouts.app')

@section('content')
@php
    $labels = [
        2 => ['title' => __('onboarding.step2_title'), 'sub' => __('onboarding.step2_sub')],
        3 => ['title' => __('onboarding.step3_title'), 'sub' => __('onboarding.step3_sub')],
        4 => ['title' => __('onboarding.step4_title'), 'sub' => __('onboarding.step4_sub')],
        5 => ['title' => __('onboarding.step5_title'), 'sub' => __('onboarding.step5_sub')],
    ];
    $head = $labels[$step] ?? ['title' => '', 'sub' => ''];
    $pct = (int) round(($step / max(1, (int) ($totalSteps ?? 5))) * 100);
@endphp
<div class="py-6 md:py-12 bg-gradient-to-b from-slate-50/90 via-white to-slate-50/80 dark:from-gray-950 dark:via-gray-900 dark:to-gray-950 min-h-[calc(100vh-5rem)]">
    {{-- Capped width on large screens so fields and CTAs do not stretch edge-to-edge --}}
    <div class="w-full max-w-xl md:max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        {{-- success/info flash: layouts.app only (dismissible + auto-hide) to avoid duplicate banners --}}
        @if ($errors->any())
            <div class="mb-4 px-4 py-3 rounded-xl bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        <p class="text-xs font-semibold tracking-wide text-indigo-600 dark:text-indigo-400 uppercase mb-2">{{ __('onboarding.step_of', ['current' => $step, 'total' => $totalSteps]) }}</p>
        <div class="mb-6">
            <div class="h-2.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-rose-500 transition-all duration-500" style="width: {{ $pct }}%"></div>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200/90 dark:border-gray-600/90 bg-white dark:bg-gray-800 shadow-xl shadow-slate-200/40 dark:shadow-none ring-1 ring-black/[0.03] dark:ring-white/[0.06] p-6 sm:p-8 md:p-9 space-y-6 md:space-y-8">
            <header>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 leading-tight">{{ $head['title'] }}</h1>
                <p class="mt-2 text-gray-600 dark:text-gray-300 text-sm sm:text-base">{{ $head['sub'] }}</p>
            </header>

            @if ($step === 2)
                @include('matrimony.onboarding.steps.step2')
            @elseif ($step === 3)
                @include('matrimony.onboarding.steps.step3')
            @elseif ($step === 4)
                @include('matrimony.onboarding.steps.step4')
            @elseif ($step === 5)
                @include('matrimony.onboarding.steps.step5')
            @endif
        </div>

        <p class="mt-6 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.hero_line') }}</p>
    </div>
</div>
@endsection
