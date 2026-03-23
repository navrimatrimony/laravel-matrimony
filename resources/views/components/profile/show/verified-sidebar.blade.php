@props([
    'items' => [],
])

<div class="rounded-2xl bg-white/95 p-4 shadow-[0_8px_24px_-8px_rgba(28,25,23,0.1)] ring-1 ring-stone-200/60 dark:bg-gray-900/85 dark:shadow-[0_8px_22px_-8px_rgba(0,0,0,0.32)] dark:ring-gray-700/70 sm:p-5 lg:p-3.5">
    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-300 lg:mb-2">{{ __('profile.show_verification_title') }}</h3>
    @if (empty($items))
        <p class="text-sm leading-relaxed text-stone-500 dark:text-stone-400">{{ __('profile.show_verification_none') }}</p>
    @else
        <ul class="space-y-2.5">
            @foreach ($items as $item)
                <li class="flex gap-2.5 text-sm leading-snug text-stone-800 dark:text-stone-100">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    </span>
                    <span class="min-w-0 flex-1 pt-0.5">{{ $item['label'] ?? '' }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
