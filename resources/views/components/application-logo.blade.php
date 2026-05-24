@props(['themeAware' => false])

@php
    $siteIdentity = app(\App\Services\SiteIdentityService::class);
    $fallbackPath = $siteIdentity->get('logo_dark', 'images/my-logo.png') ?: 'images/my-logo.png';
    $lightPath = $siteIdentity->get('logo_light', 'images/my-logo-light-mode.png') ?: 'images/my-logo-light-mode.png';
    $fallbackSrc = asset($fallbackPath);
    $lightModePath = public_path($lightPath);
    $useDual = $themeAware && is_file($lightModePath);
    $lightSrc = asset($lightPath);
    $siteName = $siteIdentity->get('site_name', config('app.name'));
    $logoClass = $attributes->get('class', '');
@endphp

@if ($useDual)
    {{-- Light UI header: dark/colored mark. Dark UI: existing logo (typically light/gold on transparent). --}}
    <img src="{{ $lightSrc }}" alt="{{ $siteName }}" {{ $attributes->except('class') }} @class(['block dark:hidden', $logoClass]) />
    <img src="{{ $fallbackSrc }}" alt="{{ $siteName }}" {{ $attributes->except('class') }} @class(['hidden dark:block', $logoClass]) />
@else
    <img src="{{ $fallbackSrc }}" alt="{{ $siteName }}" {{ $attributes->merge(['class' => 'h-9 w-auto']) }} />
@endif
