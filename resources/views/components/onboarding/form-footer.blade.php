{{-- Desktop: Back left, primary right (fixed min width). Mobile: primary on top, full width. --}}
@props([
    'backUrl' => null,
    'submitLabel' => null,
    'submitExtraClass' => '',
])
@php
    $submitLabel = $submitLabel ?? __('onboarding.continue');
    $rowAlign = $backUrl ? 'sm:justify-between' : 'sm:justify-end';
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-col-reverse sm:flex-row sm:items-center gap-3 pt-6 mt-1 border-t border-gray-200 dark:border-gray-600 '.$rowAlign]) }}>
    @if ($backUrl)
        <a href="{{ $backUrl }}"
            class="onboarding-back-btn inline-flex justify-center items-center min-h-[56px] px-6 rounded-2xl text-base font-semibold text-gray-800 dark:text-gray-100 border border-gray-300/90 dark:border-gray-500 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 w-full sm:w-auto sm:min-w-[9.5rem] shadow-sm shadow-gray-900/[0.06] dark:shadow-lg dark:shadow-black/30 transition-all duration-200 hover:shadow-md active:scale-[0.99]">
            {{ __('onboarding.back') }}
        </a>
    @endif
    <button type="submit"
        class="onboarding-primary-btn inline-flex justify-center items-center min-h-[56px] px-10 rounded-2xl text-base font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 border border-indigo-500/40 shadow-md shadow-indigo-500/25 dark:shadow-indigo-900/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900 w-full sm:w-auto sm:min-w-[13.5rem] transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 {{ $submitExtraClass }}">
        {{ $submitLabel }}
    </button>
</div>
