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
        <title>{{ $siteIdentityLayout->get('site_name', config('app.name', 'Laravel')) }}</title>
        @include('layouts.partials.site-identity-head')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="relative min-h-screen overflow-hidden bg-gray-100 dark:bg-gray-900">
            @if ($guestBackgroundImageUrl)
                <div
                    class="absolute inset-0 bg-cover bg-center bg-no-repeat"
                    style="background-image: url('{{ $guestBackgroundImageUrl }}');"
                    aria-hidden="true"
                ></div>
                <div class="absolute inset-0 bg-white/70 dark:bg-gray-950/78" aria-hidden="true"></div>
            @endif

            <div class="relative z-10 min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0">
                @include('partials.laravel-validation-payload')
                <div>
                    <a href="/">
                        <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                    </a>
                </div>

                @isset($aboveCard)
                    <div class="w-full sm:max-w-md mt-6 px-6">
                        {{ $aboveCard }}
                    </div>
                @endisset

                <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white/95 dark:bg-gray-800/95 shadow-md overflow-hidden sm:rounded-lg backdrop-blur-sm">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </body>
</html>
