@php
    $photoUrl = $teaser['photo_url'] ?? null;
    $avatarStyle = $teaser['avatar_style'] ?? 'blur';
    $blurClass = $teaser['blur_photo_class'] ?? 'blur-md scale-110 opacity-90';
    $accentLine = $teaser['accent_line'] ?? null;
    $matchLine = $teaser['match_line'] ?? null;
    $layout = isset($cardLayout) ? (string) $cardLayout : 'horizontal';
    $isVertical = $layout === 'vertical';
    $hideRightCta = ! empty($hideTeaserCtaColumn);
    $applyBlur = $avatarStyle === 'blur' && $photoUrl;
@endphp
<div @class([
    'flex w-full items-stretch overflow-hidden rounded-2xl border border-indigo-200/80 bg-gradient-to-br from-white via-indigo-50/40 to-indigo-100/50 shadow-md ring-1 ring-indigo-100/60 dark:border-indigo-800/70 dark:from-gray-900 dark:via-indigo-950/30 dark:to-indigo-950/50 dark:ring-indigo-900/40',
    'min-h-[8.5rem] flex-row' => ! $isVertical,
    'min-h-0 flex-col sm:min-h-[8.5rem] sm:flex-row' => $isVertical,
])>
    {{-- Photo: stretch with row height; blur optional --}}
    <div @class([
        'relative shrink-0 overflow-hidden bg-gray-900',
        'w-28 self-stretch sm:w-32' => ! $isVertical,
        'h-44 w-full shrink-0 sm:h-auto sm:min-h-[9rem] sm:w-32 sm:self-stretch' => $isVertical,
    ])>
        @if ($photoUrl)
            <img src="{{ $photoUrl }}" alt="" class="absolute inset-0 h-full w-full object-cover{{ $applyBlur ? ' '.$blurClass : '' }}" loading="lazy">
        @else
            <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-b from-indigo-900/50 to-gray-900">
                <svg class="h-14 w-14 text-indigo-100/70" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
            </div>
        @endif
        <div class="pointer-events-none absolute inset-0 z-[1] flex flex-col items-center justify-center gap-2 bg-gradient-to-b from-black/10 via-black/25 to-black/45 px-2 py-2 sm:gap-3">
            <svg class="pointer-events-none h-8 w-8 shrink-0 text-white drop-shadow-lg sm:h-9 sm:w-9" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 00-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
            </svg>
            <a href="{{ $plansUrl }}" class="pointer-events-auto rounded-full bg-white/20 px-2.5 py-1.5 text-[10px] font-bold tracking-wide text-amber-200 ring-1 ring-white/30 backdrop-blur-sm hover:bg-white/30 hover:text-white sm:text-[11px]">
                {{ __('who_viewed.teaser_photo_view_plans') }}
            </a>
        </div>
    </div>

    {{-- Details: natural height, no forced inner scroll --}}
    <div @class([
        'flex min-w-0 flex-1 flex-col justify-start border-indigo-200/50 bg-white/40 px-3 py-2.5 dark:border-indigo-800/50 dark:bg-gray-900/20',
        'border-l' => ! $isVertical,
        'border-t border-l-0 sm:border-l sm:border-t-0' => $isVertical,
    ])>
        <p class="line-clamp-2 font-semibold text-gray-900 dark:text-gray-100">{{ $teaser['headline'] ?? '' }}</p>
        @if ($accentLine)
            <p class="mt-1.5 line-clamp-2 text-sm font-semibold text-amber-700 dark:text-amber-300">{{ $accentLine }}</p>
        @endif
        @if ($matchLine)
            <p class="mt-1 line-clamp-2 text-sm font-semibold text-emerald-700 dark:text-emerald-400">{{ $matchLine }}</p>
        @endif
        <div class="mt-1 space-y-0.5 text-sm leading-snug text-gray-600 dark:text-gray-300">
            @foreach ($teaser['lines'] ?? [] as $line)
                <p class="break-words leading-snug">{{ $line }}</p>
            @endforeach
        </div>
        <p class="mt-1 shrink-0 break-words text-xs text-gray-500 dark:text-gray-400">{{ $teaser['viewed_summary'] ?? '' }}</p>
        @if ($hideRightCta)
            <p class="mt-2 shrink-0">
                <a href="{{ $plansUrl }}" class="inline-flex items-center gap-1 text-xs font-bold text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900 dark:text-indigo-300 dark:hover:text-indigo-100">
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 00-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                    {{ __('who_viewed.teaser_unlock_button') }}
                </a>
            </p>
        @endif
    </div>

    @if (! $hideRightCta)
        {{-- CTA column --}}
        <div @class([
            'flex shrink-0 flex-col items-stretch justify-center gap-2 self-stretch border-indigo-200/60 bg-gradient-to-b from-indigo-600/95 via-indigo-700 to-indigo-800 px-2.5 py-2.5 text-white shadow-inner dark:border-indigo-700/80',
            'w-[8.75rem] border-l sm:w-36' => ! $isVertical,
            'w-full border-t border-l-0 sm:w-36 sm:border-l sm:border-t-0' => $isVertical,
        ])>
            <div class="flex flex-col items-center gap-1">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/10 ring-1 ring-white/25">
                    <svg class="h-5 w-5 text-amber-100" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 00-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </span>
                <p class="line-clamp-3 text-center text-[9px] font-medium leading-snug text-indigo-100/95 sm:text-[10px]">
                    {{ __('who_viewed.teaser_cta_column_hint') }}
                </p>
            </div>
            <a href="{{ $plansUrl }}" title="{{ __('who_viewed.teaser_unlock_cta') }}" class="group inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-white px-2 py-2.5 text-center text-xs font-bold text-indigo-800 shadow-lg ring-2 ring-white/40 transition hover:bg-amber-50 hover:text-indigo-900 hover:ring-amber-200/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 focus-visible:ring-offset-2 focus-visible:ring-offset-indigo-700 sm:text-sm">
                <svg class="h-4 w-4 shrink-0 text-indigo-600 group-hover:text-indigo-800" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 00-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
                <span>{{ __('who_viewed.teaser_unlock_button') }}</span>
            </a>
        </div>
    @endif
</div>
