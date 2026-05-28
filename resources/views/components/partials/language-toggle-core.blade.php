@props([
    'isMr' => false,
    'urlEn' => '#',
    'urlMr' => '#',
    'onRed' => false,
])

@php
    $wrapperClass = $onRed
        ? 'inline-flex max-w-full items-center gap-0.5 rounded-full border border-white/35 bg-white/10 p-0.5 shadow-sm'
        : 'inline-flex max-w-full items-center gap-0.5 rounded-full border border-gray-300 bg-white p-0.5 shadow-sm dark:border-gray-600 dark:bg-gray-800';

    $activeClass = $onRed
        ? 'bg-white text-red-700 shadow-sm'
        : 'bg-red-600 text-white shadow-sm dark:bg-red-500';

    $inactiveClass = $onRed
        ? 'text-white/90 hover:bg-white/10'
        : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700';

    $btnBase = 'inline-flex items-center justify-center whitespace-nowrap rounded-full px-2 py-1.5 text-[12px] font-semibold leading-none transition';
@endphp

<div class="{{ $wrapperClass }}" role="group" aria-label="{{ __('homepage.language') }}">
    <a
        href="{{ $urlEn }}"
        @class([$btnBase, $isMr ? $inactiveClass : $activeClass])
        aria-current="{{ $isMr ? 'false' : 'true' }}"
    >
        <span>English</span>
    </a>
    <a
        href="{{ $urlMr }}"
        @class([$btnBase, $isMr ? $activeClass : $inactiveClass])
        aria-current="{{ $isMr ? 'true' : 'false' }}"
    >
        <span class="font-devanagari">मराठी</span>
    </a>
</div>
