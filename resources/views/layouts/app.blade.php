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
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            @include('layouts.navigation')

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
				<main>
                {{-- Flash Messages --}}
@if (session('success'))
    <div style="
        margin: 15px auto;
        max-width: 800px;
        padding: 12px;
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #10b981;
        border-radius: 6px;
    ">
        {{ session('success') }}
    </div>
@endif

@if (session('error'))
    <div style="
        margin: 15px auto;
        max-width: 800px;
        padding: 12px;
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #ef4444;
        border-radius: 6px;
    ">
        {{ session('error') }}
    </div>
@endif

@if (session('info'))
    <div style="
        margin: 15px auto;
        max-width: 800px;
        padding: 12px;
        background: #e0f2fe;
        color: #075985;
        border: 1px solid #38bdf8;
        border-radius: 6px;
    ">
        {{ session('info') }}
    </div>
@endif

    @yield('content')
</main>



        </div>
    </body>
</html>
