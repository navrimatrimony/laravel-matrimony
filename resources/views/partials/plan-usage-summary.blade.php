@php
    /** @var array{bypass?: bool, rows?: array<int, array<string, mixed>}|null}|null $planUsageSummary */
    $summary = $planUsageSummary ?? null;
    $variant = $variant ?? 'compact';
    $show = $summary && (($summary['bypass'] ?? false) || ! empty($summary['rows'] ?? []));
    if ($show && $variant === 'compact' && request()->routeIs('dashboard')) {
        $show = false;
    }
@endphp
@if ($show)
    @php
        $isCompact = $variant === 'compact';
    @endphp
    <div @class([
        'border-b border-gray-200 bg-slate-50/90 dark:border-gray-700 dark:bg-gray-800/80' => $isCompact,
        'bg-white dark:bg-gray-800 rounded-lg shadow-md border border-gray-200 dark:border-gray-700' => ! $isCompact,
    ])>
        <div @class([
            'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2.5' => $isCompact,
            'p-5 sm:p-6' => ! $isCompact,
        ])>
            @if (! $isCompact)
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.usage_section_title') }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">{{ __('dashboard.usage_section_subtitle') }}</p>
                    </div>
                    <a href="{{ route('plans.index') }}" class="inline-flex items-center justify-center shrink-0 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition">
                        {{ __('dashboard.usage_upgrade_plan') }}
                    </a>
                </div>
            @else
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('dashboard.usage_strip_title') }}</p>
                    <a href="{{ route('plans.index') }}" class="text-xs font-medium text-red-600 dark:text-red-400 hover:underline">{{ __('dashboard.usage_upgrade_plan') }}</a>
                </div>
            @endif

            @if ($summary['bypass'] ?? false)
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('dashboard.usage_bypass') }}</p>
            @else
                <ul @class([
                    'flex flex-wrap gap-x-4 gap-y-2 text-xs sm:text-sm' => $isCompact,
                    'grid gap-3 sm:grid-cols-2 lg:grid-cols-3' => ! $isCompact,
                ])>
                    @foreach ($summary['rows'] ?? [] as $row)
                        <li @class([
                            'inline-flex flex-wrap items-baseline gap-x-2 gap-y-0.5 rounded-md bg-white/80 px-2 py-1 dark:bg-gray-900/40' => $isCompact,
                            'rounded-lg border border-gray-100 dark:border-gray-700 px-4 py-3' => ! $isCompact,
                        ])>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ $row['label'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">({{ $row['period'] === 'daily' ? __('dashboard.usage_period_daily') : __('dashboard.usage_period_monthly') }})</span>
                            <span class="text-gray-700 dark:text-gray-300">
                                @if (! empty($row['locked']))
                                    {{ __('dashboard.usage_not_included') }}
                                @elseif (! empty($row['is_unlimited']))
                                    {{ $row['used'] }} / ∞ · {{ __('dashboard.usage_unlimited') }}
                                @else
                                    {{ $row['used'] }} / {{ $row['limit'] }}
                                    @if (isset($row['remaining']))
                                        · {{ __('dashboard.usage_remaining', ['n' => $row['remaining']]) }}
                                    @endif
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
