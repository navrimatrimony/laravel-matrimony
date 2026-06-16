<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $siteIdentity = app(\App\Services\SiteIdentityService::class);
            $siteName = $siteIdentity->get('site_name', 'नवरी मिळे नवऱ्याला');
            $siteTagline = $siteIdentity->get('site_tagline', 'Navri Mile Navryala | Marathi Matrimony');
            $fallbackHeroImage = file_exists(public_path('images/homepage/hero_1779797852.jpg'))
                ? asset('images/homepage/hero_1779797852.jpg')
                : asset('images/matrimonial-hero.jpg');
            $customHeroImagePath = trim((string) ($suchakHeroImagePath ?? ''));
            $heroImage = $customHeroImagePath !== ''
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($customHeroImagePath)
                : $fallbackHeroImage;
            $isMarathiLocale = str_starts_with((string) app()->getLocale(), 'mr');
            $fontClass = $isMarathiLocale ? 'font-devanagari' : '';
            $homepageCopyDefaults = \App\Modules\Suchak\Services\SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_COPY;
            $homepageStyleDefaults = \App\Modules\Suchak\Services\SuchakPolicyService::DEFAULT_SUCHAK_HOMEPAGE_STYLE;
            $homepageCopy = array_replace_recursive(
                $homepageCopyDefaults,
                is_array($suchakHomepageCopy ?? null) ? $suchakHomepageCopy : [],
            );
            $homepageStyle = array_replace(
                $homepageStyleDefaults,
                is_array($suchakHomepageStyle ?? null) ? $suchakHomepageStyle : [],
            );
            $localeKey = $isMarathiLocale ? 'mr' : 'en';
            $copy = array_replace(
                $homepageCopyDefaults[$localeKey],
                is_array($homepageCopy[$localeKey] ?? null) ? $homepageCopy[$localeKey] : [],
            );
            $primaryUrl = $suchakAccount
                ? route('suchak.dashboard')
                : route('suchak.register.info');
            $primaryLabel = $suchakAccount
                ? (string) ($copy['dashboard_cta'] ?? $homepageCopyDefaults[$localeKey]['dashboard_cta'])
                : (string) ($copy['primary_cta'] ?? $homepageCopyDefaults[$localeKey]['primary_cta']);
            $showLoginPanel = request('mode') === 'login'
                || (old('login') !== null && old('suchak_name') === null);

            $benefits = collect($homepageCopy['benefits'] ?? $homepageCopyDefaults['benefits'])
                ->map(fn (array $benefit): array => [
                    'title' => (string) ($benefit['title_'.$localeKey] ?? ''),
                    'body' => (string) ($benefit['body_'.$localeKey] ?? ''),
                ])
                ->filter(fn (array $benefit): bool => $benefit['title'] !== '' || $benefit['body'] !== '')
                ->values()
                ->all();
            $process = collect($homepageCopy['process_steps'] ?? $homepageCopyDefaults['process_steps'])
                ->map(fn (array $step): string => (string) ($step['label_'.$localeKey] ?? ''))
                ->filter()
                ->values()
                ->all();
            $tools = collect($homepageCopy['tools'] ?? $homepageCopyDefaults['tools'])
                ->map(fn (array $tool): string => (string) ($tool['label_'.$localeKey] ?? ''))
                ->filter()
                ->values()
                ->all();
            $hexToRgb = function (string $hex): array {
                $hex = ltrim($hex, '#');

                return [
                    hexdec(substr($hex, 0, 2)),
                    hexdec(substr($hex, 2, 2)),
                    hexdec(substr($hex, 4, 2)),
                ];
            };
            $overlayRgb = implode(', ', $hexToRgb((string) $homepageStyle['overlay_color']));
            $desktopOverlayOpacity = number_format(max(20, min(100, (int) $homepageStyle['desktop_overlay_opacity'])) / 100, 2, '.', '');
            $mobileOverlayOpacity = number_format(max(20, min(100, (int) $homepageStyle['mobile_overlay_opacity'])) / 100, 2, '.', '');
            $formCardOpacity = number_format(max(60, min(100, (int) $homepageStyle['form_card_opacity'])) / 100, 2, '.', '');
            $formShadow = ($homepageStyle['form_shadow_enabled'] ?? true)
                ? '0 24px 48px rgba(127, 29, 29, .18)'
                : 'none';
        @endphp

        <title>{{ $copy['title'] }} - {{ $siteName }}</title>
        @include('layouts.partials.site-identity-head')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700,800&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/profile/location-typeahead.js'])

        <style>
            :root {
                --suchak-red: {{ $homepageStyle['primary_color'] }};
                --suchak-red-dark: {{ $homepageStyle['primary_dark_color'] }};
                --suchak-ink: {{ $homepageStyle['ink_color'] }};
                --suchak-page-bg: {{ $homepageStyle['page_background_color'] }};
                --suchak-hero-bg: {{ $homepageStyle['hero_background_color'] }};
                --suchak-overlay-rgb: {{ $overlayRgb }};
                --suchak-desktop-overlay-opacity: {{ $desktopOverlayOpacity }};
                --suchak-mobile-overlay-opacity: {{ $mobileOverlayOpacity }};
                --suchak-hero-position-desktop: {{ $homepageStyle['image_position_desktop'] }};
                --suchak-hero-position-mobile: {{ $homepageStyle['image_position_mobile'] }};
                --suchak-hero-height-desktop: {{ (int) $homepageStyle['hero_min_height_desktop'] }}vh;
                --suchak-hero-height-mobile: {{ (int) $homepageStyle['hero_min_height_mobile'] }}vh;
                --suchak-hero-blur: {{ (int) $homepageStyle['hero_blur_px'] }}px;
                --suchak-bottom-fade-display: {{ ($homepageStyle['bottom_fade_enabled'] ?? true) ? 'block' : 'none' }};
                --suchak-bottom-fade-height: {{ (int) $homepageStyle['bottom_fade_height_rem'] }}rem;
                --suchak-form-card-opacity: {{ $formCardOpacity }};
                --suchak-form-shadow: {{ $formShadow }};
            }
            .font-devanagari { font-family: 'Noto Sans Devanagari', 'Instrument Sans', sans-serif; }
            .suchak-page { background: var(--suchak-page-bg); color: var(--suchak-ink); }
            .suchak-language {
                position: fixed;
                right: 1rem;
                top: 1rem;
                z-index: 40;
                max-width: calc(100vw - 2rem);
            }
            .suchak-primary {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: .375rem;
                background: var(--suchak-red);
                color: #fff;
                font-size: .875rem;
                font-weight: 700;
                padding: .75rem 1.5rem;
                box-shadow: 0 16px 32px rgba(127, 29, 29, .20);
                transition: background-color .15s ease, transform .15s ease;
            }
            .suchak-primary:hover { background: var(--suchak-red-dark); }
            .suchak-hero {
                position: relative;
                min-height: var(--suchak-hero-height-desktop);
                overflow: hidden;
                background: var(--suchak-hero-bg);
            }
            .suchak-hero::before {
                content: "";
                position: absolute;
                inset: -12px;
                background-image:
                    linear-gradient(90deg, rgba(var(--suchak-overlay-rgb), var(--suchak-desktop-overlay-opacity)) 0%, rgba(var(--suchak-overlay-rgb), calc(var(--suchak-desktop-overlay-opacity) * .94)) 33%, rgba(var(--suchak-overlay-rgb), calc(var(--suchak-desktop-overlay-opacity) * .48)) 57%, rgba(45, 20, 18, .14) 100%),
                    var(--suchak-hero-image);
                background-position: var(--suchak-hero-position-desktop);
                background-size: cover;
                filter: blur(var(--suchak-hero-blur));
            }
            .suchak-hero::after {
                content: "";
                display: var(--suchak-bottom-fade-display);
                position: absolute;
                inset: auto 0 0 0;
                height: var(--suchak-bottom-fade-height);
                background: linear-gradient(180deg, rgba(255,255,255,0), var(--suchak-page-bg));
            }
            .suchak-hero-inner {
                position: relative;
                z-index: 10;
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(22rem, 30rem);
                gap: 2rem;
                align-items: center;
                min-height: 84vh;
                max-width: 80rem;
                margin: 0 auto;
                padding: 5rem 1rem;
            }
            .suchak-copy { max-width: 48rem; }
            .suchak-hero-form {
                border: 1px solid rgba(254, 202, 202, .85);
                border-radius: .5rem;
                background: rgba(255, 255, 255, var(--suchak-form-card-opacity));
                box-shadow: var(--suchak-form-shadow);
                padding: 1.25rem;
            }
            .suchak-hero-form-title {
                color: #111827;
                font-size: 1.1rem;
                font-weight: 800;
                line-height: 1.3;
            }
            .suchak-hero-form-body {
                color: #4b5563;
                font-size: .82rem;
                line-height: 1.6;
                margin-top: .35rem;
            }
            .suchak-eyebrow {
                display: inline-flex;
                border: 1px solid #fecaca;
                border-radius: 9999px;
                background: rgba(255,255,255,.82);
                color: #991b1b;
                font-size: .75rem;
                font-weight: 800;
                letter-spacing: .025em;
                line-height: 1;
                padding: .45rem .8rem;
                text-transform: uppercase;
                box-shadow: 0 1px 4px rgba(127, 29, 29, .08);
            }
            .suchak-title {
                max-width: 46rem;
                margin-top: 1.25rem;
                color: #09090b;
                font-size: 3.7rem;
                font-weight: 800;
                letter-spacing: 0;
                line-height: 1.03;
                overflow-wrap: break-word;
            }
            .suchak-subtitle {
                max-width: 42rem;
                margin-top: 1.25rem;
                color: #3f3f46;
                font-size: 1.125rem;
                font-weight: 600;
                line-height: 1.75;
            }
            .suchak-actions {
                display: flex;
                flex-wrap: wrap;
                gap: .75rem;
                margin-top: 2rem;
            }
            .suchak-secondary {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid #fecaca;
                border-radius: .375rem;
                background: rgba(255,255,255,.86);
                color: #991b1b;
                font-size: .875rem;
                font-weight: 700;
                padding: .75rem 1.5rem;
                transition: background-color .15s ease;
            }
            .suchak-secondary:hover { background: #fff; }
            .suchak-trust {
                max-width: 42rem;
                margin-top: 1.5rem;
                color: #3f3f46;
                font-size: .875rem;
                line-height: 1.85;
            }
            .suchak-auth-panel[hidden] {
                display: none;
            }
            .suchak-auth-links {
                margin-top: 1rem;
                border-top: 1px solid #fee2e2;
                padding-top: .9rem;
                text-align: center;
                color: #4b5563;
                font-size: .82rem;
                line-height: 1.6;
            }
            .suchak-auth-link {
                color: #991b1b;
                font-weight: 800;
                text-decoration: underline;
                text-underline-offset: 3px;
            }
            .suchak-auth-link:hover {
                color: #7f1d1d;
            }
            .suchak-auth-separator {
                margin: 0 .45rem;
                color: #d1d5db;
            }
            @media (max-width: 767px) {
                .suchak-language {
                    left: .75rem;
                    right: auto;
                    top: .65rem;
                    transform: scale(.94);
                    transform-origin: top left;
                }
                .suchak-hero {
                    min-height: var(--suchak-hero-height-mobile);
                }
                .suchak-hero-inner {
                    grid-template-columns: 1fr;
                    min-height: var(--suchak-hero-height-mobile);
                    padding: 4.25rem 1rem 3rem;
                }
                .suchak-copy {
                    max-width: 21.5rem;
                }
                .suchak-hero::before {
                    background-image:
                        linear-gradient(180deg, rgba(var(--suchak-overlay-rgb), var(--suchak-mobile-overlay-opacity)) 0%, rgba(var(--suchak-overlay-rgb), calc(var(--suchak-mobile-overlay-opacity) * .92)) 48%, rgba(var(--suchak-overlay-rgb), calc(var(--suchak-mobile-overlay-opacity) * .24)) 100%),
                        var(--suchak-hero-image);
                    background-position: var(--suchak-hero-position-mobile);
                }
                .suchak-title {
                    max-width: 100%;
                    font-size: 1.8rem;
                    line-height: 1.2;
                }
                .suchak-subtitle {
                    max-width: 100%;
                    font-size: .98rem;
                    line-height: 1.75;
                }
                .suchak-actions { flex-direction: column; }
                .suchak-primary,
                .suchak-secondary {
                    width: 100%;
                }
                .suchak-trust {
                    max-width: 100%;
                    line-height: 1.75;
                }
                .suchak-hero-form {
                    padding: 1rem;
                }
            }
            @media (min-width: 768px) and (max-width: 1023px) {
                .suchak-hero-inner {
                    grid-template-columns: 1fr;
                }
                .suchak-hero-form {
                    max-width: 42rem;
                }
            }
        </style>
    </head>
    <body class="suchak-page min-h-screen antialiased">
        <div class="suchak-language">
            <x-language-switcher :on-red="false" />
        </div>

        <main>
            <section class="suchak-hero" style="--suchak-hero-image: url('{{ $heroImage }}');">
                <img src="{{ $heroImage }}" alt="" class="hidden" onerror="this.closest('.suchak-hero').classList.add('bg-red-50');">
                <div class="suchak-hero-inner">
                    <div class="suchak-copy">
                        <p class="suchak-eyebrow">
                            {{ $copy['eyebrow'] }}
                        </p>
                        <h1 class="{{ $fontClass }} suchak-title">
                            {{ $copy['title'] }}
                        </h1>
                        <p class="{{ $fontClass }} suchak-subtitle">
                            {{ $copy['subtitle'] }}
                        </p>

                        <div class="suchak-actions">
                            @if (! ($showHeroRegistrationForm ?? true) || $suchakAccount || auth()->check())
                                <a href="{{ $primaryUrl }}" class="suchak-primary">
                                    {{ $primaryLabel }}
                                </a>
                            @endif
                            @if (($showHeroRegistrationForm ?? true) && ! $suchakAccount && ! auth()->check())
                                <a href="#how-it-works" class="suchak-secondary">
                                    {{ $copy['secondary_cta'] }}
                                </a>
                            @elseif (! ($showHeroRegistrationForm ?? true))
                                {{-- Admin disabled the hero form; keep the hero action to a single CTA. --}}
                            @else
                                <a href="#how-it-works" class="suchak-secondary">
                                    {{ $copy['secondary_cta'] }}
                                </a>
                            @endif
                        </div>

                        <p class="{{ $fontClass }} suchak-trust">
                            {{ $copy['trust'] }}
                        </p>
                    </div>

                    @if (($showHeroRegistrationForm ?? true) && ! $suchakAccount && ! auth()->check())
                        <aside class="suchak-hero-form" data-suchak-auth-card>

                            @if ($errors->any())
                                <div class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-xs leading-5 text-red-800">
                                    <ul class="list-disc space-y-1 pl-4">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <div class="suchak-auth-panel" data-suchak-auth-panel="register" @if ($showLoginPanel) hidden @endif>
                                <h2 class="{{ $fontClass }} suchak-hero-form-title">{{ $copy['hero_form_title'] }}</h2>
                                <p class="{{ $fontClass }} suchak-hero-form-body">{{ $copy['hero_form_body'] }}</p>

                                <form method="POST" action="{{ route('suchak.register.store') }}" data-suchak-registration-form class="mt-4 space-y-4">
                                    @csrf
                                    @include('suchak.partials.registration-fields', [
                                        'businessTypes' => $businessTypes,
                                        'fieldIdPrefix' => 'hero_suchak_',
                                        'gridClass' => 'grid gap-3',
                                        'fieldClass' => 'mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500',
                                        'labelClass' => 'block text-xs font-bold text-gray-700',
                                        'helpClass' => 'mt-1 text-[11px] leading-4 text-gray-500',
                                    ])
                                    <button type="submit" class="suchak-primary w-full">
                                        {{ __('suchak.register.submit') }}
                                    </button>
                                </form>

                                <div class="{{ $fontClass }} suchak-auth-links">
                                    <span>{{ __('suchak.register.already_have_account') }}</span>
                                    <a href="#suchak-login" class="suchak-auth-link" data-suchak-auth-toggle="login">
                                        {{ __('suchak.register.login_here') }}
                                    </a>
                                </div>
                            </div>

                            <div class="suchak-auth-panel" data-suchak-auth-panel="login" @if (! $showLoginPanel) hidden @endif>
                                <h2 class="{{ $fontClass }} suchak-hero-form-title">{{ __('suchak.register.login_title') }}</h2>
                                <p class="{{ $fontClass }} suchak-hero-form-body">{{ __('suchak.register.login_intro') }}</p>

                                <form id="suchak-login-form" method="POST" action="{{ route('login') }}" class="mt-4 space-y-4">
                                    @csrf
                                    <div>
                                        <label for="suchak_login" class="block text-xs font-bold text-gray-700">{{ __('suchak.register.login_identifier') }}</label>
                                        <input
                                            id="suchak_login"
                                            name="login"
                                            value="{{ old('login') }}"
                                            type="text"
                                            required
                                            autocomplete="username"
                                            placeholder="{{ __('suchak.register.login_identifier_placeholder') }}"
                                            class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                        >
                                        <p class="mt-1 text-[11px] leading-4 text-gray-500">{{ __('suchak.register.login_identifier_help') }}</p>
                                    </div>

                                    <div>
                                        <label for="suchak_login_password" class="block text-xs font-bold text-gray-700">{{ __('suchak.register.password') }}</label>
                                        <input
                                            id="suchak_login_password"
                                            name="password"
                                            type="password"
                                            required
                                            autocomplete="current-password"
                                            class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-500 focus:ring-red-500"
                                        >
                                    </div>

                                    <label for="suchak_login_remember" class="inline-flex items-center gap-2 text-xs font-semibold text-gray-600">
                                        <input id="suchak_login_remember" type="checkbox" name="remember" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                        <span>{{ __('Remember me') }}</span>
                                    </label>

                                    <button type="submit" class="suchak-primary w-full">
                                        {{ __('suchak.register.login_submit') }}
                                    </button>
                                </form>

                                <div class="{{ $fontClass }} suchak-auth-links">
                                    @if (Route::has('password.request'))
                                        <a href="{{ route('password.request') }}" class="suchak-auth-link">
                                            {{ __('suchak.register.forgot_password') }}
                                        </a>
                                        <span class="suchak-auth-separator" aria-hidden="true">|</span>
                                    @endif
                                    <a href="#suchak-register" class="suchak-auth-link" data-suchak-auth-toggle="register">
                                        {{ __('suchak.register.new_suchak_register') }}
                                    </a>
                                </div>
                            </div>
                        </aside>
                    @endif
                </div>
            </section>

            @if (session('status') || session('info') || session('error'))
                <div class="mx-auto mt-6 max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm">
                        {{ session('status') ?: session('info') ?: session('error') }}
                    </div>
                </div>
            @endif

            <section class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-[0.8fr_1.2fr] lg:px-8">
                <div>
                    <p class="text-sm font-bold uppercase tracking-wide text-red-700">{{ $copy['benefits_title'] }}</p>
                    @if (! empty($copy['benefits_intro']))
                        <p class="{{ $fontClass }} mt-2 text-sm leading-6 text-zinc-600">
                            {{ $copy['benefits_intro'] }}
                        </p>
                    @endif
                    <h2 class="{{ $fontClass }} mt-3 text-3xl font-extrabold text-zinc-950">
                        {{ $copy['business_title'] }}
                    </h2>
                    <p class="{{ $fontClass }} mt-4 text-sm leading-7 text-zinc-600">
                        {{ $copy['business_body'] }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($benefits as $benefit)
                        <article class="rounded-lg border border-red-100 bg-white p-5 shadow-sm">
                            <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-red-50 text-red-700">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l2.25 2.25L15.75 9M12 3.75l7.5 3v5.25c0 4.47-3.06 8.43-7.5 9.5-4.44-1.07-7.5-5.03-7.5-9.5V6.75l7.5-3z" />
                                </svg>
                            </div>
                            <h3 class="{{ $fontClass }} text-base font-bold text-zinc-950">{{ $benefit['title'] }}</h3>
                            <p class="{{ $fontClass }} mt-2 text-sm leading-6 text-zinc-600">{{ $benefit['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section id="how-it-works" class="bg-white py-12">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                        <div>
                            <p class="text-sm font-bold uppercase tracking-wide text-red-700">{{ $copy['process_title'] }}</p>
                            <h2 class="{{ $fontClass }} mt-3 text-3xl font-extrabold text-zinc-950">{{ $copy['final_title'] }}</h2>
                        </div>
                        <a href="{{ $primaryUrl }}" class="suchak-primary w-fit px-5 py-2.5">
                            {{ $primaryLabel }}
                        </a>
                    </div>

                    <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($process as $index => $step)
                            <li class="rounded-lg border border-zinc-200 bg-zinc-50 p-5">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-red-700 text-sm font-bold text-white">{{ $index + 1 }}</span>
                                <p class="{{ $fontClass }} mt-4 text-sm font-bold text-zinc-900">{{ $step }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-zinc-950 p-6 text-white shadow-xl sm:p-8">
                    <div class="grid gap-8 lg:grid-cols-[0.85fr_1.15fr] lg:items-center">
                        <div>
                            <p class="text-sm font-bold uppercase tracking-wide text-red-200">{{ $copy['tools_title'] }}</p>
                            <h2 class="{{ $fontClass }} mt-3 text-2xl font-extrabold">{{ $copy['final_body'] }}</h2>
                            @auth
                                <a href="{{ route('suchak.register.status') }}" class="mt-5 inline-flex rounded-md border border-white/30 px-4 py-2 text-sm font-bold text-white hover:bg-white/10">
                                    {{ $copy['status_cta'] }}
                                </a>
                            @endauth
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($tools as $tool)
                                <div class="rounded-md border border-white/10 bg-white/10 px-4 py-3 text-sm font-semibold text-white">
                                    {{ $tool }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>
        </main>
        <script>
            (function () {
                var card = document.querySelector('[data-suchak-auth-card]');
                if (!card) {
                    return;
                }

                var panels = card.querySelectorAll('[data-suchak-auth-panel]');
                var toggles = card.querySelectorAll('[data-suchak-auth-toggle]');

                var showPanel = function (name, shouldFocus) {
                    panels.forEach(function (panel) {
                        panel.hidden = panel.getAttribute('data-suchak-auth-panel') !== name;
                    });

                    if (shouldFocus) {
                        var focusTarget = card.querySelector(
                            name === 'login' ? '#suchak_login' : '#hero_suchak_suchak_name'
                        );
                        if (focusTarget) {
                            focusTarget.focus({ preventScroll: true });
                        }
                    }
                };

                toggles.forEach(function (toggle) {
                    toggle.addEventListener('click', function (event) {
                        event.preventDefault();
                        showPanel(toggle.getAttribute('data-suchak-auth-toggle'), true);
                    });
                });

                if (window.location.hash === '#suchak-login') {
                    showPanel('login', false);
                }

                card.querySelectorAll('form').forEach(function (form) {
                    form.addEventListener('submit', function () {
                        var button = form.querySelector('button[type="submit"]');
                        if (!button || button.dataset.once) {
                            return;
                        }

                        button.dataset.once = '1';
                        button.disabled = true;
                    });
                });
            })();
        </script>
    </body>
</html>
