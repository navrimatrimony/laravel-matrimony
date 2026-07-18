@extends('layouts.admin')

@section('content')
@php
    $fieldClass = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100';
    $labelClass = 'block text-sm font-semibold text-gray-700 dark:text-gray-200';
    $helpClass = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $panelClass = 'rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800';
    $url = function (?string $path): ?string {
        $path = trim((string) $path);
        return $path !== '' ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
    };
@endphp

<div class="mx-auto max-w-3xl space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak APK Settings</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Theme, welcome photo, and logos for the Suchak mobile app. Specs are shown next to each upload so sizes stay consistent.
            </p>
        </div>
        <a href="{{ route('admin.suchak.dashboard') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300">Dashboard</a>
    </div>

    @if (session('success'))
        <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.suchak.apk-settings.update') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf

        <div class="{{ $panelClass }} space-y-4">
            <div>
                <label class="{{ $labelClass }}" for="theme_color">Theme color</label>
                <input id="theme_color" name="theme_color" type="text" value="{{ old('theme_color', $config['theme_color']) }}" class="{{ $fieldClass }} mt-1 font-mono" placeholder="#1F6B4F" required>
                <p class="{{ $helpClass }}">Single hex for APK primary/accent. Example: #1F6B4F</p>
                @error('theme_color')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="tagline_mr">Welcome tagline (Marathi)</label>
                    <input id="tagline_mr" name="tagline_mr" type="text" value="{{ old('tagline_mr', $config['tagline']['mr'] ?? '') }}" class="{{ $fieldClass }} mt-1">
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="tagline_en">Welcome tagline (English)</label>
                    <input id="tagline_en" name="tagline_en" type="text" value="{{ old('tagline_en', $config['tagline']['en'] ?? '') }}" class="{{ $fieldClass }} mt-1">
                </div>
            </div>
        </div>

        <div class="{{ $panelClass }} space-y-3">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Homepage photo</h2>
            <p class="{{ $helpClass }}">{{ $specs['homepage'] }}</p>
            @if ($url($config['homepage_photo_path'] ?? null))
                <img src="{{ $url($config['homepage_photo_path']) }}" alt="Homepage preview" class="max-h-64 rounded-md border border-gray-200 object-cover dark:border-gray-600">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" name="remove_homepage_photo" value="1" @checked(old('remove_homepage_photo'))>
                    Remove current photo
                </label>
            @endif
            <input type="file" name="homepage_photo" accept="image/jpeg,image/png,image/webp" class="{{ $fieldClass }}">
            @error('homepage_photo')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="{{ $panelClass }} space-y-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Light logo</h2>
                <p class="{{ $helpClass }}">{{ $specs['logo'] }} · for light backgrounds</p>
                @if ($url($config['logo_light_path'] ?? null))
                    <img src="{{ $url($config['logo_light_path']) }}" alt="Light logo" class="h-24 w-24 rounded-md border border-gray-200 bg-white object-contain p-2 dark:border-gray-600">
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="remove_logo_light" value="1"> Remove</label>
                @endif
                <input type="file" name="logo_light" accept="image/png" class="{{ $fieldClass }}">
                @error('logo_light')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="{{ $panelClass }} space-y-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Dark logo</h2>
                <p class="{{ $helpClass }}">{{ $specs['logo'] }} · for dark / photo backgrounds</p>
                @if ($url($config['logo_dark_path'] ?? null))
                    <img src="{{ $url($config['logo_dark_path']) }}" alt="Dark logo" class="h-24 w-24 rounded-md border border-gray-200 bg-gray-900 object-contain p-2 dark:border-gray-600">
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="remove_logo_dark" value="1"> Remove</label>
                @endif
                <input type="file" name="logo_dark" accept="image/png" class="{{ $fieldClass }}">
                @error('logo_dark')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="{{ $panelClass }} space-y-3">
            <label class="{{ $labelClass }}" for="reason">Change reason (audit)</label>
            <textarea id="reason" name="reason" rows="2" class="{{ $fieldClass }}" required minlength="10">{{ old('reason') }}</textarea>
            @error('reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save APK settings</button>
        </div>
    </form>
</div>
@endsection
