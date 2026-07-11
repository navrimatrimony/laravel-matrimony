<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $siteIdentityLayout = app(\App\Services\SiteIdentityService::class);
            $guestBackgroundImageUrl = $siteIdentityLayout->assetUrl('auth_background_image');
        @endphp
        <title>{{ $siteIdentityLayout->get('site_name', config('app.name', 'Laravel')) }} — बायोडाटा नोंदणी</title>
        @include('layouts.partials.site-identity-head')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite([
            'resources/css/app.css',
            'resources/js/app.js',
            'resources/js/profile/location-typeahead.js',
            'resources/js/profile/religion-caste-selector.js',
            'resources/js/matrimony/occupation-engine-entry.js',
        ])
        @stack('head')
    </head>
    <body class="font-sans text-gray-900 antialiased bg-slate-100">
        @if ($guestBackgroundImageUrl)
            <div
                class="pointer-events-none fixed inset-0 -z-20 bg-contain bg-center bg-no-repeat"
                style="background-image: url('{{ $guestBackgroundImageUrl }}');"
                aria-hidden="true"
            ></div>
            <div class="pointer-events-none fixed inset-0 -z-10 bg-white/20" aria-hidden="true"></div>
        @endif

        <div class="relative min-h-screen">
            <div class="px-3 py-4 sm:px-4 sm:py-5">
                @include('partials.laravel-validation-payload')

                <div class="mx-auto mb-3 flex max-w-2xl items-center justify-center">
                    <a href="/" class="inline-flex items-center gap-2 rounded-lg bg-white/90 px-3 py-1.5 shadow-sm ring-1 ring-violet-100 backdrop-blur-sm">
                        <x-application-logo class="h-9 w-9 fill-current text-violet-700" />
                    </a>
                </div>

                <div class="mx-auto w-full max-w-2xl">
                    @yield('content')
                </div>
            </div>
        </div>
        @stack('scripts')
    </body>
</html>
