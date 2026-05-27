@php
    $locale = app()->getLocale();
    $isMr = str_starts_with((string) $locale, 'mr');
    $urlEn = request()->fullUrlWithQuery(['locale' => 'en']);
    $urlMr = request()->fullUrlWithQuery(['locale' => 'mr']);
@endphp

<div class="nmn-lang-switch" role="group" aria-label="{{ __('homepage.language') }}">
    <span class="nmn-lang-switch-icon" aria-hidden="true">
        <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 21l5.25-11.25L21 21M3 10.5h12M3 6.75h18M3 14.25h9" />
        </svg>
    </span>
    <a href="{{ $urlEn }}" @class(['nmn-lang-switch-btn', 'is-active' => ! $isMr])" @if (! $isMr) aria-current="true" @endif>
        <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.6 9h16.8M12 3c2.2 2.4 3.5 5.5 3.5 9s-1.3 6.6-3.5 9" />
        </svg>
        <span>EN</span>
    </a>
    <a href="{{ $urlMr }}" @class(['nmn-lang-switch-btn', 'is-active' => $isMr])" @if ($isMr) aria-current="true" @endif>
        <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 6.75h15M4.5 12h15M4.5 17.25H9.75" />
        </svg>
        <span class="font-devanagari">मर</span>
    </a>
</div>
