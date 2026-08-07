{{--
    Shared shell for the four public, no-auth information pages
    (/pricing, /contact, /about, /shipping).

    Design family: this deliberately copies resources/views/legal/show.blade.php —
    standalone document, site-identity head, Vite fallback guard, index/follow,
    LIGHT MODE ONLY. Six legal pages already render without a single `dark:`
    class; a reviewer opening /contact next to /grievance must not see one page
    flip with the OS theme while its siblings do not.

    Every company fact rendered here arrives in $identity, resolved ONCE in
    routes/web.php from App\Support\LegalDocument::replacements() — which is the
    single join point between config/legal.php and SiteIdentityService. Nothing on
    this page may hard-code a phone number, email, address or entity name.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('page_title') — {{ $siteName }}</title>
        @include('layouts.partials.site-identity-head')

        <link rel="canonical" href="{{ url()->current() }}">
        <meta name="robots" content="index, follow">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet" />

        {{-- Same Vite guard the legal pages use: a missing build must not 500 a
             page an external reviewer is fetching. --}}
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <style>
                *,::before,::after{box-sizing:border-box}
                body{margin:0;font-family:system-ui,sans-serif;line-height:1.6;color:#201a1a;padding:1.5rem}
            </style>
        @endif

        <style>
            :root { --brand-red: #b91c1c; --brand-red-dark: #8f1515; --ink: #201a1a; }
            .font-devanagari { font-family: 'Noto Sans Devanagari', 'Instrument Sans', sans-serif; }
            .page-hero { background: linear-gradient(180deg, #fff5f5 0%, #fffafa 55%, #ffffff 100%); }
            .page-rule { background: linear-gradient(90deg, var(--brand-red) 0%, rgba(185,28,28,0) 100%); }
        </style>
    </head>
    <body class="bg-white text-[#201a1a] antialiased">

        <a href="#page-main" class="{{ $devanagariClass }} sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-[color:var(--brand-red)] focus:px-4 focus:py-2 focus:text-sm focus:font-bold focus:text-white">
            {{ __('public_pages.common.skip_to_content') }}
        </a>

        <header class="border-b border-zinc-200 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
                <a href="{{ url('/') }}" class="{{ $devanagariClass }} text-lg font-extrabold tracking-tight text-[color:var(--brand-red)]">
                    {{ $siteName }}
                </a>

                <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                    <nav aria-label="{{ __('public_pages.common.pages') }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        @foreach ($publicPageLinks as $navKey => $navLink)
                            <a
                                href="{{ $navLink['url'] }}"
                                @class([
                                    $devanagariClass,
                                    'font-semibold text-[color:var(--brand-red)]' => $navKey === $activePageKey,
                                    'text-zinc-600 hover:text-[color:var(--brand-red)]' => $navKey !== $activePageKey,
                                ])
                                @if ($navKey === $activePageKey) aria-current="page" @endif
                            >{{ $navLink['label'] }}</a>
                        @endforeach
                    </nav>
                    <x-language-switcher :on-red="false" />
                </div>
            </div>
        </header>

        <main id="page-main">

            <div class="page-hero border-b border-zinc-200">
                <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-12">

                    @php
                        // Unfilled [[TOKEN]] warning, identical in intent to legal/show.blade.php:
                        // shown ONLY to an admin or outside production. A reviewer at PayU,
                        // Meta or Google must never see it.
                        $unfilledIdentityPlaceholders = (auth()->user()?->is_admin || ! app()->environment('production'))
                            ? \App\Support\LegalDocument::unfilledPlaceholders()
                            : [];
                    @endphp
                    @if (! empty($unfilledIdentityPlaceholders))
                        <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-900">
                            <strong>Admin notice — not shown to the public.</strong>
                            These placeholders are still unfilled in <code>config/legal.php</code>, so the facts
                            below are incomplete: <code>{{ implode('  ', $unfilledIdentityPlaceholders) }}</code>
                        </div>
                    @endif

                    <nav class="text-xs text-zinc-500">
                        <a href="{{ url('/') }}" class="hover:underline">{{ __('legal.common.home') }}</a>
                        <span class="mx-1">/</span>
                        <span class="{{ $devanagariClass }}">@yield('page_title')</span>
                    </nav>

                    <h1 class="{{ $devanagariClass }} mt-5 text-3xl font-extrabold leading-tight tracking-tight text-[#201a1a] sm:text-4xl">
                        @yield('page_title')
                    </h1>
                    <div class="page-rule mt-4 h-1 w-20 rounded-full"></div>

                    <p class="{{ $devanagariClass }} mt-4 max-w-3xl text-base leading-8 text-zinc-600">
                        @yield('page_summary')
                    </p>

                    @hasSection('hero_extra')
                        <div class="mt-6">@yield('hero_extra')</div>
                    @endif
                </div>
            </div>

            @yield('content')

        </main>

        {{-- One footer for the whole site. This shell used to hand-build its
             own, which is how three visually different footers ended up on one
             domain — a visitor saw a different company block on the homepage,
             the pricing page and a legal page. --}}
        @include('layouts.site-footer')
    </body>
</html>
