@props([
    'title' => null,
    'message' => '',
    'ctaText' => null,
    'ctaTextDynamic' => null,
    'ctaHref' => null,
])
@php
    $ctaHref = $ctaHref ?? route('plans.index');
    $ctaLabel = $ctaTextDynamic ?? $ctaText ?? __('profile.feature_gate_upgrade_plan');
@endphp
<div {{ $attributes->merge(['class' => 'rounded-xl border border-stone-200/90 bg-white/95 p-4 text-center shadow-sm ring-1 ring-stone-100/80 dark:border-gray-600 dark:bg-gray-800/95 dark:ring-gray-700/60 max-w-sm mx-auto']) }}>
    <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-stone-100 text-stone-600 dark:bg-gray-700 dark:text-stone-300" aria-hidden="true">
        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
        </svg>
    </div>
    @if ($title)
        <p class="text-sm font-semibold text-stone-900 dark:text-stone-50">{{ $title }}</p>
    @endif
    @if ($message !== '')
        <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ $message }}</p>
    @endif
    <a href="{{ $ctaHref }}" class="mt-3 inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
        {{ $ctaLabel }}
    </a>
</div>
