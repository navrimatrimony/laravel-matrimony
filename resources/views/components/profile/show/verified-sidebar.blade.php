@props([
    'panel' => [],
])

@php
    $verified = $panel['verified'] ?? [];
    $unverified = $panel['unverified'] ?? [];
    $hasAny = ! empty($verified) || ! empty($unverified);
@endphp

<div class="rounded-2xl bg-white/95 p-4 shadow-[0_8px_24px_-8px_rgba(28,25,23,0.1)] ring-1 ring-stone-200/60 dark:bg-gray-900/85 dark:shadow-[0_8px_22px_-8px_rgba(0,0,0,0.32)] dark:ring-gray-700/70 sm:p-5 lg:p-3.5">
    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-300 lg:mb-2">{{ __('profile.show_verification_title') }}</h3>
    @if (! $hasAny)
        <p class="text-sm leading-relaxed text-stone-500 dark:text-stone-400">{{ __('profile.show_verification_none') }}</p>
    @else
        <ul class="space-y-2.5">
            @foreach ($verified as $row)
                <li class="flex min-w-0 flex-nowrap items-center gap-2 text-sm text-stone-800 dark:text-stone-100">
                    <x-profile.show.verification-row-icon variant="ok" :row-key="$row['key'] ?? ''" />
                    <span class="min-w-0 flex-1 truncate whitespace-nowrap leading-none" title="{{ $row['label'] ?? '' }}">{{ $row['label'] ?? '' }}</span>
                </li>
            @endforeach
            @foreach ($unverified as $row)
                <li class="flex min-w-0 flex-nowrap items-center gap-2 text-sm text-red-700 dark:text-red-300">
                    <x-profile.show.verification-row-icon variant="warn" :row-key="$row['key'] ?? ''" />
                    <span class="min-w-0 flex-1 truncate whitespace-nowrap leading-none" title="{{ $row['label'] ?? '' }}">{{ $row['label'] ?? '' }}</span>
                    @if (! empty($row['verify_url']))
                        <a href="{{ $row['verify_url'] }}" class="shrink-0 whitespace-nowrap text-xs font-semibold text-red-800 underline underline-offset-2 hover:text-red-900 sm:text-sm dark:text-red-200 dark:hover:text-red-100">
                            {{ __('profile.verify_action') }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
