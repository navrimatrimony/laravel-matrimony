@props([
    'navigation' => ['prev' => null, 'next' => null],
])

@php
    $prev = $navigation['prev'] ?? null;
    $next = $navigation['next'] ?? null;
@endphp

<div class="flex flex-wrap items-stretch justify-between gap-3 rounded-2xl border border-stone-200/80 dark:border-gray-600 bg-stone-50/80 dark:bg-gray-800/40 px-3 py-3 sm:px-4">
    <div class="min-w-0 flex-1">
        @if ($prev)
            <a href="{{ route('matrimony.profile.show', $prev['id']) }}" class="flex items-center gap-3 group rounded-xl p-1 -m-1 hover:bg-white/80 dark:hover:bg-gray-700/50 transition-colors">
                <span class="text-stone-400 group-hover:text-stone-600 dark:group-hover:text-stone-300 shrink-0" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19l-7-7 7-7"/></svg>
                </span>
                <img src="{{ $prev['photo_url'] }}" alt="" class="h-10 w-10 rounded-lg object-cover border border-stone-200 dark:border-gray-600 hidden sm:block" width="40" height="40" />
                <div class="min-w-0 text-left">
                    <p class="text-[10px] uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('profile.show_nav_prev') }}</p>
                    <p class="text-sm font-medium text-stone-900 dark:text-stone-100 truncate">{{ $prev['name'] }}</p>
                </div>
            </a>
        @else
            <div class="flex items-center gap-3 opacity-60">
                <span class="text-stone-300 dark:text-stone-600 shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19l-7-7 7-7"/></svg></span>
                <div>
                    <p class="text-[10px] uppercase tracking-wide text-stone-500">{{ __('profile.show_nav_prev') }}</p>
                    <p class="text-sm text-stone-500">{{ __('profile.show_nav_unavailable') }}</p>
                </div>
            </div>
        @endif
    </div>
    <div class="w-px bg-stone-200 dark:bg-gray-600 self-stretch shrink-0 hidden sm:block"></div>
    <div class="min-w-0 flex-1">
        @if ($next)
            <a href="{{ route('matrimony.profile.show', $next['id']) }}" class="flex items-center gap-3 justify-end group rounded-xl p-1 -m-1 hover:bg-white/80 dark:hover:bg-gray-700/50 transition-colors text-right">
                <div class="min-w-0 text-right">
                    <p class="text-[10px] uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('profile.show_nav_next') }}</p>
                    <p class="text-sm font-medium text-stone-900 dark:text-stone-100 truncate">{{ $next['name'] }}</p>
                </div>
                <img src="{{ $next['photo_url'] }}" alt="" class="h-10 w-10 rounded-lg object-cover border border-stone-200 dark:border-gray-600 hidden sm:block" width="40" height="40" />
                <span class="text-stone-400 group-hover:text-stone-600 dark:group-hover:text-stone-300 shrink-0" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7"/></svg>
                </span>
            </a>
        @else
            <div class="flex items-center gap-3 justify-end opacity-60">
                <div class="text-right">
                    <p class="text-[10px] uppercase tracking-wide text-stone-500">{{ __('profile.show_nav_next') }}</p>
                    <p class="text-sm text-stone-500">{{ __('profile.show_nav_unavailable') }}</p>
                </div>
                <span class="text-stone-300 dark:text-stone-600 shrink-0"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7"/></svg></span>
            </div>
        @endif
    </div>
</div>
