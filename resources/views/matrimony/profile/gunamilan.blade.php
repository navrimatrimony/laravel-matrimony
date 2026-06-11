@extends('layouts.app')

@section('title', __('profile.gunamilan_title'))

@section('content')
@php
    $total = (float) ($result['total_points'] ?? 0);
    $max = (float) ($result['max_points'] ?? 36);
    $percent = $max > 0 ? min(100, max(0, ($total / $max) * 100)) : 0;
    $targetName = \App\Support\ProfileDisplayCopy::formatPersonName((string) ($profile->full_name ?? ''));
    $viewerName = \App\Support\ProfileDisplayCopy::formatPersonName((string) ($viewerProfile->full_name ?? ''));
    $scoreBand = $explanation['score_band'] ?? ['label' => '', 'summary' => ''];
    $reportTemplates = $reportTemplates ?? [];
    $selectedReportFormat = (string) ($selectedReportFormat ?? 'traditional');
    $selectedPreviewUrl = route('matrimony.profile.gunamilan.print', [$profile, 'report_format' => $selectedReportFormat, 'preview' => 1]);
    $formatPoints = static function (float|int $value): string {
        $rounded = round((float) $value, 1);
        return fmod($rounded, 1.0) === 0.0 ? (string) (int) $rounded : number_format($rounded, 1);
    };
@endphp

<div class="min-h-screen bg-stone-50 py-8 text-stone-900 dark:bg-gray-950 dark:text-stone-100">
    <div class="mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8">
        <div class="mb-4 flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            <a href="{{ route('matrimony.profile.show', $profile) }}" class="inline-flex items-center gap-2 text-sm font-semibold text-rose-700 hover:text-rose-800 dark:text-rose-300 dark:hover:text-rose-200">
                <span aria-hidden="true">&larr;</span>
                <span>{{ __('profile.gunamilan_back_profile') }}</span>
            </a>
            <div class="flex w-full flex-wrap items-center gap-2 rounded-xl border border-stone-200 bg-white p-2 shadow-sm dark:border-gray-800 dark:bg-gray-900 lg:w-auto">
                <form method="GET" action="{{ route('matrimony.profile.gunamilan', $profile) }}" data-gunamilan-format-form class="flex flex-wrap items-center gap-1">
                    <div class="inline-flex flex-wrap overflow-hidden rounded-lg border border-stone-200 bg-stone-50 p-0.5 dark:border-gray-700 dark:bg-gray-950" data-gunamilan-format-tabs>
                        @foreach ($reportTemplates as $reportTemplate)
                            @php($isSelectedReportFormat = $selectedReportFormat === $reportTemplate['key'])
                            <label class="cursor-pointer">
                                <input
                                    type="radio"
                                    name="report_format"
                                    value="{{ $reportTemplate['key'] }}"
                                    data-gunamilan-format-input
                                    class="sr-only"
                                    @checked($isSelectedReportFormat)
                                >
                                <span class="block rounded-md px-3 py-1.5 text-sm font-bold transition {{ $isSelectedReportFormat ? 'bg-rose-700 text-white shadow-sm' : 'text-stone-700 hover:bg-white hover:text-rose-800 dark:text-stone-200 dark:hover:bg-gray-800 dark:hover:text-rose-100' }}">
                                    {{ $reportTemplate['label'] }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </form>
                <div class="hidden h-7 w-px bg-stone-200 dark:bg-gray-700 sm:block"></div>
                <div class="flex flex-wrap items-center gap-1" data-gunamilan-actions>
                    <a href="{{ route('matrimony.profile.gunamilan.pdf', [$profile, 'report_format' => $selectedReportFormat]) }}" class="inline-flex items-center justify-center rounded-md bg-rose-700 px-3 py-1.5 text-sm font-bold text-white shadow-sm hover:bg-rose-800">
                        {{ __('profile.gunamilan_download_pdf') }}
                    </a>
                    <a href="{{ route('matrimony.profile.gunamilan.jpg', [$profile, 'report_format' => $selectedReportFormat]) }}" class="inline-flex items-center justify-center rounded-md bg-stone-900 px-3 py-1.5 text-sm font-bold text-white shadow-sm hover:bg-black dark:bg-stone-100 dark:text-stone-950">
                        {{ __('profile.gunamilan_download_jpg') }}
                    </a>
                    <a href="{{ route('matrimony.profile.gunamilan.print', [$profile, 'report_format' => $selectedReportFormat]) }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-md border border-rose-200 bg-white px-3 py-1.5 text-sm font-bold text-rose-800 hover:bg-rose-50 dark:border-rose-900 dark:bg-gray-900 dark:text-rose-100 dark:hover:bg-rose-950/30">
                        {{ __('profile.gunamilan_print_report') }}
                    </a>
                </div>
            </div>
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
                    @if (($scoreBand['label'] ?? '') !== '')
                        <div class="mt-4 rounded-xl border border-rose-100 bg-white/70 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/70">
                            <p class="text-sm font-bold text-stone-950 dark:text-white">{{ $scoreBand['label'] }}</p>
                            <p class="mt-1 text-sm leading-6 text-stone-600 dark:text-stone-300">{{ $scoreBand['summary'] }}</p>
                        </div>
                    @endif
                    <p class="mt-4 text-sm leading-6 text-stone-600 dark:text-stone-300">{{ __('profile.gunamilan_disclaimer') }}</p>
                </div>
            </div>
        </section>

        <section class="mt-6 overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-col gap-3 border-b border-stone-200 px-5 py-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-stone-950 dark:text-white">{{ __('profile.gunamilan_selected_format_preview') }}</h2>
                    <p class="mt-1 text-sm text-stone-500 dark:text-stone-400">{{ $reportTemplate['label'] ?? __('profile.gunamilan_format_label') }}</p>
                </div>
                <a href="{{ route('matrimony.profile.gunamilan.print', [$profile, 'report_format' => $selectedReportFormat]) }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-50 dark:border-rose-900 dark:bg-gray-900 dark:text-rose-100 dark:hover:bg-rose-950/30">
                    {{ __('profile.gunamilan_preview_open') }}
                </a>
            </div>
            <div class="bg-stone-100 p-3 dark:bg-gray-950 sm:p-5">
                <iframe
                    src="{{ $selectedPreviewUrl }}"
                    title="{{ __('profile.gunamilan_selected_format_preview') }}"
                    data-gunamilan-report-preview
                    class="h-[860px] w-full rounded-xl border border-stone-300 bg-white dark:border-gray-700"
                    loading="lazy"
                ></iframe>
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
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('[data-gunamilan-format-form]');
        if (! form) {
            return;
        }

        form.querySelectorAll('[data-gunamilan-format-input]').forEach(function (input) {
            input.addEventListener('change', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }

                form.submit();
            });
        });
    });
</script>
@endsection
