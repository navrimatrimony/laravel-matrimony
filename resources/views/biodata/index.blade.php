@extends('layouts.app')

@section('content')
@php
    $allowed = (bool) ($exportState['allowed'] ?? false);
    $unlimited = (bool) ($exportState['unlimited'] ?? false);
    $limit = $exportState['limit'] ?? null;
    $used = (int) ($exportState['used'] ?? 0);
    $remaining = $exportState['remaining'] ?? null;
@endphp

<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-red-700 dark:text-red-300">{{ $profile->full_name }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">{{ __('profile.biodata_export_title') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ __('profile.biodata_export_subtitle') }}</p>
        </div>
        <a href="{{ route('matrimony.profile.show', $profile->id) }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
            {{ __('profile.biodata_export_back_profile') }}
        </a>
    </div>

    <div class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Plan download balance</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    @if ($unlimited)
                        Unlimited downloads in your current plan.
                    @else
                        Used {{ $used }} of {{ $limit ?? 0 }}. Remaining {{ $remaining ?? 0 }} this month.
                    @endif
                </p>
            </div>
            @unless ($allowed)
                <a href="{{ route('plans.index') }}" class="inline-flex items-center justify-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Upgrade plan</a>
            @endunless
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($templates as $template)
            @php
                $premium = (bool) ($template['premium'] ?? false);
                $locked = $premium && ! $canUsePremiumTemplate;
                $exportDisabled = ! $allowed || $locked;
            @endphp
            <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $template['label'] }}</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $template['description'] }}</p>
                    </div>
                    @if ($premium)
                        <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Premium</span>
                    @endif
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-2 text-xs text-gray-600 dark:text-gray-300">
                    <div class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-800">
                        <dt class="font-semibold uppercase tracking-wide">Size</dt>
                        <dd class="mt-1">A4 {{ ucfirst((string) $template['orientation']) }}</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-800">
                        <dt class="font-semibold uppercase tracking-wide">Photo</dt>
                        <dd class="mt-1">{{ ! empty($template['with_photo']) ? 'With photo' : 'No photo' }}</dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-2">
                    @if ($locked)
                        <a href="{{ route('plans.index') }}" class="inline-flex items-center justify-center rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">Upgrade</a>
                    @else
                        <a href="{{ route('matrimony.profile.biodata.preview', $template['key']) }}" class="inline-flex items-center justify-center rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Preview</a>
                    @endif

                    @if ($exportDisabled)
                        <span class="inline-flex items-center justify-center rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-400 dark:bg-gray-800 dark:text-gray-500">PDF</span>
                        <span class="inline-flex items-center justify-center rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-400 dark:bg-gray-800 dark:text-gray-500">JPG</span>
                    @else
                        <a href="{{ route('matrimony.profile.biodata.pdf', $template['key']) }}" class="inline-flex items-center justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">PDF</a>
                        <a href="{{ route('matrimony.profile.biodata.jpg', $template['key']) }}" class="inline-flex items-center justify-center rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-black dark:bg-gray-100 dark:text-gray-900">JPG</a>
                        <a href="{{ route('matrimony.profile.biodata.print', $template['key']) }}" target="_blank" class="inline-flex items-center justify-center rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-200 dark:hover:bg-red-950/40">Print</a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
</div>
@endsection
