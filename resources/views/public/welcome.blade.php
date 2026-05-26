<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @php
            $siteIdentity = app(\App\Services\SiteIdentityService::class);
            $siteName = $siteIdentity->get('site_name', 'à¤¨à¤µà¤°à¥€ à¤®à¤¿à¤³à¥‡ à¤¨à¤µà¤±à¥à¤¯à¤¾à¤²à¤¾');
            $siteTagline = $siteIdentity->get('site_tagline', 'Navri Mile Navryala | Marathi Matrimony');
        @endphp
        <title>{{ $siteName }}{{ $siteTagline ? ' - '.$siteTagline : '' }}</title>
        @include('layouts.partials.site-identity-head')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
        <style>
            :root {
                --brand-red: #b91c1c;
                --brand-red-dark: #8f1515;
                --ink: #201a1a;
            }
            .font-devanagari { font-family: 'Noto Sans Devanagari', 'Instrument Sans', sans-serif; }
            .homepage-icon { width: 1.15rem; height: 1.15rem; }
            .nmn-hero {
                position: relative;
                overflow: hidden;
                min-height: calc(100vh - 84px);
                border-bottom: 1px solid #f1dede;
                background: #281313;
            }
            .nmn-hero::before {
                content: "";
                position: absolute;
                inset: 0;
                background-image:
                    linear-gradient(90deg, rgba(255,247,247,.97) 0%, rgba(255,247,247,.84) 34%, rgba(255,247,247,.22) 58%, rgba(20,8,8,.08) 100%),
                    var(--nmn-hero-bg);
                background-size: cover;
                background-position: center right;
                transform: scale(1.01);
            }
            .nmn-hero::after {
                content: "";
                position: absolute;
                inset: auto 0 0;
                height: 18%;
                background: linear-gradient(0deg, rgba(255,255,255,.62), rgba(255,255,255,0));
                pointer-events: none;
            }
            .nmn-hero-inner {
                position: relative;
                z-index: 1;
                max-width: 1560px;
                margin: 0 auto;
                padding: 44px 32px 18px;
            }
            .nmn-hero-grid {
                min-height: clamp(360px, 48vh, 455px);
                display: flex;
                align-items: center;
            }
            .nmn-hero-copy { width: min(700px, 54vw); }
            .nmn-hero-badge {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                max-width: 100%;
                border: 1px solid #f0caca;
                border-radius: 999px;
                background: rgba(255,255,255,.92);
                color: var(--brand-red);
                padding: 8px 14px;
                font-size: 14px;
                font-weight: 800;
                box-shadow: 0 8px 24px rgba(127, 29, 29, .08);
            }
            .nmn-hero-title {
                margin: 18px 0 0;
                max-width: 680px;
                font-size: clamp(34px, 3.25vw, 58px);
                line-height: 1.14;
                font-weight: 900;
                letter-spacing: 0;
                color: #0f0b0b;
            }
            .nmn-hero-subtitle {
                margin-top: 14px;
                max-width: 620px;
                font-size: 18px;
                line-height: 1.55;
                color: #3f3434;
            }
            .nmn-hero-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                margin-top: 24px;
            }
            .nmn-hero-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                min-height: 48px;
                border-radius: 8px;
                padding: 0 20px;
                font-size: 15px;
                font-weight: 850;
                text-decoration: none;
                border: 1px solid #f0b8b8;
            }
            .nmn-hero-btn.primary {
                background: var(--brand-red);
                border-color: var(--brand-red);
                color: white;
                box-shadow: 0 12px 26px rgba(185, 28, 28, .18);
            }
            .nmn-hero-btn.secondary {
                background: white;
                color: var(--brand-red);
            }
            .nmn-trust-row {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                max-width: 720px;
                margin-top: 26px;
            }
            .nmn-trust-pill {
                display: flex;
                align-items: center;
                gap: 9px;
                min-height: 46px;
                border: 1px solid #eadede;
                border-radius: 10px;
                background: rgba(255,255,255,.78);
                padding: 9px 12px;
                font-size: 13px;
                font-weight: 800;
                color: #3b3030;
            }
            .nmn-photo-fallback {
                position: absolute;
                right: 32px;
                top: 80px;
                display: none;
                align-items: center;
                justify-content: center;
                width: min(420px, calc(100% - 64px));
                min-height: 180px;
                border-radius: 12px;
                padding: 28px;
                text-align: center;
                color: var(--brand-red);
                font-weight: 800;
                background: linear-gradient(135deg, #fff1f2, #fee2e2);
            }
            .nmn-hero.is-missing .nmn-photo-fallback { display: flex; }
            .nmn-search-wrap {
                position: absolute;
                left: 0;
                right: 0;
                bottom: 24px;
                max-width: 1560px;
                margin: 0 auto;
                padding: 0 32px;
                z-index: 2;
            }
            .nmn-quick-search {
                border: 1px solid #efd1d1;
                border-radius: 12px;
                background: rgba(255,255,255,.98);
                box-shadow: 0 18px 45px rgba(75, 27, 27, .12);
                padding: 14px;
                display: grid;
                grid-template-columns: minmax(210px, 270px) minmax(0, 1fr);
                gap: 14px;
                align-items: end;
            }
            .nmn-search-head {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 0;
            }
            .nmn-search-head strong {
                display: block;
                font-size: 17px;
                color: #111;
            }
            .nmn-search-head span {
                display: block;
                margin-top: 1px;
                font-size: 12px;
                color: #696060;
            }
            .nmn-search-grid {
                display: flex;
                flex-wrap: nowrap;
                overflow-x: auto;
                gap: 10px;
                align-items: end;
                padding-bottom: 2px;
                scrollbar-width: none;
                -ms-overflow-style: none;
            }
            .nmn-search-grid::-webkit-scrollbar {
                display: none;
            }
            .nmn-field {
                flex: 1 0 138px;
            }
            .nmn-field.nmn-age-field {
                flex-basis: 230px;
            }
            .nmn-field label {
                display: block;
                margin: 0 0 6px;
                font-size: 12px;
                color: #5f5555;
                font-weight: 850;
            }
            .nmn-field input,
            .nmn-field select {
                width: 100%;
                height: 44px;
                border: 1px solid #d8c7c7;
                border-radius: 8px;
                background: white;
                padding: 0 12px;
                font-size: 14px;
                color: #1f1717;
            }
            .nmn-dual-range {
                display: flex;
                align-items: center;
                box-sizing: border-box;
                min-width: 0;
                overflow: hidden;
                border: 1px solid #d8c7c7;
                border-radius: 8px;
                background: white;
                padding: 10px 12px;
            }
            .nmn-dual-range-slider {
                position: relative;
                width: 100%;
                height: 32px;
                flex-shrink: 0;
            }
            .nmn-dual-range-track {
                position: absolute;
                left: 0;
                right: 0;
                top: 50%;
                height: 4px;
                transform: translateY(-50%);
                border-radius: 999px;
                background: #e8d8d8;
                pointer-events: none;
            }
            .nmn-dual-range-fill {
                position: absolute;
                top: 50%;
                height: 4px;
                transform: translateY(-50%);
                border-radius: 999px;
                background: var(--brand-red);
                pointer-events: none;
            }
            .nmn-dual-range input[type="range"] {
                position: absolute;
                inset: 0;
                z-index: 6;
                width: 100%;
                height: 32px;
                margin: 0;
                border: 0;
                padding: 0;
                background: transparent;
                pointer-events: none;
                appearance: none;
                -webkit-appearance: none;
            }
            .nmn-dual-range input[type="range"]::-webkit-slider-thumb {
                -webkit-appearance: none;
                appearance: none;
                width: 28px;
                height: 28px;
                margin-top: -12px;
                border: 0;
                border-radius: 50%;
                background: transparent;
                box-shadow: none;
                cursor: grab;
                pointer-events: auto;
            }
            .nmn-dual-range input[type="range"]::-moz-range-thumb {
                width: 28px;
                height: 28px;
                border: 0;
                border-radius: 50%;
                background: transparent;
                box-shadow: none;
                cursor: grab;
                pointer-events: auto;
            }
            .nmn-dual-range-thumb {
                position: absolute;
                top: 50%;
                z-index: 4;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 28px;
                height: 28px;
                border: 2px solid #fff;
                border-radius: 50%;
                background: var(--brand-red);
                box-shadow: 0 1px 3px rgba(127, 29, 29, .22);
                color: #fff;
                font-size: 10px;
                font-weight: 900;
                line-height: 1;
                font-variant-numeric: tabular-nums;
                pointer-events: none;
                transform: translate(-50%, -50%);
            }
            .nmn-dual-range-thumb-max {
                z-index: 5;
            }
            .nmn-dual-range input[type="range"]::-webkit-slider-runnable-track {
                height: 4px;
                background: transparent;
                -webkit-appearance: none;
                appearance: none;
            }
            .nmn-dual-range input[type="range"]::-moz-range-track {
                height: 4px;
                background: transparent;
            }
            .nmn-success-slider {
                position: relative;
            }
            .nmn-success-slider-viewport {
                overflow: hidden;
            }
            .nmn-success-slider-track {
                display: flex;
                gap: 1rem;
                will-change: transform;
                transition: transform 0.45s ease;
            }
            .nmn-success-slider-slide {
                flex: 0 0 auto;
                box-sizing: border-box;
            }
            .nmn-success-slider-nav {
                position: absolute;
                top: 50%;
                z-index: 2;
                display: flex;
                height: 2.5rem;
                width: 2.5rem;
                align-items: center;
                justify-content: center;
                border-radius: 999px;
                border: 1px solid #f0caca;
                background: rgba(255,255,255,.95);
                color: var(--brand-red);
                box-shadow: 0 4px 14px rgba(127,29,29,.12);
                transform: translateY(-50%);
                cursor: pointer;
            }
            .nmn-success-slider-nav:hover {
                background: #fff;
            }
            .nmn-success-slider-nav:disabled {
                opacity: .35;
                cursor: not-allowed;
            }
            .nmn-success-slider-nav-prev { left: -0.35rem; }
            .nmn-success-slider-nav-next { right: -0.35rem; }
            @media (min-width: 640px) {
                .nmn-success-slider-nav-prev { left: -0.75rem; }
                .nmn-success-slider-nav-next { right: -0.75rem; }
            }
            .nmn-success-slider-dots {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 0.45rem;
                margin-top: 1.25rem;
            }
            .nmn-success-slider-dot {
                height: 0.55rem;
                width: 0.55rem;
                border-radius: 999px;
                border: 0;
                background: #e7b4b4;
                cursor: pointer;
                padding: 0;
            }
            .nmn-success-slider-dot.is-active {
                width: 1.35rem;
                background: var(--brand-red);
            }
            .nmn-lang-switch {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px;
                border-radius: 999px;
                border: 1px solid #f0caca;
                background: #fff7f7;
            }
            .nmn-lang-switch-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                border-radius: 999px;
                min-height: 34px;
                padding: 0 12px;
                font-size: 12px;
                font-weight: 800;
                line-height: 1;
                color: #7f1d1d;
                text-decoration: none;
                transition: background .15s ease, color .15s ease;
            }
            .nmn-lang-switch-btn:hover {
                background: #fee2e2;
                color: var(--brand-red-dark);
            }
            .nmn-lang-switch-btn.is-active {
                background: var(--brand-red);
                color: #fff;
                box-shadow: 0 2px 8px rgba(127, 29, 29, .22);
            }
            @media (max-width: 520px) {
                .nmn-lang-switch-btn {
                    padding: 0 9px;
                    font-size: 11px;
                }
                .nmn-lang-switch-btn svg {
                    display: none;
                }
            }
            .nmn-app-section {
                border-radius: 12px;
                background: #18181b;
                color: #fff;
            }
            .nmn-app-store-badges {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                margin-top: 20px;
            }
            .nmn-app-store-badge {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                min-height: 48px;
                padding: 10px 16px;
                border-radius: 10px;
                border: 1px solid rgba(255,255,255,.22);
                background: rgba(255,255,255,.08);
                color: #fff;
                text-decoration: none;
                transition: background .15s ease, border-color .15s ease;
            }
            .nmn-app-store-badge:hover {
                background: rgba(255,255,255,.14);
                border-color: rgba(255,255,255,.35);
            }
            .nmn-app-store-badge svg {
                width: 1.5rem;
                height: 1.5rem;
                flex-shrink: 0;
            }
            .nmn-app-store-badge span {
                display: block;
                font-size: 11px;
                line-height: 1.2;
                opacity: .85;
            }
            .nmn-app-store-badge strong {
                display: block;
                font-size: 14px;
                line-height: 1.25;
            }
            .nmn-search-submit {
                flex: 0 0 138px;
                height: 44px;
                min-width: 138px;
                border: 0;
                border-radius: 8px;
                background: var(--brand-red);
                color: white;
                font-weight: 900;
                cursor: pointer;
            }
            @media (max-width: 1100px) {
                .nmn-hero::before {
                    background-image:
                        linear-gradient(90deg, rgba(255,247,247,.97) 0%, rgba(255,247,247,.82) 46%, rgba(255,247,247,.2) 100%),
                        var(--nmn-hero-bg);
                }
                .nmn-hero-copy { width: min(760px, 72vw); }
                .nmn-quick-search {
                    display: flex;
                    flex-direction: column;
                    align-items: stretch;
                    grid-template-columns: 1fr;
                    gap: 12px;
                }
                .nmn-search-head {
                    width: 100%;
                    max-width: 100%;
                    margin-bottom: 0;
                }
                .nmn-search-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    flex-wrap: unset;
                    overflow: visible;
                    width: 100%;
                    max-width: 100%;
                    gap: 10px;
                    align-items: end;
                }
                .nmn-field {
                    flex: none;
                    width: auto;
                    min-width: 0;
                }
                .nmn-field.nmn-age-field,
                .nmn-search-submit {
                    grid-column: 1 / -1;
                }
                .nmn-search-submit { width: 100%; min-width: 0; }
            }
            @media (max-width: 700px) {
                .nmn-hero {
                    min-height: calc(100dvh - 64px);
                    min-height: calc(100svh - 64px);
                    max-height: calc(100dvh - 64px);
                    max-height: calc(100svh - 64px);
                    overflow: hidden;
                    display: flex;
                    flex-direction: column;
                    border-bottom: 0;
                }
                .nmn-hero::before {
                    background-image:
                        linear-gradient(180deg, rgba(255,247,247,.98) 0%, rgba(255,247,247,.9) 38%, rgba(255,247,247,.55) 68%, rgba(255,247,247,.28) 100%),
                        var(--nmn-hero-bg);
                    background-position: center bottom;
                }
                .nmn-hero::after {
                    height: 22%;
                }
                .nmn-hero-inner {
                    padding: 20px 16px 0;
                    flex: 1 1 auto;
                    min-height: 0;
                    overflow-y: auto;
                    -webkit-overflow-scrolling: touch;
                    scrollbar-width: none;
                }
                .nmn-hero-inner::-webkit-scrollbar {
                    display: none;
                }
                .nmn-hero-grid { min-height: auto; }
                .nmn-hero-copy { width: 100%; }
                .nmn-hero-badge {
                    padding: 6px 12px;
                    font-size: 13px;
                }
                .nmn-hero-title {
                    margin-top: 12px;
                    font-size: clamp(26px, 7.2vw, 34px);
                    line-height: 1.12;
                }
                .nmn-hero-subtitle {
                    margin-top: 8px;
                    font-size: 15px;
                    line-height: 1.45;
                }
                .nmn-hero-actions {
                    flex-direction: row;
                    flex-wrap: wrap;
                    gap: 8px;
                    margin-top: 14px;
                }
                .nmn-hero-btn {
                    flex: 1 1 calc(50% - 4px);
                    width: auto;
                    min-width: 0;
                    min-height: 44px;
                    padding: 0 12px;
                    font-size: 14px;
                }
                .nmn-trust-row {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 6px;
                    max-width: none;
                    margin-top: 12px;
                    overflow: visible;
                    padding-bottom: 0;
                }
                .nmn-trust-pill {
                    flex: none;
                    min-width: 0;
                    min-height: 36px;
                    padding: 6px 7px;
                    font-size: 10px;
                    line-height: 1.2;
                    white-space: normal;
                }
                .nmn-trust-pill .homepage-icon {
                    width: 0.9rem;
                    height: 0.9rem;
                    flex-shrink: 0;
                }
                .nmn-search-wrap {
                    position: static;
                    width: 100%;
                    max-width: none;
                    margin-top: auto;
                    flex-shrink: 0;
                    padding: 8px 16px calc(14px + env(safe-area-inset-bottom, 0px));
                    z-index: 2;
                }
                .nmn-quick-search {
                    display: flex;
                    flex-direction: column;
                    align-items: stretch;
                    gap: 14px;
                    grid-template-columns: 1fr;
                    width: 100%;
                    padding: 16px 14px;
                }
                .nmn-search-head {
                    width: 100%;
                    max-width: 100%;
                    margin-bottom: 0;
                    gap: 10px;
                }
                .nmn-search-head .inline-flex {
                    width: 2.25rem;
                    height: 2.25rem;
                }
                .nmn-search-head strong {
                    font-size: 16px;
                    line-height: 1.25;
                }
                .nmn-search-head span {
                    display: block;
                    margin-top: 2px;
                    font-size: 12px;
                    line-height: 1.35;
                }
                .nmn-search-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    flex-wrap: unset;
                    align-items: end;
                    gap: 12px;
                    overflow: visible;
                    width: 100%;
                    max-width: 100%;
                    padding-bottom: 0;
                }
                .nmn-field {
                    flex: none;
                    width: auto;
                    min-width: 0;
                }
                .nmn-field label {
                    margin-bottom: 6px;
                    font-size: 12px;
                }
                .nmn-field input,
                .nmn-field select {
                    height: 44px;
                    padding: 0 12px;
                    font-size: 14px;
                }
                .nmn-field.nmn-age-field,
                .nmn-search-submit {
                    grid-column: 1 / -1;
                }
                .nmn-dual-range {
                    padding: 12px;
                }
                .nmn-dual-range-slider {
                    height: 34px;
                }
                .nmn-dual-range input[type="range"] {
                    height: 34px;
                }
                .nmn-dual-range-thumb {
                    width: 30px;
                    height: 30px;
                    font-size: 11px;
                }
                .nmn-search-submit {
                    height: 48px;
                    margin-top: 2px;
                    font-size: 15px;
                }
            }
        </style>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <style>
                *,::before,::after{box-sizing:border-box} body{margin:0;font-family:system-ui,sans-serif;line-height:1.5}
            </style>
        @endif
    </head>
    <body class="min-h-screen bg-[#fbfaf9] text-[var(--ink)] antialiased dark:bg-[#101010] dark:text-[#f4f4f5]">
        @php
            $castes = $castes ?? collect();
            $genders = $genders ?? collect();
            $religions = $religions ?? collect();
            $addressStates = $addressStates ?? collect();
            $addressDistricts = $addressDistricts ?? collect();
            $maritalStatuses = $maritalStatuses ?? collect();
            $successStories = $successStories ?? collect();
            $homepagePlans = $homepagePlans ?? collect();
            $homepageStats = $homepageStats ?? ['profiles' => 0, 'success_stories' => 0, 'plans' => 0];
            $defaultCountry = $defaultCountry ?? null;
            $homepageImages = $homepageImages ?? [];
            $homepageSettings = $homepageSettings ?? app(\App\Services\Admin\HomepageContentService::class)->settings();
            $siteIdentitySettings = $siteIdentity->all();
            $logoLightUrl = $siteIdentity->assetUrl('logo_light');
            $logoDarkUrl = $siteIdentity->assetUrl('logo_dark');
            $heroPath = ! empty($homepageImages['hero'] ?? null) ? $homepageImages['hero'] : 'images/matrimonial-hero.jpg';
            $assistedPath = $homepageImages['assisted_service'] ?? null;
            $appPath = $homepageImages['app_section'] ?? null;
            $successStoriesDisplay = ($homepageSettings['success_stories_display'] ?? 'slider') === 'slider' ? 'slider' : 'grid';
            $successStoriesAutoplay = filter_var($homepageSettings['success_stories_autoplay'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $successStoriesAutoplayMs = max(2000, (int) ($homepageSettings['success_stories_autoplay_seconds'] ?? 5) * 1000);
            $successStoriesSlidesMobile = max(1, min(2, (int) ($homepageSettings['success_stories_slides_mobile'] ?? 1)));
            $successStoriesSlidesTablet = max(1, min(3, (int) ($homepageSettings['success_stories_slides_tablet'] ?? 2)));
            $successStoriesSlidesDesktop = max(1, min(4, (int) ($homepageSettings['success_stories_slides_desktop'] ?? 3)));
            $successStoriesShowArrows = filter_var($homepageSettings['success_stories_show_arrows'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $successStoriesShowDots = filter_var($homepageSettings['success_stories_show_dots'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $successStoriesPauseOnHover = filter_var($homepageSettings['success_stories_pause_on_hover'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $successStoriesLoop = filter_var($homepageSettings['success_stories_loop'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $retailPath = $homepageImages['retail_outlet'] ?? null;
            $sectionEnabled = fn (string $key): bool => (bool) data_get($homepageSettings, "sections.$key.enabled", true);
            $sectionOrder = collect($homepageSettings['sections'] ?? [])->map(fn ($section, $key) => ['key' => $key, 'sort_order' => (int) ($section['sort_order'] ?? 50)])->sortBy('sort_order')->pluck('key')->all();
            $searchEnabled = fn (string $key): bool => (bool) data_get($homepageSettings, "search_fields.$key", true);
            $isMarathiLocale = str_starts_with((string) app()->getLocale(), 'mr');
            $devanagariClass = $isMarathiLocale ? 'font-devanagari' : '';
            $localized = fn (string $key, string $fallbackMr, string $fallbackEn): string => (string) (
                $isMarathiLocale
                    ? ($homepageSettings[$key.'_mr'] ?? $fallbackMr)
                    : ($homepageSettings[$key.'_en'] ?? $fallbackEn)
            );
            $heroBadge = $localized('hero_badge', 'à¤µà¤¿à¤¶à¥à¤µà¤¾à¤¸à¥‚ à¤®à¤°à¤¾à¤ à¥€ à¤µà¤¿à¤µà¤¾à¤¹à¤¸à¥à¤¥à¤³', 'Trusted Marathi Matrimony');
            $heroTitle = $localized('hero_title', 'à¤¯à¥‹à¤—à¥à¤¯ à¤®à¤°à¤¾à¤ à¥€ à¤œà¥‹à¤¡à¥€à¤¦à¤¾à¤° à¤¶à¥‹à¤§à¤¾', 'Find your trusted Marathi match');
            $heroSubtitle = $localized('hero_subtitle', 'à¤•à¥à¤Ÿà¥à¤‚à¤¬à¤¾à¤šà¥à¤¯à¤¾ à¤¸à¤¹à¤­à¤¾à¤—à¤¾à¤¨à¥‡, à¤¸à¥à¤°à¤•à¥à¤·à¤¿à¤¤ à¤¸à¤‚à¤ªà¤°à¥à¤• à¤†à¤£à¤¿ à¤µà¥à¤¯à¤µà¤¸à¥à¤¥à¤¿à¤¤ à¤ªà¥à¤°à¥‹à¤«à¤¾à¤‡à¤²à¤¸à¤¹ à¤—à¤‚à¤­à¥€à¤° à¤µà¤¿à¤µà¤¾à¤¹à¤¾à¤¸à¤¾à¤ à¥€ à¤¯à¥‹à¤—à¥à¤¯ à¤¸à¥à¤¥à¤³ à¤¶à¥‹à¤§à¤¾.', 'Search serious profiles with privacy, family-first trust, and a guided matchmaking flow.');
            $primaryCta = $localized('primary_cta', 'à¤¨à¥‹à¤‚à¤¦à¤£à¥€ à¤•à¤°à¤¾', 'Register free');
            $secondaryCta = $localized('secondary_cta', 'à¤¸à¥à¤¥à¤³ à¤¶à¥‹à¤§à¤¾', 'Search profiles');
            $assistedTitle = $localized('assisted_title', 'à¤¸à¤¹à¤¾à¤¯à¥à¤¯à¤• à¤¸à¥‡à¤µà¤¾', 'Assisted Service');
            $assistedBody = $localized('assisted_body', 'à¤•à¥à¤Ÿà¥à¤‚à¤¬à¤¾à¤‚à¤¨à¤¾ à¤ªà¥à¤°à¥‹à¤«à¤¾à¤‡à¤², à¤ªà¤¸à¤‚à¤¤à¥€ à¤†à¤£à¤¿ à¤¸à¤‚à¤µà¤¾à¤¦ à¤¯à¤¾à¤®à¤§à¥à¤¯à¥‡ à¤¸à¤‚à¤¯à¤®à¥€ à¤®à¤¦à¤¤.', 'Support for families that want a guided, matrimony-focused experience.');
            $successTitle = $localized('success_title', 'à¤¯à¤¶à¥‹à¤—à¤¾à¤¥à¤¾', 'Success Stories');
            $successIntro = $localized('success_intro', 'à¤µà¤¿à¤¶à¥à¤µà¤¾à¤¸à¤¾à¤¨à¥‡ à¤¸à¥à¤°à¥‚ à¤à¤¾à¤²à¥‡à¤²à¤¾ à¤¸à¤‚à¤µà¤¾à¤¦ à¤†à¤¯à¥à¤·à¥à¤¯à¤­à¤°à¤¾à¤šà¥à¤¯à¤¾ à¤¨à¤¾à¤¤à¥à¤¯à¤¾à¤¤ à¤¬à¤¦à¤²à¤²à¤¾.', 'Real stories can be featured here with consent and admin approval.');
            $finalCtaTitle = $localized('final_cta_title', 'à¤¯à¥‹à¤—à¥à¤¯ à¤œà¥‹à¤¡à¥€à¤¦à¤¾à¤°à¤¾à¤šà¤¾ à¤¶à¥‹à¤§ à¤¸à¥à¤°à¥‚ à¤•à¤°à¤¾', 'Ready to explore matches?');
            $finalCtaBody = $localized('final_cta_body', 'à¤ªà¥à¤°à¥‹à¤«à¤¾à¤‡à¤² à¤¤à¤¯à¤¾à¤° à¤•à¤°à¤¾ à¤•à¤¿à¤‚à¤µà¤¾ à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤¸à¥à¤¥à¤³à¥‡ à¤¶à¥‹à¤§à¤¾.', 'Create your profile or open the search flow with the same filters used inside the platform.');
            $appTitle = $localized('app_title', 'à¤®à¥‹à¤¬à¤¾à¤‡à¤² à¤…â€à¥…à¤ª', 'Download our mobile app');
            $appBody = $localized('app_body', 'Android à¤†à¤£à¤¿ iOS à¤µà¤° à¤¶à¥‹à¤§, interests à¤†à¤£à¤¿ à¤¸à¤‚à¤µà¤¾à¤¦ à¤¸à¥‹à¤ªà¥‡ à¤ à¥‡à¤µà¤¾.', 'Search profiles, manage interests, and chat on Android and iOS.');
            $appAndroidUrl = trim((string) ($homepageSettings['app_android_url'] ?? ''));
            $appIosUrl = trim((string) ($homepageSettings['app_ios_url'] ?? ''));
            $appShowAndroid = filter_var($homepageSettings['app_show_android'] ?? true, FILTER_VALIDATE_BOOLEAN) && $appAndroidUrl !== '';
            $appShowIos = filter_var($homepageSettings['app_show_ios'] ?? true, FILTER_VALIDATE_BOOLEAN) && $appIosUrl !== '';
            $appSectionHasContent = $appPath || $appShowAndroid || $appShowIos;
            $heroTrustItems = [
                __('homepage.trust_verified'),
                __('homepage.trust_privacy'),
                __('homepage.trust_family'),
            ];
            $howStepNumbers = $isMarathiLocale ? ['à¥§', 'à¥¨', 'à¥©', 'à¥ª'] : ['1', '2', '3', '4'];
            $ageControl = in_array(($homepageSettings['hero_search_age_control'] ?? 'inputs'), ['inputs', 'slider'], true)
                ? $homepageSettings['hero_search_age_control']
                : 'inputs';
            $communityMode = in_array(($homepageSettings['hero_search_community_mode'] ?? 'caste'), ['none', 'caste', 'religion_caste'], true)
                ? $homepageSettings['hero_search_community_mode']
                : 'caste';
            $locationMode = in_array(($homepageSettings['hero_search_location_mode'] ?? 'state_district'), ['none', 'state', 'state_district'], true)
                ? $homepageSettings['hero_search_location_mode']
                : 'state_district';
            $showReligion = $communityMode === 'religion_caste' && $searchEnabled('religion');
            $showCaste = in_array($communityMode, ['caste', 'religion_caste'], true) && $searchEnabled('caste');
            $showState = in_array($locationMode, ['state', 'state_district'], true) && $searchEnabled('state');
            $showDistrict = $locationMode === 'state_district' && $searchEnabled('district');
            $ageFromValue = max(18, min(80, (int) request('age_from', 18)));
            $ageToValue = max($ageFromValue, min(80, (int) request('age_to', 35)));
        @endphp

        <header class="sticky top-0 z-30 border-b border-red-100/80 bg-white/95 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/90">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <a href="{{ url('/') }}" class="flex min-w-0 items-center gap-3">
                    @if ($logoLightUrl || $logoDarkUrl)
                        <img src="{{ $logoLightUrl ?: $logoDarkUrl }}" alt="{{ $siteName }}" class="h-10 w-auto max-w-[12rem] object-contain dark:hidden">
                        <img src="{{ $logoDarkUrl ?: $logoLightUrl }}" alt="{{ $siteName }}" class="hidden h-10 w-auto max-w-[12rem] object-contain dark:block">
                    @else
                        <span class="font-devanagari text-xl font-extrabold text-[var(--brand-red)]">{{ $siteName }}</span>
                    @endif
                    @if ($siteTagline)
                        <span class="hidden max-w-xs text-sm font-semibold text-zinc-600 dark:text-zinc-300 sm:inline">{{ $siteTagline }}</span>
                    @endif
                </a>

                @if (Route::has('login'))
                    <nav class="flex items-center gap-2">
                        <x-homepage-language-switcher />
                        @auth
                            <a href="{{ route('dashboard') }}" class="rounded-md px-3 py-2 text-sm font-semibold text-[var(--brand-red)] hover:bg-red-50 dark:hover:bg-red-950/40">{{ __('homepage.dashboard') }}</a>
                            @if (Route::has('matrimony.profile.wizard'))
                                <a href="{{ route('matrimony.profile.wizard') }}" class="rounded-md bg-[var(--brand-red)] px-3 py-2 text-sm font-semibold text-white hover:bg-[var(--brand-red-dark)]">{{ __('homepage.profile_wizard') }}</a>
                            @endif
                        @else
                            <a href="{{ route('login') }}" class="rounded-md px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800">{{ __('homepage.login') }}</a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="rounded-md bg-[var(--brand-red)] px-4 py-2 text-sm font-semibold text-white hover:bg-[var(--brand-red-dark)]">{{ __('homepage.register') }}</a>
                            @endif
                        @endauth
                    </nav>
                @else
                    <x-homepage-language-switcher />
                @endif
            </div>
        </header>

        <main>
            <section class="nmn-hero" style="--nmn-hero-bg: url('{{ asset($heroPath) }}');">
                <img src="{{ asset($heroPath) }}" alt="" hidden onerror="this.closest('.nmn-hero').classList.add('is-missing');">
                <div class="nmn-hero-inner">
                    <div class="nmn-hero-grid">
                        <div class="nmn-hero-copy">
                            <div class="nmn-hero-badge">
                                <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l2.25 2.25L15.75 9M12 3.75l7.5 3v5.25c0 4.47-3.06 8.43-7.5 9.5-4.44-1.07-7.5-5.03-7.5-9.5V6.75l7.5-3z" /></svg>
                                <span>{{ $heroBadge }}</span>
                            </div>

                            <h1 class="nmn-hero-title {{ $devanagariClass }}">{{ $heroTitle }}</h1>
                            <p class="nmn-hero-subtitle {{ $devanagariClass }}">{{ $heroSubtitle }}</p>

                            <div class="nmn-hero-actions">
                                @guest
                                    @if (Route::has('register'))
                                        <a href="{{ route('register') }}" class="nmn-hero-btn primary">
                                            <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3M15 21a6 6 0 00-12 0M9 10.5a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5z" /></svg>
                                            {{ $primaryCta }}
                                        </a>
                                    @endif
                                    <a href="{{ route('matrimony.profiles.index') }}" class="nmn-hero-btn secondary">
                                        <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2m0 0A7.5 7.5 0 105.2 5.2a7.5 7.5 0 0010.6 10.6z" /></svg>
                                        {{ $secondaryCta }}
                                    </a>
                                @else
                                    @if (Route::has('matrimony.profile.wizard'))
                                        <a href="{{ route('matrimony.profile.wizard') }}" class="nmn-hero-btn primary">
                                            <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a8.25 8.25 0 1115 0" /></svg>
                                            {{ __('homepage.complete_profile') }}
                                        </a>
                                    @endif
                                    <a href="{{ route('matrimony.profiles.index') }}" class="nmn-hero-btn secondary">
                                        <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2m0 0A7.5 7.5 0 105.2 5.2a7.5 7.5 0 0010.6 10.6z" /></svg>
                                        {{ $secondaryCta }}
                                    </a>
                                @endguest
                            </div>

                            <div class="nmn-trust-row">
                                @foreach ($heroTrustItems as $trust)
                                    <div class="nmn-trust-pill">
                                        <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l2.25 2.25L15.75 9M12 3.75l7.5 3v5.25c0 4.47-3.06 8.43-7.5 9.5-4.44-1.07-7.5-5.03-7.5-9.5V6.75l7.5-3z" /></svg>
                                        <span>{{ $trust }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="nmn-photo-fallback">{{ __('homepage.hero_image_missing') }}</div>
                    </div>
                </div>

                <div class="nmn-search-wrap">
                    <form method="GET" action="{{ route('matrimony.profiles.index') }}" class="nmn-quick-search">
                        @if ($defaultCountry)
                            <input type="hidden" name="country_id" value="{{ $defaultCountry->id }}">
                        @endif

                        <div class="nmn-search-head">
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-red-100 text-[var(--brand-red)]">
                                <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z" /></svg>
                            </span>
                            <div>
                                <strong class="{{ $devanagariClass }}">{{ __('homepage.quick_search_title') }}</strong>
                                <span>{{ __('homepage.quick_search_help') }}</span>
                            </div>
                        </div>

                        <div class="nmn-search-grid">
                            @if ($searchEnabled('gender'))
                                <div class="nmn-field">
                                    <label>{{ __('homepage.looking_for') }}</label>
                                    <select name="gender_id">
                                        <option value="">{{ __('homepage.any') }}</option>
                                        @foreach ($genders as $gender)
                                            <option value="{{ $gender->id }}" @selected((string) request('gender_id') === (string) $gender->id)>{{ $gender->display_label ?? $gender->label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @if ($searchEnabled('marital_status'))
                                <div class="nmn-field">
                                    <label>{{ __('homepage.marital') }}</label>
                                    <select name="marital_status_id">
                                        <option value="">{{ __('homepage.any') }}</option>
                                        @foreach ($maritalStatuses as $status)
                                            <option value="{{ $status->id }}" @selected((string) request('marital_status_id') === (string) $status->id)>{{ $status->label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @if ($searchEnabled('age'))
                                @if ($ageControl === 'slider')
                                    <div class="nmn-field nmn-age-field">
                                        <label>{{ __('homepage.age_range') }}</label>
                                        <div class="nmn-dual-range" data-age-range>
                                            <div class="nmn-dual-range-slider">
                                                <div class="nmn-dual-range-track" aria-hidden="true"></div>
                                                <div class="nmn-dual-range-fill" data-age-fill aria-hidden="true"></div>
                                                <span class="nmn-dual-range-thumb nmn-dual-range-thumb-min" data-age-min-label>{{ $ageFromValue }}</span>
                                                <span class="nmn-dual-range-thumb nmn-dual-range-thumb-max" data-age-max-label>{{ $ageToValue }}</span>
                                                <input type="range" name="age_from" min="18" max="80" value="{{ $ageFromValue }}" aria-label="{{ __('homepage.age_from') }}" data-age-min>
                                                <input type="range" name="age_to" min="18" max="80" value="{{ $ageToValue }}" aria-label="{{ __('homepage.age_to') }}" data-age-max>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <div class="nmn-field">
                                        <label>{{ __('homepage.age_from') }}</label>
                                        <input type="number" name="age_from" min="18" max="80" placeholder="18" value="{{ request('age_from') }}">
                                    </div>
                                    <div class="nmn-field">
                                        <label>{{ __('homepage.age_to') }}</label>
                                        <input type="number" name="age_to" min="18" max="80" placeholder="35" value="{{ request('age_to') }}">
                                    </div>
                                @endif
                            @endif

                            @if ($showReligion)
                                <div class="nmn-field">
                                    <label>{{ __('homepage.religion') }}</label>
                                    <select name="religion_id">
                                        <option value="">{{ __('homepage.any') }}</option>
                                        @foreach ($religions as $religion)
                                            <option value="{{ $religion->id }}" @selected((string) request('religion_id') === (string) $religion->id)>{{ $religion->display_label ?? $religion->label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @if ($showCaste)
                                <div class="nmn-field">
                                    <label>{{ __('homepage.caste') }}</label>
                                    <select name="caste_id">
                                        <option value="">{{ __('homepage.any') }}</option>
                                        @foreach ($castes as $c)
                                            <option value="{{ $c->id }}" @selected((string) request('caste_id') === (string) $c->id)>{{ $c->display_label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @if ($showState)
                                <div class="nmn-field">
                                    <label for="welcome-search-state">{{ __('search.state') }}</label>
                                    <select id="welcome-search-state" name="state_id">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach ($addressStates as $st)
                                            <option value="{{ $st->id }}" @selected((string) request('state_id') === (string) $st->id)>{{ $st->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            @if ($showDistrict)
                                <div class="nmn-field">
                                    <label for="welcome-search-district">{{ __('search.district') }}</label>
                                    <select id="welcome-search-district" name="district_id">
                                        <option value="">{{ __('search.any') }}</option>
                                        @foreach ($addressDistricts as $d)
                                            <option value="{{ $d->id }}" @selected((string) request('district_id') === (string) $d->id)>{{ $d->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            <button type="submit" class="nmn-search-submit">
                                {{ __('homepage.search') }}
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            @foreach ($sectionOrder as $sectionKey)
                @continue(! $sectionEnabled($sectionKey))

                @if ($sectionKey === 'trust')
                    <section class="border-y border-zinc-200 bg-zinc-50 px-4 py-8 dark:border-zinc-800 dark:bg-zinc-900/50 sm:px-6">
                        <div class="mx-auto grid max-w-7xl gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ([
                                ['icon' => 'shield', 'label' => __('homepage.trust_safety_comm')],
                                ['icon' => 'check', 'label' => __('homepage.trust_profiles')],
                                ['icon' => 'users', 'label' => __('homepage.trust_family_flow')],
                                ['icon' => 'heart', 'label' => __('homepage.trust_intent')],
                            ] as $item)
                                <div class="flex items-start gap-3 rounded-lg bg-white p-4 shadow-sm dark:bg-zinc-950">
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-[var(--brand-red)] dark:bg-red-950/50">
                                        @if ($item['icon'] === 'shield')
                                            <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75l7.5 3v5.25c0 4.47-3.06 8.43-7.5 9.5-4.44-1.07-7.5-5.03-7.5-9.5V6.75l7.5-3z" /></svg>
                                        @elseif ($item['icon'] === 'check')
                                            <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l2.25 2.25L15.75 9" /></svg>
                                        @elseif ($item['icon'] === 'users')
                                            <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.09 9.09 0 003.74-.48 3 3 0 00-4.68-2.72M15 6.75a3 3 0 11-6 0 3 3 0 016 0zM4.5 20.12a7.5 7.5 0 0115 0A17.9 17.9 0 0112 21.75c-2.68 0-5.22-.58-7.5-1.63z" /></svg>
                                        @else
                                            <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
                                        @endif
                                    </span>
                                    <div>
                                        <h2 class="{{ $devanagariClass }} text-base font-bold text-zinc-950 dark:text-white">{{ $item['label'] }}</h2>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($sectionKey === 'how_it_works')
                    <section class="px-4 py-12 sm:px-6">
                        <div class="mx-auto max-w-7xl">
                            <div class="mb-7 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <h2 class="{{ $devanagariClass }} text-2xl font-extrabold text-zinc-950 dark:text-white">{{ __('homepage.how_it_works_title') }}</h2>
                                    <p class="text-sm font-semibold text-[var(--brand-red)]">{{ __('homepage.how_it_works_subtitle') }}</p>
                                </div>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ([
                                    __('homepage.how_step_1'),
                                    __('homepage.how_step_2'),
                                    __('homepage.how_step_3'),
                                    __('homepage.how_step_4'),
                                ] as $index => $stepLabel)
                                    <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                                        <span class="{{ $devanagariClass }} inline-flex h-10 w-10 items-center justify-center rounded-full bg-red-100 text-lg font-extrabold text-[var(--brand-red)] dark:bg-red-950/50">{{ $howStepNumbers[$index] ?? (string) ($index + 1) }}</span>
                                        <h3 class="{{ $devanagariClass }} mt-4 text-lg font-bold text-zinc-950 dark:text-white">{{ $stepLabel }}</h3>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif

                @if ($sectionKey === 'assisted_service')
                    <section class="border-y border-red-100 bg-white px-4 py-12 dark:border-zinc-800 dark:bg-zinc-950 sm:px-6">
                        <div class="mx-auto grid max-w-7xl gap-8 md:grid-cols-2 md:items-center">
                            <div>
                                <p class="text-sm font-bold text-[var(--brand-red)]">{{ __('homepage.assisted_kicker') }}</p>
                                <h2 class="{{ $devanagariClass }} mt-2 text-2xl font-extrabold text-zinc-950 dark:text-white">{{ $assistedTitle }}</h2>
                                <p class="{{ $devanagariClass }} mt-4 text-sm leading-8 text-zinc-700 dark:text-zinc-300">{{ $assistedBody }}</p>
                            </div>
                            @if ($assistedPath)
                                <img src="{{ asset($assistedPath) }}" alt="" class="mx-auto max-h-80 w-full rounded-lg border border-zinc-200 object-contain p-3 dark:border-zinc-800" onerror="this.style.display='none';">
                            @endif
                        </div>
                    </section>
                @endif

                @if ($sectionKey === 'success_stories' && $successStories->isNotEmpty())
                    <section id="success-stories" class="bg-red-50/70 px-4 py-12 dark:bg-red-950/10 sm:px-6">
                        <div class="mx-auto max-w-7xl">
                            <div class="mb-7 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <h2 class="{{ $devanagariClass }} text-2xl font-extrabold text-zinc-950 dark:text-white">{{ $successTitle }}</h2>
                                    <p class="{{ $devanagariClass }} mt-2 max-w-3xl text-sm leading-7 text-zinc-700 dark:text-zinc-300">{{ $successIntro }}</p>
                                </div>
                            </div>

                            @if ($successStoriesDisplay === 'slider' && $successStories->count() > 1)
                                    <div
                                        class="nmn-success-slider"
                                        data-nmn-success-slider
                                        data-autoplay="{{ $successStoriesAutoplay ? '1' : '0' }}"
                                        data-autoplay-ms="{{ $successStoriesAutoplayMs }}"
                                        data-slides-mobile="{{ $successStoriesSlidesMobile }}"
                                        data-slides-tablet="{{ $successStoriesSlidesTablet }}"
                                        data-slides-desktop="{{ $successStoriesSlidesDesktop }}"
                                        data-arrows="{{ $successStoriesShowArrows ? '1' : '0' }}"
                                        data-dots="{{ $successStoriesShowDots ? '1' : '0' }}"
                                        data-pause-hover="{{ $successStoriesPauseOnHover ? '1' : '0' }}"
                                        data-loop="{{ $successStoriesLoop ? '1' : '0' }}"
                                    >
                                        @if ($successStoriesShowArrows)
                                            <button type="button" class="nmn-success-slider-nav nmn-success-slider-nav-prev" data-slider-prev aria-label="Previous stories">
                                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                                            </button>
                                            <button type="button" class="nmn-success-slider-nav nmn-success-slider-nav-next" data-slider-next aria-label="Next stories">
                                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                            </button>
                                        @endif
                                        <div class="nmn-success-slider-viewport" data-slider-viewport>
                                            <div class="nmn-success-slider-track" data-slider-track>
                                                @foreach ($successStories as $story)
                                                    <div class="nmn-success-slider-slide">
                                                        @include('public.partials.success-story-card', ['story' => $story])
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                        @if ($successStoriesShowDots)
                                            <div class="nmn-success-slider-dots" data-slider-dots aria-hidden="true"></div>
                                        @endif
                                    </div>
                                @else
                                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                        @foreach ($successStories as $story)
                                            @include('public.partials.success-story-card', ['story' => $story])
                                        @endforeach
                                    </div>
                                @endif
                        </div>
                    </section>
                @endif

                @if ($sectionKey === 'safety')
                    <section class="px-4 py-12 sm:px-6">
                        <div class="mx-auto grid max-w-7xl gap-6 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
                            <div>
                                <h2 class="{{ $devanagariClass }} text-2xl font-extrabold text-zinc-950 dark:text-white">{{ __('homepage.safety_title') }}</h2>
                                <p class="mt-2 text-sm font-bold text-[var(--brand-red)]">{{ __('homepage.safety_subtitle') }}</p>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ([
                                    __('homepage.safety_photo'),
                                    __('homepage.safety_contact'),
                                    __('homepage.safety_report'),
                                    __('homepage.safety_admin'),
                                ] as $safetyLabel)
                                    <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                                        <div class="flex items-center gap-2">
                                            <svg class="homepage-icon text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l2.25 2.25L15.75 9M12 3.75l7.5 3v5.25c0 4.47-3.06 8.43-7.5 9.5-4.44-1.07-7.5-5.03-7.5-9.5V6.75l7.5-3z" /></svg>
                                            <h3 class="{{ $devanagariClass }} font-bold text-zinc-950 dark:text-white">{{ $safetyLabel }}</h3>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif

                @if ($sectionKey === 'plans' && $homepagePlans->isNotEmpty())
                    <section class="border-y border-zinc-200 bg-white px-4 py-12 dark:border-zinc-800 dark:bg-zinc-950 sm:px-6">
                        <div class="mx-auto max-w-7xl">
                            <div class="mb-6">
                                <h2 class="{{ $devanagariClass }} text-2xl font-extrabold text-zinc-950 dark:text-white">{{ __('homepage.plans_title') }}</h2>
                                <p class="text-sm font-bold text-[var(--brand-red)]">{{ __('homepage.plans_subtitle') }}</p>
                            </div>
                            <div class="grid gap-4 md:grid-cols-3">
                                @foreach ($homepagePlans as $plan)
                                    <div class="rounded-lg border {{ $plan->highlight ? 'border-red-300' : 'border-zinc-200' }} bg-zinc-50 p-5 dark:border-zinc-800 dark:bg-zinc-900">
                                        @if ($plan->highlight)
                                            <span class="rounded-full bg-red-100 px-2 py-1 text-[11px] font-bold text-[var(--brand-red)]">{{ __('homepage.popular') }}</span>
                                        @endif
                                        <h3 class="{{ $devanagariClass }} mt-3 text-lg font-extrabold text-zinc-950 dark:text-white">{{ $isMarathiLocale && $plan->name_mr ? $plan->name_mr : $plan->name }}</h3>
                                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $plan->description }}</p>
                                        <p class="mt-4 text-2xl font-extrabold text-[var(--brand-red)]">â‚¹{{ number_format((float) $plan->price, 0) }}</p>
                                    </div>
                                @endforeach
                            </div>
                            <a href="{{ route('plans.index') }}" class="mt-5 inline-flex items-center gap-2 rounded-md border border-red-200 px-4 py-2 text-sm font-bold text-[var(--brand-red)] hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-950/40">
                                <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 15h19.5M10.5 3.75v16.5m3-16.5v16.5" /></svg>
                                {{ __('homepage.view_plans') }}
                            </a>
                        </div>
                    </section>
                @endif

                @if ($sectionKey === 'app_section' && $appSectionHasContent)
                    <section id="mobile-app" class="px-4 py-12 sm:px-6">
                        <div class="nmn-app-section mx-auto grid max-w-7xl gap-8 p-6 md:grid-cols-2 md:items-center lg:p-8">
                            <div>
                                <h2 class="{{ $devanagariClass }} text-2xl font-extrabold">{{ $appTitle }}</h2>
                                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-300">{{ $appBody }}</p>
                                @if ($appShowAndroid || $appShowIos)
                                    <div class="nmn-app-store-badges">
                                        @if ($appShowAndroid)
                                            <a href="{{ $appAndroidUrl }}" target="_blank" rel="noopener noreferrer" class="nmn-app-store-badge">
                                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.6 1.8 13.2 12 3.6 22.2a1.4 1.4 0 0 1-.2-.8V2.6c0-.3.1-.6.2-.8Zm1.5.9 10.9 6.3-2.5 2.5L5.1 2.7Zm12.4 7.4-3.1-1.8-2.8 2.8 2.8 2.8 3.1-1.8a1.5 1.5 0 0 0 0-2.6l-.1-.4Zm-5.9 3.4 2.5 2.5-10.9 6.3 8.4-8.8Z"/></svg>
                                                <span>
                                                    <span>{{ $isMarathiLocale ? 'Android à¤¸à¤¾à¤ à¥€' : 'Android app on' }}</span>
                                                    <strong>{{ __('homepage.app_android_cta') }}</strong>
                                                </span>
                                            </a>
                                        @endif
                                        @if ($appShowIos)
                                            <a href="{{ $appIosUrl }}" target="_blank" rel="noopener noreferrer" class="nmn-app-store-badge">
                                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.36 12.74c.03-2.97 2.43-4.4 2.54-4.47-1.38-2.02-3.53-2.3-4.29-2.33-1.83-.19-3.57 1.08-4.49 1.08-.93 0-2.35-1.05-3.86-1.02-1.99.03-3.82 1.16-4.84 2.94-2.07 3.58-.53 8.88 1.48 11.78 1 1.45 2.19 3.08 3.75 3.02 1.51-.06 2.08-.98 3.91-.98 1.83 0 2.35.98 3.95.95 1.63-.03 2.66-1.48 3.64-2.93 1.15-1.68 1.62-3.31 1.65-3.39-.04-.02-3.17-1.22-3.2-4.83l-.01-.04ZM13.3 4.22c.83-1.01 1.39-2.41 1.24-3.8-1.2.05-2.65.8-3.51 1.8-.77.89-1.44 2.32-1.26 3.69 1.33.1 2.69-.68 3.53-1.69Z"/></svg>
                                                <span>
                                                    <span>{{ $isMarathiLocale ? 'iOS à¤¸à¤¾à¤ à¥€' : 'Download on the' }}</span>
                                                    <strong>{{ __('homepage.app_ios_cta') }}</strong>
                                                </span>
                                            </a>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            @if ($appPath)
                                <img src="{{ asset($appPath) }}" alt="" class="mx-auto max-h-80 w-full object-contain" onerror="this.style.display='none';">
                            @endif
                        </div>
                    </section>
                @endif

                @if ($sectionKey === 'retail_outlet' && $retailPath)
                    <section class="border-y border-zinc-200 bg-zinc-50 px-4 py-12 dark:border-zinc-800 dark:bg-zinc-900/50 sm:px-6">
                        <div class="mx-auto grid max-w-7xl gap-8 md:grid-cols-2 md:items-center">
                            <img src="{{ asset($retailPath) }}" alt="" class="mx-auto max-h-80 w-full rounded-lg border border-zinc-200 object-contain p-3 dark:border-zinc-800" onerror="this.style.display='none';">
                            <div>
                                <h2 class="{{ $devanagariClass }} text-2xl font-extrabold text-zinc-950 dark:text-white">{{ __('homepage.retail_title') }}</h2>
                                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-600 dark:text-zinc-400">{{ __('homepage.retail_body') }}</p>
                            </div>
                        </div>
                    </section>
                @endif

                @if ($sectionKey === 'final_cta')
                    <section class="bg-[var(--brand-red)] px-4 py-12 text-white sm:px-6">
                        <div class="mx-auto max-w-4xl text-center">
                            <h2 class="{{ $devanagariClass }} text-3xl font-extrabold">{{ $finalCtaTitle }}</h2>
                            <p class="{{ $devanagariClass }} mx-auto mt-4 max-w-2xl text-sm leading-7 text-white/90">{{ $finalCtaBody }}</p>
                            <div class="mt-6 flex flex-wrap justify-center gap-3">
                                <a href="{{ route('matrimony.profiles.index') }}" class="inline-flex items-center gap-2 rounded-md bg-white px-5 py-3 text-sm font-bold text-[var(--brand-red)] hover:bg-red-50">
                                    <svg class="homepage-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z" /></svg>
                                    {{ __('homepage.final_search') }}
                                </a>
                                @guest
                                    @if (Route::has('register'))
                                        <a href="{{ route('register') }}" class="inline-flex items-center gap-2 rounded-md border border-white/50 px-5 py-3 text-sm font-bold text-white hover:bg-white/10">{{ __('homepage.final_register') }}</a>
                                    @endif
                                @endguest
                            </div>
                        </div>
                    </section>
                @endif
            @endforeach
        </main>

        <footer class="bg-zinc-950 px-4 py-10 text-sm text-zinc-400 sm:px-6">
            <div class="mx-auto grid max-w-7xl gap-8 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <p class="font-devanagari text-lg font-bold text-white">{{ $siteIdentitySettings['company_name'] ?: $siteName }}</p>
                    <p class="{{ $devanagariClass }} mt-2 max-w-md text-xs leading-6 text-zinc-500">{{ __('homepage.footer_disclaimer') }}</p>
                    @if (! empty($siteIdentitySettings['address']))
                        <p class="mt-3 whitespace-pre-line text-xs leading-6 text-zinc-500">{{ $siteIdentitySettings['address'] }}</p>
                    @endif
                </div>
                @if (! empty($siteIdentitySettings['support_email']) || ! empty($siteIdentitySettings['sales_email']) || ! empty($siteIdentitySettings['info_email']) || ! empty($siteIdentitySettings['primary_phone']) || ! empty($siteIdentitySettings['secondary_phone']))
                    <div class="flex flex-col gap-2">
                        <span class="text-[#888] text-xs uppercase tracking-wide">Contact</span>
                        @foreach ([
                            'support_email' => 'Support',
                            'sales_email' => 'Sales',
                            'info_email' => 'Info',
                        ] as $field => $label)
                            @if (! empty($siteIdentitySettings[$field]))
                                <a href="mailto:{{ $siteIdentitySettings[$field] }}" class="text-white hover:underline">{{ $label }}: {{ $siteIdentitySettings[$field] }}</a>
                            @endif
                        @endforeach
                        @foreach (['primary_phone', 'secondary_phone'] as $field)
                            @if (! empty($siteIdentitySettings[$field]))
                                <a href="tel:{{ preg_replace('/\s+/', '', $siteIdentitySettings[$field]) }}" class="text-white hover:underline">{{ $siteIdentitySettings[$field] }}</a>
                            @endif
                        @endforeach
                    </div>
                @endif
                <div class="flex flex-col gap-2">
                    <span class="text-xs font-bold uppercase text-zinc-500">{{ __('homepage.footer_contact') }}</span>
                    @foreach ([
                        'support_email' => 'Support',
                        'sales_email' => 'Sales',
                        'info_email' => 'Info',
                    ] as $field => $label)
                        @if (! empty($siteIdentitySettings[$field]))
                            <a href="mailto:{{ $siteIdentitySettings[$field] }}" class="text-white hover:underline">{{ $label }}: {{ $siteIdentitySettings[$field] }}</a>
                        @endif
                    @endforeach
                    @foreach (['primary_phone', 'secondary_phone'] as $field)
                        @if (! empty($siteIdentitySettings[$field]))
                            <a href="tel:{{ preg_replace('/\s+/', '', $siteIdentitySettings[$field]) }}" class="text-white hover:underline">{{ $siteIdentitySettings[$field] }}</a>
                        @endif
                    @endforeach
                </div>
                <div class="flex flex-col gap-2">
                    <span class="text-xs font-bold uppercase text-zinc-500">{{ __('homepage.footer_navigate') }}</span>
                    <a href="{{ route('matrimony.profiles.index') }}" class="text-white hover:underline">{{ __('homepage.footer_partner_search') }}</a>
                    @if (Route::has('plans.index'))
                        <a href="{{ route('plans.index') }}" class="text-white hover:underline">{{ __('homepage.footer_plans') }}</a>
                    @endif
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="text-white hover:underline">{{ __('homepage.login') }}</a>
                    @endif
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="text-white hover:underline">{{ __('homepage.register') }}</a>
                    @endif
                </div>
            </div>
            @php
                $socialLinks = array_filter([
                    'Facebook' => $siteIdentitySettings['facebook_url'] ?? null,
                    'Instagram' => $siteIdentitySettings['instagram_url'] ?? null,
                    'YouTube' => $siteIdentitySettings['youtube_url'] ?? null,
                    'LinkedIn' => $siteIdentitySettings['linkedin_url'] ?? null,
                    'X' => $siteIdentitySettings['x_url'] ?? null,
                ]);
            @endphp
            @if (! empty($socialLinks))
                <div class="mx-auto mt-6 flex max-w-7xl flex-wrap gap-4 text-xs">
                    @foreach ($socialLinks as $label => $url)
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-white hover:underline">{{ $label }}</a>
                    @endforeach
                </div>
            @endif
            <div class="mx-auto mt-8 max-w-7xl border-t border-zinc-800 pt-6 text-xs text-zinc-600">
                {{ $siteIdentity->copyrightText() }}
            </div>
        </footer>

        @if (($addressStates ?? collect())->isNotEmpty())
            <script>
                (function () {
                    var stateEl = document.getElementById('welcome-search-state');
                    var distEl = document.getElementById('welcome-search-district');
                    if (!stateEl || !distEl) return;
                    var apiDistricts = @json(url('/api/internal/location/districts'));
                    var anyLabel = @json(__('search.any'));
                    stateEl.addEventListener('change', function () {
                        var sid = stateEl.value;
                        distEl.innerHTML = '<option value="">' + anyLabel + '</option>';
                        if (!sid) return;
                        fetch(apiDistricts + '?parent_id=' + encodeURIComponent(sid), {
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (j) {
                                var rows = j && j.data ? j.data : [];
                                rows.forEach(function (d) {
                                    var o = document.createElement('option');
                                    o.value = d.id;
                                    o.textContent = d.name;
                                    distEl.appendChild(o);
                                });
                            })
                            .catch(function () {});
                    });
                })();
            </script>
        @endif
        <script>
            (function () {
                document.querySelectorAll('[data-age-range]').forEach(function (wrap) {
                    var min = wrap.querySelector('[data-age-min]');
                    var max = wrap.querySelector('[data-age-max]');
                    var minThumb = wrap.querySelector('[data-age-min-label]');
                    var maxThumb = wrap.querySelector('[data-age-max-label]');
                    var fill = wrap.querySelector('[data-age-fill]');
                    if (!min || !max || !minThumb || !maxThumb) return;

                    var absMin = parseInt(min.min || '18', 10);
                    var absMax = parseInt(min.max || '80', 10);
                    function valueToPercent(value) {
                        var span = Math.max(1, absMax - absMin);
                        return ((value - absMin) / span) * 100;
                    }

                    function placeThumb(el, value) {
                        var track = wrap.querySelector('.nmn-dual-range-slider');
                        if (!track) return;
                        var width = track.offsetWidth;
                        var thumbPx = el.offsetWidth || 28;
                        var pct = valueToPercent(value) / 100;
                        var x = thumbPx / 2 + pct * Math.max(0, width - thumbPx);
                        el.style.left = (width > 0 ? (x / width) * 100 : pct * 100) + '%';
                    }

                    function syncZ(minValue, maxValue) {
                        min.style.zIndex = minValue > absMax - 5 ? '8' : '6';
                        max.style.zIndex = maxValue < absMin + 5 ? '8' : '7';
                        maxThumb.style.zIndex = maxValue - minValue <= 2 ? '5' : '4';
                        minThumb.style.zIndex = '4';
                    }

                    function paintFill(minValue, maxValue) {
                        if (!fill) return;
                        var span = Math.max(1, absMax - absMin);
                        var p1 = ((minValue - absMin) / span) * 100;
                        var p2 = ((maxValue - absMin) / span) * 100;
                        fill.style.left = p1 + '%';
                        fill.style.width = Math.max(0, p2 - p1) + '%';
                    }

                    function sync(changed) {
                        var minValue = parseInt(min.value || String(absMin), 10);
                        var maxValue = parseInt(max.value || '35', 10);
                        if (minValue > maxValue) {
                            if (changed === max) {
                                minValue = maxValue;
                                min.value = String(minValue);
                            } else {
                                maxValue = minValue;
                                max.value = String(maxValue);
                            }
                        }
                        minThumb.textContent = String(minValue);
                        maxThumb.textContent = String(maxValue);
                        placeThumb(minThumb, minValue);
                        placeThumb(maxThumb, maxValue);
                        paintFill(minValue, maxValue);
                        syncZ(minValue, maxValue);
                    }

                    min.addEventListener('input', function () { sync(min); });
                    max.addEventListener('input', function () { sync(max); });
                    sync();
                    window.addEventListener('resize', function () { sync(); });
                });

                document.querySelectorAll('[data-nmn-success-slider]').forEach(function (root) {
                    var viewport = root.querySelector('[data-slider-viewport]');
                    var track = root.querySelector('[data-slider-track]');
                    var dotsWrap = root.querySelector('[data-slider-dots]');
                    var prevBtn = root.querySelector('[data-slider-prev]');
                    var nextBtn = root.querySelector('[data-slider-next]');
                    if (!viewport || !track) return;

                    var autoplay = root.getAttribute('data-autoplay') === '1';
                    var autoplayMs = parseInt(root.getAttribute('data-autoplay-ms') || '5000', 10);
                    var slidesMobile = parseInt(root.getAttribute('data-slides-mobile') || '1', 10);
                    var slidesTablet = parseInt(root.getAttribute('data-slides-tablet') || '2', 10);
                    var slidesDesktop = parseInt(root.getAttribute('data-slides-desktop') || '3', 10);
                    var showArrows = root.getAttribute('data-arrows') === '1';
                    var showDots = root.getAttribute('data-dots') === '1';
                    var pauseOnHover = root.getAttribute('data-pause-hover') === '1';
                    var loop = root.getAttribute('data-loop') === '1';
                    var gap = 16;
                    var page = 0;
                    var timer = null;
                    var slides = Array.prototype.slice.call(track.children);

                    function perView() {
                        if (window.innerWidth < 768) return Math.max(1, slidesMobile);
                        if (window.innerWidth < 1024) return Math.max(1, slidesTablet);
                        return Math.max(1, slidesDesktop);
                    }

                    function pageCount() {
                        var pv = perView();
                        return Math.max(1, Math.ceil(slides.length / pv));
                    }

                    function layout() {
                        var pv = perView();
                        var viewportW = viewport.offsetWidth;
                        var slideW = pv > 0 ? (viewportW - gap * (pv - 1)) / pv : viewportW;
                        slides.forEach(function (slide) {
                            slide.style.width = slideW + 'px';
                            slide.style.flex = '0 0 ' + slideW + 'px';
                        });
                        goTo(page, false);
                        renderDots();
                        updateArrows();
                    }

                    function offsetForPage(p) {
                        var pv = perView();
                        var viewportW = viewport.offsetWidth;
                        var slideW = pv > 0 ? (viewportW - gap * (pv - 1)) / pv : viewportW;
                        return p * pv * (slideW + gap);
                    }

                    function goTo(p, animate) {
                        var pages = pageCount();
                        if (loop) {
                            if (p < 0) p = pages - 1;
                            if (p >= pages) p = 0;
                        } else {
                            p = Math.max(0, Math.min(pages - 1, p));
                        }
                        page = p;
                        track.style.transition = animate === false ? 'none' : 'transform 0.45s ease';
                        track.style.transform = 'translateX(-' + offsetForPage(page) + 'px)';
                        if (animate === false) {
                            requestAnimationFrame(function () {
                                track.style.transition = 'transform 0.45s ease';
                            });
                        }
                        renderDots();
                        updateArrows();
                    }

                    function updateArrows() {
                        if (!showArrows) return;
                        var pages = pageCount();
                        if (prevBtn) prevBtn.disabled = !loop && page <= 0;
                        if (nextBtn) nextBtn.disabled = !loop && page >= pages - 1;
                    }

                    function renderDots() {
                        if (!showDots || !dotsWrap) return;
                        var pages = pageCount();
                        dotsWrap.innerHTML = '';
                        for (var i = 0; i < pages; i++) {
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'nmn-success-slider-dot' + (i === page ? ' is-active' : '');
                            btn.setAttribute('aria-label', 'Go to slide group ' + (i + 1));
                            (function (idx) {
                                btn.addEventListener('click', function () {
                                    stopAutoplay();
                                    goTo(idx, true);
                                    startAutoplay();
                                });
                            })(i);
                            dotsWrap.appendChild(btn);
                        }
                    }

                    function next() { goTo(page + 1, true); }
                    function prev() { goTo(page - 1, true); }

                    function startAutoplay() {
                        stopAutoplay();
                        if (!autoplay || pageCount() <= 1) return;
                        timer = window.setInterval(function () { next(); }, Math.max(2000, autoplayMs));
                    }

                    function stopAutoplay() {
                        if (timer) {
                            window.clearInterval(timer);
                            timer = null;
                        }
                    }

                    if (prevBtn) prevBtn.addEventListener('click', function () { stopAutoplay(); prev(); startAutoplay(); });
                    if (nextBtn) nextBtn.addEventListener('click', function () { stopAutoplay(); next(); startAutoplay(); });

                    if (pauseOnHover) {
                        root.addEventListener('mouseenter', stopAutoplay);
                        root.addEventListener('mouseleave', startAutoplay);
                    }

                    window.addEventListener('resize', function () { layout(); });
                    layout();
                    startAutoplay();
                });
            })();
        </script>
    </body>
</html>
