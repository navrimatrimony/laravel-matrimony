@props([
    'items' => [],
    'variant' => 'chips',
    'limit' => null,
    'columns' => 2,
])

@php
    $normalized = collect($items)
        ->filter(fn ($item) => is_array($item) && filled($item['message'] ?? null))
        ->values();

    if ($limit !== null) {
        $normalized = $normalized->take((int) $limit)->values();
    }

    $count = $normalized->count();
    $textSize = $count >= 5 ? 'text-xs' : ($count >= 3 ? 'text-sm' : 'text-sm');
@endphp

@if ($count > 0)
    @if ($variant === 'cards')
        @php
            $columnsClass = ((int) $columns) <= 1 ? 'sm:grid-cols-1' : 'sm:grid-cols-2';
        @endphp
        <div class="grid h-full grid-cols-1 gap-3 {{ $columnsClass }}">
            @foreach ($normalized as $item)
                @php
                    $severity = (string) ($item['severity'] ?? 'info');
                    $idx = (int) $loop->index;
                    $alternateClasses = $idx % 2 === 0
                        ? 'border-indigo-200 bg-indigo-50 text-indigo-900 dark:border-indigo-800/70 dark:bg-indigo-900/25 dark:text-indigo-100'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800/70 dark:bg-emerald-900/25 dark:text-emerald-100';

                    $cardClasses = match ($severity) {
                        'danger' => 'border-red-200 bg-red-50 text-red-900 dark:border-red-800/70 dark:bg-red-900/20 dark:text-red-100',
                        default => $alternateClasses,
                    };
                @endphp
                <div class="h-full rounded-xl border px-4 py-3 shadow-sm {{ $cardClasses }}">
                    <p class="break-words font-semibold leading-tight {{ $textSize }}">{{ $item['message'] }}</p>
                    @if (!empty($item['action_url'] ?? null) && !empty($item['action_label'] ?? null))
                        <a href="{{ $item['action_url'] }}" class="mt-2 inline-block text-xs font-medium underline underline-offset-2">
                            {{ $item['action_label'] }}
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="mb-4 rounded-lg border border-amber-200/80 bg-amber-50/70 px-3 py-2 dark:border-amber-800/70 dark:bg-amber-900/15">
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($normalized as $item)
                    @php
                        $severity = (string) ($item['severity'] ?? 'info');
                        $chipClasses = match ($severity) {
                            'danger' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-800/70 dark:bg-red-900/20 dark:text-red-200',
                            'warning' => 'border-amber-200 bg-amber-100 text-amber-800 dark:border-amber-800/70 dark:bg-amber-900/30 dark:text-amber-200',
                            default => 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200',
                        };
                    @endphp

                    <span class="inline-flex max-w-full items-center gap-2 rounded-full border px-3 py-1 font-medium leading-tight {{ $textSize }} {{ $chipClasses }}">
                        <span class="truncate" title="{{ $item['message'] }}">{{ $item['message'] }}</span>
                        @if (!empty($item['action_url'] ?? null) && !empty($item['action_label'] ?? null))
                            <a
                                href="{{ $item['action_url'] }}"
                                class="shrink-0 underline underline-offset-2"
                            >
                                {{ $item['action_label'] }}
                            </a>
                        @endif
                    </span>
                @endforeach
            </div>
        </div>
    @endif
@endif
