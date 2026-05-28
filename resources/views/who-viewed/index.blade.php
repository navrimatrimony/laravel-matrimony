@extends('layouts.app')

@section('content')
@php
    $whoViewedRows = $whoViewedRows ?? [];
    $hasTeaserRows = false;
    foreach ($whoViewedRows as $__r) {
        if (($__r['display'] ?? '') === 'teaser') {
            $hasTeaserRows = true;
            break;
        }
    }
@endphp
<div class="mx-auto max-w-4xl px-4 pb-28 pt-6 sm:py-8">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-4">{{ __('who_viewed.title') }}</h1>

    @if ($whoViewedLocked ?? false)
        @if (($teaserUniqueCount ?? 0) > 0)
            <p class="text-gray-700 dark:text-gray-300 mb-3">
                {{ trans_choice('who_viewed.teaser_headline', $teaserUniqueCount, ['count' => $teaserUniqueCount]) }}
            </p>
            @if (count($whoViewedRows) > 0)
                <div class="space-y-4 mb-6">
                    @foreach ($whoViewedRows as $row)
                        @if (($row['display'] ?? '') === 'teaser' && ! empty($row['teaser']))
                            @include('who-viewed.partials.viewer-row-teaser', ['teaser' => $row['teaser'], 'plansUrl' => $plansUrl ?? route('plans.index')])
                        @endif
                    @endforeach
                </div>
            @else
                <div class="relative mb-6 overflow-hidden rounded-xl border border-gray-200 bg-gray-50/90 dark:border-gray-700 dark:bg-gray-800/50">
                    <div class="pointer-events-none select-none space-y-3 p-4 blur-md opacity-80" aria-hidden="true">
                        @for ($i = 0; $i < min(4, max(1, $teaserUniqueCount)); $i++)
                            <div class="flex items-center gap-3">
                                <div class="h-14 w-14 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></div>
                                <div class="min-w-0 flex-1 space-y-2">
                                    <div class="h-4 w-40 rounded bg-gray-300 dark:bg-gray-600"></div>
                                    <div class="h-3 w-28 rounded bg-gray-200 dark:bg-gray-700"></div>
                                </div>
                            </div>
                        @endfor
                    </div>
                    <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/30 via-white/70 to-white dark:from-gray-900/20 dark:via-gray-900/75 dark:to-gray-900"></div>
                </div>
            @endif
            <a href="{{ $plansUrl ?? route('plans.index') }}" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                {{ __('who_viewed.upgrade_cta') }}
            </a>
            <p class="mt-5 text-sm text-gray-600 dark:text-gray-400">{{ __('who_viewed.locked_html') }}</p>
        @else
            <p class="text-gray-600 dark:text-gray-400 mb-6">{{ __('who_viewed.locked_html') }}</p>
        @endif
    @elseif ($uniqueCount === 0)
        <p class="text-gray-600 dark:text-gray-400 mb-6">
            @if (! ($hasFullWhoViewedAccess ?? true) && ($windowDays ?? null))
                @if ($whoViewedEmptyUsesMonth ?? false)
                    {{ __('who_viewed.none_this_month') }}
                @else
                    {{ __('who_viewed.none_in_window', ['days' => (int) ($windowDays ?? 0)]) }}
                @endif
            @elseif ($whoViewedEmptyUsesMonth ?? false)
                {{ __('who_viewed.none_this_month') }}
            @else
                {{ __('who_viewed.none_all_time') }}
            @endif
        </p>
    @else
        <p class="text-gray-700 dark:text-gray-300 mb-6">
            @if (! ($hasFullWhoViewedAccess ?? true))
                @if (($whoViewedEmptyUsesMonth ?? false))
                    {{ trans_choice('who_viewed.people_viewed_this_month', $uniqueCount, ['count' => $uniqueCount]) }}
                @elseif (($windowDays ?? null))
                    {{ trans_choice('who_viewed.people_viewed_in_window', $uniqueCount, ['count' => $uniqueCount, 'days' => (int) ($windowDays ?? 0)]) }}
                @else
                    {{ trans_choice('who_viewed.people_viewed_all_time', $uniqueCount, ['count' => $uniqueCount]) }}
                @endif
            @else
                {{ trans_choice('who_viewed.people_viewed_all_time', $uniqueCount, ['count' => $uniqueCount]) }}
            @endif
        </p>

        <div class="space-y-4">
            @foreach ($whoViewedRows as $row)
                @if (($row['display'] ?? '') === 'full')
                    @include('who-viewed.partials.viewer-row-full', ['view' => $row['view']])
                @elseif (($row['display'] ?? '') === 'teaser' && ! empty($row['teaser']))
                    @include('who-viewed.partials.viewer-row-teaser', ['teaser' => $row['teaser'], 'plansUrl' => $plansUrl ?? route('plans.index')])
                @endif
            @endforeach
        </div>

        @if ($whoViewedRows instanceof \Illuminate\Contracts\Pagination\Paginator && $whoViewedRows->hasPages())
            <div class="mt-6">{{ $whoViewedRows->withQueryString()->links() }}</div>
        @endif

        @if ($hasTeaserRows && ! ($hasFullWhoViewedAccess ?? true))
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                <a href="{{ $plansUrl ?? route('plans.index') }}" class="font-medium text-indigo-600 underline hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">{{ __('who_viewed.partial_plan_upgrade_hint') }}</a>
            </p>
        @endif
    @endif
</div>
@endsection
