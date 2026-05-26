@php
    $locale = app()->getLocale();
    $isMr = str_starts_with((string) $locale, 'mr');
    $urlEn = request()->fullUrlWithQuery(['locale' => 'en']);
    $urlMr = request()->fullUrlWithQuery(['locale' => 'mr']);
@endphp

<div class="nmn-lang-switch" role="group" aria-label="{{ __('homepage.language') }}">
    <a href="{{ $urlEn }}" @class(['nmn-lang-switch-btn', 'is-active' => ! $isMr]) @if (! $isMr) aria-current="true" @endif>
        <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.6 9h16.8M12 3c2.2 2.4 3.5 5.5 3.5 9s-1.3 6.6-3.5 9" />
        </svg>
        <span>English</span>
    </a>
    <a href="{{ $urlMr }}" @class(['nmn-lang-switch-btn', 'is-active' => $isMr]) @if ($isMr) aria-current="true" @endif>
        <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.6 9h16.8M12 3c2.2 2.4 3.5 5.5 3.5 9s-1.3 6.6-3.5 9" />
        </svg>
        <span class="font-devanagari">मराठी</span>
    </a>
</div>
