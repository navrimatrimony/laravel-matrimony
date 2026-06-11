@extends('layouts.app')

@section('title', __('profile.gunamilan_title'))

@section('content')
@php
    $total = (float) ($result['total_points'] ?? 0);
    $max = (float) ($result['max_points'] ?? 36);
    $percent = $max > 0 ? min(100, max(0, ($total / $max) * 100)) : 0;
    $targetName = \App\Support\ProfileDisplayCopy::formatPersonName((string) ($profile->full_name ?? ''));
    $viewerName = \App\Support\ProfileDisplayCopy::formatPersonName((string) ($viewerProfile->full_name ?? ''));
    $formatPoints = static function (float|int $value): string {
        $rounded = round((float) $value, 1);
        return fmod($rounded, 1.0) === 0.0 ? (string) (int) $rounded : number_format($rounded, 1);
    };
    $statusClass = static function (string $status): string {
        return match ($status) {
            'full' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100',
            'missing' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100',
            default => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100',
        };
    };
@endphp

<div class="min-h-screen bg-stone-50 py-8 text-stone-900 dark:bg-gray-950 dark:text-stone-100">
    <div class="mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8">
        <div class="mb-5">
            <a href="{{ route('matrimony.profile.show', $profile) }}" class="inline-flex items-center gap-2 text-sm font-semibold text-rose-700 hover:text-rose-800 dark:text-rose-300 dark:hover:text-rose-200">
                <span aria-hidden="true">&larr;</span>
                <span>{{ __('profile.gunamilan_back_profile') }}</span>
            </a>
        </div>

        <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="grid gap-0 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="p-6 sm:p-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-rose-600 dark:text-rose-300">{{ __('profile.gunamilan_kicker') }}</p>
                    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-stone-950 dark:text-white sm:text-4xl">{{ __('profile.gunamilan_title') }}</h1>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-stone-600 dark:text-stone-300">{{ __('profile.gunamilan_subtitle') }}</p>

                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-stone-200 bg-stone-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/70">
                            <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('profile.gunamilan_viewer') }}</p>
                            <p class="mt-1 truncate text-lg font-bold">{{ $viewerName ?: __('profile.gunamilan_not_available') }}</p>
                        </div>
                        <div class="rounded-xl border border-stone-200 bg-stone-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/70">
                            <p class="text-xs font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('profile.gunamilan_target') }}</p>
                            <p class="mt-1 truncate text-lg font-bold">{{ $targetName ?: __('profile.gunamilan_not_available') }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col justify-center border-t border-stone-200 bg-gradient-to-br from-rose-50 via-white to-amber-50 p-6 dark:border-gray-800 dark:from-rose-950/30 dark:via-gray-900 dark:to-amber-950/20 lg:border-l lg:border-t-0 sm:p-8">
                    <p class="text-sm font-semibold text-stone-600 dark:text-stone-300">{{ __('profile.gunamilan_total_label') }}</p>
                    <div class="mt-2 flex items-end gap-2">
                        <span class="text-5xl font-black leading-none text-stone-950 dark:text-white">{{ $formatPoints($total) }}</span>
                        <span class="pb-1 text-xl font-bold text-stone-500 dark:text-stone-400">/ {{ $formatPoints($max) }}</span>
                    </div>
                    <div class="mt-5 h-3 overflow-hidden rounded-full bg-white ring-1 ring-stone-200 dark:bg-gray-800 dark:ring-gray-700">
                        <div class="h-full rounded-full bg-rose-600" style="width: {{ $percent }}%;"></div>
                    </div>
                    <p class="mt-4 text-sm leading-6 text-stone-600 dark:text-stone-300">{{ __('profile.gunamilan_disclaimer') }}</p>
                </div>
            </div>
        </section>

        @if (! empty($result['missing_fields']))
            <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-100">
                <h2 class="text-lg font-bold">{{ __('profile.gunamilan_missing_title') }}</h2>
                <p class="mt-1 text-sm leading-6">{{ __('profile.gunamilan_missing_body') }}</p>
                <ul class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ($result['missing_fields'] as $missing)
                        <li class="rounded-lg bg-white/70 px-3 py-2 text-sm font-medium dark:bg-gray-900/50">{{ $missing['label'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="mt-6 grid gap-4 lg:grid-cols-2">
            @foreach ($result['sections'] as $section)
                <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-stone-950 dark:text-white">{{ $section['label'] }}</h2>
                            <p class="mt-1 text-sm text-stone-500 dark:text-stone-400">{{ $section['note'] }}</p>
                        </div>
                        <span class="shrink-0 rounded-full border px-3 py-1 text-sm font-bold {{ $statusClass($section['status']) }}">
                            {{ $formatPoints($section['points']) }} / {{ $formatPoints($section['max_points']) }}
                        </span>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-stone-200 bg-stone-50 px-3 py-3 dark:border-gray-700 dark:bg-gray-800/65">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('profile.gunamilan_bride') }}</p>
                            <p class="mt-1 text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $section['bride_value'] }}</p>
                        </div>
                        <div class="rounded-xl border border-stone-200 bg-stone-50 px-3 py-3 dark:border-gray-700 dark:bg-gray-800/65">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('profile.gunamilan_groom') }}</p>
                            <p class="mt-1 text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $section['groom_value'] }}</p>
                        </div>
                    </div>

                    @if (! empty($section['missing']))
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/25 dark:text-amber-100">
                            {{ implode(' | ', $section['missing']) }}
                        </div>
                    @endif
                </article>
            @endforeach
        </section>
    </div>
</div>
@endsection
