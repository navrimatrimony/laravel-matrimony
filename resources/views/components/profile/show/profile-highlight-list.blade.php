@props([
    'items' => [],
])

@php
    $list = array_values(array_filter(is_array($items) ? $items : []));
@endphp

@if (count($list) > 0)
    <div class="flex flex-wrap gap-2">
        @foreach ($list as $item)
            <span class="inline-flex items-center rounded-full bg-stone-100/95 px-3 py-1.5 text-xs font-medium text-stone-800 ring-1 ring-stone-200/70 dark:bg-stone-800/90 dark:text-stone-100 dark:ring-stone-600/50">{{ $item }}</span>
        @endforeach
    </div>
@endif
