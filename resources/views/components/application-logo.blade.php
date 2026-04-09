@props(['themeAware' => false])

@php
    $fallbackSrc = asset('images/my-logo.png');
    $lightModePath = public_path('images/my-logo-light-mode.png');
    $useDual = $themeAware && is_file($lightModePath);
    $lightSrc = asset('images/my-logo-light-mode.png');
    $logoClass = $attributes->get('class', '');
@endphp

@if ($useDual)
    {{-- Light UI header: dark/colored mark. Dark UI: existing logo (typically light/gold on transparent). --}}
    <img src="{{ $lightSrc }}" alt="{{ config('app.name') }}" {{ $attributes->except('class') }} @class(['block dark:hidden', $logoClass]) />
    <img src="{{ $fallbackSrc }}" alt="{{ config('app.name') }}" {{ $attributes->except('class') }} @class(['hidden dark:block', $logoClass]) />
@else
    <img src="{{ $fallbackSrc }}" alt="{{ config('app.name') }}" {{ $attributes->merge(['class' => 'h-9 w-auto']) }} />
@endif
