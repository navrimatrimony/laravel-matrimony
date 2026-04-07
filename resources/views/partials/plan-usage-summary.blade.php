@php
    /** @var array{bypass?: bool, rows?: array<int, array<string, mixed>>|null}|null $planUsageSummary */
    $summary = $planUsageSummary ?? null;
    $variant = $variant ?? 'compact';
    $show = $summary && (($summary['bypass'] ?? false) || ! empty($summary['rows'] ?? []));
    if ($show && $variant === 'compact' && request()->routeIs('dashboard')) {
        $show = false;
    }

    $planUsageDisplayValue = static function (array $row): array {
        if (! empty($row['locked'])) {
            return [
                'text' => __('dashboard.usage_compact_excluded'),
                'title' => __('dashboard.usage_not_included'),
            ];
        }
        if (! empty($row['is_unlimited'])) {
            return [
                'text' => __('dashboard.usage_compact_unlimited'),
                'title' => __('dashboard.usage_unlimited'),
            ];
        }

        return [
            'text' => __('dashboard.usage_remaining', ['n' => $row['remaining']]),
            'title' => null,
        ];
    };
@endphp
@if ($show)
    @php
        $isCompact = $variant === 'compact';
    @endphp
    <div @class([
        'border-b border-gray-200/90 bg-gradient-to-r from-slate-50 via-white to-slate-50 dark:border-gray-700 dark:from-gray-900 dark:via-gray-900/95 dark:to-gray-900' => $isCompact,
        'bg-white dark:bg-gray-800 rounded-lg shadow-md border border-gray-200 dark:border-gray-700' => ! $isCompact,
    ])>
        <div @class([
            'max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-2' => $isCompact,
            'p-5 sm:p-6' => ! $isCompact,
        ])>
            @if ($summary['bypass'] ?? false)
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('dashboard.usage_bypass') }}</p>
                    <a href="{{ route('plans.index') }}" class="shrink-0 text-sm font-medium text-red-600 dark:text-red-400 hover:underline">{{ __('dashboard.usage_upgrade_plan') }}</a>
                </div>
            @elseif ($isCompact)
                {{-- Single-line strip: short labels + remaining only; scroll on small screens --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 sm:flex-nowrap sm:min-w-0">
                    <span class="sr-only">{{ __('dashboard.usage_strip_title') }}</span>
                    <div class="flex min-w-0 flex-1 items-center gap-2 overflow-x-auto pb-0.5 sm:pb-0 [-webkit-overflow-scrolling:touch] [scrollbar-width:thin]">
                        @foreach ($summary['rows'] ?? [] as $row)
                            @php
                                $dv = $planUsageDisplayValue($row);
                                $shortLabel = __('dashboard.usage_short_' . $row['key']);
                            @endphp
                            <span
                                class="inline-flex shrink-0 items-baseline gap-1.5 rounded-full border border-gray-200/90 bg-white/95 px-2.5 py-1 text-xs shadow-sm dark:border-gray-600 dark:bg-gray-800/90"
                                @if ($dv['title']) title="{{ $dv['title'] }}" @endif
                            >
                                <span class="font-medium text-gray-500 dark:text-gray-400">{{ $shortLabel }}</span>
                                <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $dv['text'] }}</span>
                            </span>
                        @endforeach
                    </div>
                    <a
                        href="{{ route('plans.index') }}"
                        class="shrink-0 rounded-full border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-100 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300 dark:hover:bg-red-950/70"
                    >
                        {{ __('dashboard.usage_upgrade_plan') }}
                    </a>
                </div>
            @else
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.usage_section_title') }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">{{ __('dashboard.usage_section_subtitle') }}</p>
                    </div>
                    <a href="{{ route('plans.index') }}" class="inline-flex items-center justify-center shrink-0 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition">
                        {{ __('dashboard.usage_upgrade_plan') }}
                    </a>
                </div>
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($summary['rows'] ?? [] as $row)
                        @php
                            $dv = $planUsageDisplayValue($row);
                            $periodLabel = $row['period'] === 'daily' ? __('dashboard.usage_period_daily') : __('dashboard.usage_period_monthly');
                        @endphp
                        <li class="rounded-xl border border-gray-100 dark:border-gray-700 bg-slate-50/80 dark:bg-gray-900/40 px-4 py-3 flex flex-col gap-1">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-sm font-medium text-gray-800 dark:text-gray-200 leading-snug">{{ $row['label'] }}</span>
                                <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $periodLabel }}</span>
                            </div>
                            <p
                                class="text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-50"
                                @if ($dv['title']) title="{{ $dv['title'] }}" @endif
                            >{{ $dv['text'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
