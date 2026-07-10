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
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="relative min-h-screen overflow-hidden bg-slate-100">
            @if ($guestBackgroundImageUrl)
                <div
                    class="absolute inset-0 bg-contain bg-center bg-no-repeat"
                    style="background-image: url('{{ $guestBackgroundImageUrl }}');"
                    aria-hidden="true"
                ></div>
                <div class="absolute inset-0 bg-white/15" aria-hidden="true"></div>
            @endif

            <div class="relative z-10 min-h-screen px-4 py-6 sm:px-6 lg:px-8">
                @include('partials.laravel-validation-payload')

                <div class="mx-auto mb-6 flex max-w-6xl items-center justify-center">
                    <a href="/" class="inline-flex items-center gap-3 rounded-xl bg-white/90 px-4 py-2 shadow-sm ring-1 ring-violet-100 backdrop-blur-sm">
                        <x-application-logo class="h-12 w-12 fill-current text-violet-700" />
                    </a>
                </div>

                <div class="mx-auto w-full max-w-6xl">
                    @yield('content')
                </div>
            </div>
        </div>
    </body>
</html>
