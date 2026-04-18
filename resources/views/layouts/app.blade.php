<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
		<meta name="robots" content="noindex, nofollow">
		<meta name="googlebot" content="noindex, nofollow">


        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/profile/religion-caste-selector.js', 'resources/js/profile/location-typeahead.js', 'resources/js/profile/about-me-narrative.js', 'resources/js/matrimony/occupation-engine-entry.js'])
    </head>
    <body class="font-sans antialiased">
        @php
            $cardOnboardingStep = auth()->check()
                ? \App\Models\MatrimonyProfile::query()->where('user_id', auth()->id())->value('card_onboarding_resume_step')
                : null;
            $hideMemberMainNav = request()->routeIs('matrimony.onboarding.*')
                || $cardOnboardingStep !== null;
            $showMobileStickyNav = auth()->check() && ! $hideMemberMainNav;
        @endphp
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            @if ($hideMemberMainNav)
                @include('layouts.partials.onboarding-minimal-nav')
            @else
                @include('layouts.navigation')
            @endif

            @unless ($hideMemberMainNav)
                @include('partials.plan-usage-summary', ['variant' => 'compact'])
            @endunless

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white dark:bg-gray-800 shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <!-- Page Content -->
				<main class="{{ $showMobileStickyNav ? 'pb-16 md:pb-0' : '' }}">
                @include('partials.laravel-validation-payload')
                {{-- Flash messages: single place, dismissible + auto-hide (see resources/js/app.js) --}}
@php($memberNotice = session('member_notice'))
@if (is_array($memberNotice) && ! empty($memberNotice['message']))
    @php($mnTone = ($memberNotice['tone'] ?? 'success') === 'danger' ? 'danger' : 'success')
    <div data-flash-dismissible data-flash-auto-ms="{{ $mnTone === 'danger' ? '12000' : '8000' }}" role="{{ $mnTone === 'danger' ? 'alert' : 'status' }}" class="relative z-40 mx-auto max-w-2xl px-4 pt-4">
        <div class="flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-sm @if ($mnTone === 'danger') border-red-200 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100 @else border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-100 @endif">
            <p class="min-w-0 flex-1 leading-relaxed">{{ $memberNotice['message'] }}</p>
            <button type="button" data-flash-close class="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold @if ($mnTone === 'danger') text-red-800 hover:bg-red-100 dark:text-red-200 dark:hover:bg-red-900/50 @else text-emerald-800 hover:bg-emerald-100 dark:text-emerald-200 dark:hover:bg-emerald-900/60 @endif" aria-label="{{ __('common.dismiss') }}">×</button>
        </div>
    </div>
@endif

@if (session('success'))
    <div data-flash-dismissible data-flash-auto-ms="8000" role="status" class="relative z-40 mx-auto max-w-2xl px-4 pt-4">
        <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 shadow-sm dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-100">
            <p class="min-w-0 flex-1 leading-relaxed">{{ session('success') }}</p>
            <button type="button" data-flash-close class="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 dark:text-emerald-200 dark:hover:bg-emerald-900/60" aria-label="{{ __('common.dismiss') }}">×</button>
        </div>
    </div>
@endif

@if (session('error'))
    <div data-flash-dismissible data-flash-auto-ms="12000" role="alert" class="relative z-40 mx-auto max-w-2xl px-4 pt-4">
        <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 shadow-sm dark:border-red-800 dark:bg-red-950/40 dark:text-red-100">
            <p class="min-w-0 flex-1 leading-relaxed">{{ session('error') }}</p>
            <button type="button" data-flash-close class="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold text-red-800 hover:bg-red-100 dark:text-red-200 dark:hover:bg-red-900/50" aria-label="{{ __('common.dismiss') }}">×</button>
        </div>
    </div>
@endif

@if (session('info'))
    <div data-flash-dismissible data-flash-auto-ms="8000" role="status" class="relative z-40 mx-auto max-w-2xl px-4 pt-4">
        <div class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 shadow-sm dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">
            <p class="min-w-0 flex-1 leading-relaxed">{{ session('info') }}</p>
            <button type="button" data-flash-close class="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold text-sky-800 hover:bg-sky-100 dark:text-sky-200 dark:hover:bg-sky-900/50" aria-label="{{ __('common.dismiss') }}">×</button>
        </div>
    </div>
@endif

    @yield('content')
</main>

@auth
    @if (! $hideMemberMainNav && ! request()->routeIs('help-centre.*'))
        @include('help-centre.partials.floating-widget')
        @include('partials.who-viewed-floating-bubble', ['suppressWhoViewedBubble' => request()->routeIs('who-viewed.index')])
        @if (! request()->routeIs('chat.*'))
            @include('partials.chat-dock-widget')
        @endif
    @endif
@endauth
@include('partials.mobile-sticky-quick-nav', ['hideMemberMainNav' => $hideMemberMainNav])



        </div>
    </body>
</html>
