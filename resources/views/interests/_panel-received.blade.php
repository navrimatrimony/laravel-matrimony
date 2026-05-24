@php
    $periodLabel = match ($interestViewPeriod ?? 'monthly') {
        'weekly' => __('interests.period_weekly'),
        'quarterly' => __('interests.period_quarterly'),
        'daily' => __('interests.period_daily'),
        'lifetime' => __('interests.period_lifetime'),
        default => __('interests.period_monthly'),
    };
    $limitVal = $interestViewLimit ?? -1;
    $photoService = app(\App\Services\Image\ProfilePhotoUrlService::class);
    $plansUrlPanel = $interestTeaserPlansUrl ?? route('plans.index');
    $cardLayout = $receivedInterestCardLayout ?? 'horizontal';
    $isVerticalCard = $cardLayout === 'vertical';
    $isTwoColumn = $cardLayout === 'two_column';
    $isPhotoOverlay = $cardLayout === 'photo_overlay';
    $stretchRowCards = $isTwoColumn || $isPhotoOverlay;
@endphp
<div class="mb-6">
    <h2 class="text-xl font-bold tracking-tight text-gray-900 dark:text-gray-100">{{ __('interests.received_interests') }}</h2>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('interests.received_subtitle') }}</p>
</div>

@if (($interestViewLimit ?? -1) >= 0)
    <div class="mb-8 overflow-hidden rounded-3xl border border-amber-200/80 bg-gradient-to-br from-amber-50/95 via-white to-rose-50/90 p-5 shadow-[0_12px_40px_-16px_rgba(225,29,72,0.25)] dark:border-amber-900/40 dark:from-amber-950/35 dark:via-gray-900 dark:to-rose-950/25">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-200">{{ __('interests.interest_view_policy_title') }}</p>
                <p class="mt-2 text-sm leading-relaxed text-gray-800 dark:text-gray-200">
                    @if ($limitVal === 0)
                        {{ __('interests.interest_view_zero') }}
                    @else
                        {{ __('interests.interest_view_banner', ['count' => $limitVal, 'period' => $periodLabel]) }}
                    @endif
                </p>
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                    {{ __('interests.interest_view_window', ['start' => $interestViewWindowStart->timezone(config('app.timezone'))->format('M j, Y')]) }}
                </p>
            </div>
            <a href="{{ route('plans.index') }}"
               class="inline-flex shrink-0 items-center justify-center rounded-2xl bg-gradient-to-r from-rose-600 to-rose-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-rose-600/25 transition hover:from-rose-700 hover:to-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-400 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                {{ __('interests.upgrade_for_more_reveals') }}
            </a>
        </div>
    </div>
@else
    <div class="mb-8 rounded-3xl border border-emerald-200/90 bg-emerald-50/70 px-4 py-3 text-sm text-emerald-900 shadow-sm dark:border-emerald-800 dark:bg-emerald-950/35 dark:text-emerald-100">
        {{ __('interests.interest_view_unlimited_plan') }}
    </div>
@endif

<div @class([
    'grid gap-6',
    'grid-cols-1 sm:grid-cols-2 sm:gap-6 [&>article]:flex [&>article]:h-full [&>article]:min-h-0 [&>article]:min-w-0 [&>article]:flex-col' => $isPhotoOverlay,
    'grid-cols-1 lg:grid-cols-2 lg:gap-x-8 lg:[&>article]:flex lg:[&>article]:h-full lg:[&>article]:min-h-0 lg:[&>article]:min-w-0 lg:[&>article]:flex-col' => $isTwoColumn && ! $isPhotoOverlay,
    'grid-cols-1' => ! $isPhotoOverlay && ! $isTwoColumn,
])>
@forelse ($receivedInterests as $interest)
    @php
        $unlocked = ($unlockById[$interest->id] ?? true) === true;
        $sender = $interest->senderProfile;
        $lockedTeaser = ($lockedInterestTeasers ?? [])[$interest->id] ?? null;
        $applyRichLocked = ($applyRichReceivedLockedTeaser ?? true);
        $useTeaserCard = ! $unlocked && $applyRichLocked && $sender && is_array($lockedTeaser);
        $gk = $sender?->gender?->key ?? null;
        $ph = $gk === 'male' ? asset('images/placeholders/male-profile.svg') : ($gk === 'female' ? asset('images/placeholders/female-profile.svg') : asset('images/placeholders/default-profile.svg'));
        $photoSrcUnlocked = ($sender && $sender->profile_photo && $sender->photo_approved !== false)
            ? $photoService->publicUrl($sender->profile_photo)
            : ($sender ? $ph : asset('images/placeholders/default-profile.svg'));
        $acceptLocked = ! $unlocked;
        $teaserCardLayout = $isVerticalCard ? 'vertical' : 'horizontal';
        $residenceLine = $sender ? trim(\App\Support\ProfileDisplayCopy::profileResidenceDisplayLine($sender)) : '';
    @endphp
    <article @class([
        'group/card relative z-0 flex min-w-0 flex-col overflow-x-clip overflow-y-visible rounded-3xl border border-stone-200/90 bg-white shadow-[0_14px_48px_-20px_rgba(28,25,23,0.18)] ring-1 ring-stone-100/70 transition hover:shadow-[0_18px_56px_-22px_rgba(28,25,23,0.22)] hover:ring-rose-100/50 dark:border-gray-700/90 dark:bg-gray-800/95 dark:ring-gray-700/60 dark:hover:ring-rose-950/40',
        'h-full min-h-0' => $stretchRowCards,
    ])>
        <div class="pointer-events-none absolute inset-x-0 top-0 z-10 h-1 bg-gradient-to-r from-rose-500 via-violet-500 to-indigo-500 opacity-90"></div>
        @if ($isPhotoOverlay)
            <div class="relative z-0 flex flex-1 flex-col p-3 sm:p-4">
                @if ($useTeaserCard)
                    @php
                        $overlayLines = [];
                        foreach (['accent_line', 'match_line'] as $__k) {
                            $__t = trim((string) ($lockedTeaser[$__k] ?? ''));
                            if ($__t !== '') {
                                $overlayLines[] = $__t;
                            }
                        }
                        foreach ($lockedTeaser['lines'] ?? [] as $__ln) {
                            $__t = trim((string) $__ln);
                            if ($__t !== '') {
                                $overlayLines[] = $__t;
                            }
                        }
                    @endphp
                    @include('interests.partials.received-interest-photo-overlay', [
                        'interest' => $interest,
                        'plansUrl' => $plansUrlPanel,
                        'acceptLocked' => $acceptLocked,
                        'photoSrc' => $lockedTeaser['photo_url'] ?? $ph,
                        'useBlur' => true,
                        'blurPhotoClass' => (string) ($lockedTeaser['blur_photo_class'] ?? 'blur-md scale-110 opacity-90'),
                        'headline' => (string) ($lockedTeaser['headline'] ?? ''),
                        'subLines' => $overlayLines,
                        'summaryLine' => (string) ($lockedTeaser['viewed_summary'] ?? ''),
                        'sender' => null,
                        'showUpgradePrimary' => false,
                    ])
                @elseif ($unlocked)
                    @include('interests.partials.received-interest-photo-overlay', [
                        'interest' => $interest,
                        'plansUrl' => $plansUrlPanel,
                        'acceptLocked' => false,
                        'photoSrc' => $photoSrcUnlocked,
                        'useBlur' => false,
                        'blurPhotoClass' => '',
                        'headline' => __('interests.from').': '.($sender?->full_name ?? __('interests.profile_deleted')),
                        'subLines' => array_values(array_filter([$residenceLine])),
                        'summaryLine' => '',
                        'sender' => $sender,
                        'showUpgradePrimary' => false,
                    ])
                @else
                    @include('interests.partials.received-interest-photo-overlay', [
                        'interest' => $interest,
                        'plansUrl' => $plansUrlPanel,
                        'acceptLocked' => $interest->status === 'pending',
                        'photoSrc' => $ph,
                        'useBlur' => false,
                        'blurPhotoClass' => '',
                        'headline' => (string) __('interests.from_locked_title'),
                        'subLines' => [(string) __('interests.from_locked_body')],
                        'summaryLine' => '',
                        'sender' => null,
                        'showUpgradePrimary' => true,
                    ])
                @endif
            </div>
        @elseif ($useTeaserCard)
            <div class="flex min-h-0 flex-1 flex-col p-3 sm:p-4">
                @include('who-viewed.partials.viewer-row-teaser', [
                    'teaser' => $lockedTeaser,
                    'plansUrl' => $plansUrlPanel,
                    'cardLayout' => $teaserCardLayout,
                    'hideTeaserCtaColumn' => $isTwoColumn,
                ])
            </div>
            <div class="mt-auto shrink-0 border-t border-stone-200/80 bg-stone-50/90 px-4 py-3 dark:border-gray-600 dark:bg-gray-900/40 sm:px-5">
                <p class="text-sm">
                    <span class="text-gray-500 dark:text-gray-400">{{ __('interests.status') }}:</span>
                    @if ($interest->status === 'pending')
                        <span class="font-semibold text-amber-700 dark:text-amber-300">{{ __('interests.pending') }}</span>
                    @elseif ($interest->status === 'accepted')
                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ __('interests.accepted') }}</span>
                    @elseif ($interest->status === 'rejected')
                        <span class="font-semibold text-red-600 dark:text-red-400">{{ __('interests.rejected') }}</span>
                    @endif
                </p>
                @if ($interest->status === 'pending')
                    @include('interests.partials.received-interest-pending-actions', [
                        'interest' => $interest,
                        'plansUrl' => $plansUrlPanel,
                        'acceptLocked' => $acceptLocked,
                        'containerClass' => 'mt-3',
                    ])
                @endif
            </div>
        @elseif ($unlocked)
            @if (! $isVerticalCard)
                <div class="flex min-h-[10.5rem] flex-1 flex-row items-stretch">
                    <div class="relative w-32 shrink-0 self-stretch overflow-hidden bg-gradient-to-b from-stone-200 to-stone-300 dark:from-gray-800 dark:to-gray-900 sm:w-40">
                        <img src="{{ $photoSrcUnlocked }}" alt="" class="absolute inset-0 h-full w-full object-cover" loading="lazy">
                        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-black/35 to-transparent"></div>
                    </div>
                    <div class="flex min-w-0 flex-1 flex-col gap-2 border-l border-stone-100 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800/80 sm:px-5">
                        <p class="line-clamp-2 text-lg font-bold tracking-tight text-gray-900 dark:text-gray-50">
                            {{ __('interests.from') }}: {{ $sender?->full_name ?? __('interests.profile_deleted') }}
                        </p>
                        @if ($sender)
                            <p>
                                <a href="{{ route('matrimony.profile.show', $sender->id) }}"
                                   class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-3 py-1 text-sm font-semibold text-rose-700 ring-1 ring-rose-100 transition hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900/40 dark:hover:bg-rose-950/60">
                                    {{ __('interests.view_matrimony_profile') }}
                                    <span aria-hidden="true">→</span>
                                </a>
                            </p>
                        @endif
                    </div>
                </div>
                <div class="mt-auto shrink-0 border-t border-stone-200/80 bg-stone-50/90 px-4 py-3 dark:border-gray-600 dark:bg-gray-900/40 sm:px-5">
                    <p class="text-sm">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('interests.status') }}:</span>
                        @if ($interest->status === 'pending')
                            <span class="font-semibold text-amber-700 dark:text-amber-300">{{ __('interests.pending') }}</span>
                        @elseif ($interest->status === 'accepted')
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ __('interests.accepted') }}</span>
                        @elseif ($interest->status === 'rejected')
                            <span class="font-semibold text-red-600 dark:text-red-400">{{ __('interests.rejected') }}</span>
                        @endif
                    </p>
                    @if ($interest->status === 'pending')
                        @include('interests.partials.received-interest-pending-actions', [
                            'interest' => $interest,
                            'plansUrl' => $plansUrlPanel,
                            'acceptLocked' => false,
                            'containerClass' => 'mt-3',
                        ])
                    @endif
                </div>
            @else
                <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
                    <div class="relative mx-auto aspect-[3/4] w-full max-h-[min(78vh,520px)] max-w-md bg-stone-900 sm:mx-0 sm:max-h-[min(70vh,560px)] sm:max-w-none">
                        <img src="{{ $photoSrcUnlocked }}" alt="" class="h-full w-full object-contain object-center" loading="lazy">
                        <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/45 to-transparent pt-20 pb-3 sm:pt-24">
                            <div class="pointer-events-auto px-4">
                                <p class="text-lg font-bold leading-snug text-white drop-shadow-md sm:text-xl">
                                    {{ $sender?->full_name ?? __('interests.profile_deleted') }}
                                </p>
                                @if ($residenceLine !== '')
                                    <p class="mt-1 line-clamp-3 text-sm leading-snug text-white/90 drop-shadow">{{ $residenceLine }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex min-h-0 flex-1 flex-col border-t border-stone-100 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800/80 sm:px-5">
                        <div class="min-h-0 flex-1">
                            @if ($sender)
                                <p>
                                    <a href="{{ route('matrimony.profile.show', $sender->id) }}"
                                       class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-3 py-1 text-sm font-semibold text-rose-700 ring-1 ring-rose-100 transition hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900/40 dark:hover:bg-rose-950/60">
                                        {{ __('interests.view_matrimony_profile') }}
                                        <span aria-hidden="true">→</span>
                                    </a>
                                </p>
                            @endif
                            <p class="mt-2 text-sm">
                                <span class="text-gray-500 dark:text-gray-400">{{ __('interests.status') }}:</span>
                                @if ($interest->status === 'pending')
                                    <span class="font-semibold text-amber-700 dark:text-amber-300">{{ __('interests.pending') }}</span>
                                @elseif ($interest->status === 'accepted')
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ __('interests.accepted') }}</span>
                                @elseif ($interest->status === 'rejected')
                                    <span class="font-semibold text-red-600 dark:text-red-400">{{ __('interests.rejected') }}</span>
                                @endif
                            </p>
                        </div>
                        @if ($interest->status === 'pending')
                            <div class="mt-auto shrink-0 pt-3">
                                @include('interests.partials.received-interest-pending-actions', [
                                    'interest' => $interest,
                                    'plansUrl' => $plansUrlPanel,
                                    'acceptLocked' => false,
                                    'containerClass' => 'mt-0',
                                ])
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @else
            <div @class([
                'flex gap-5 p-5 sm:gap-7 sm:p-6',
                'flex-col items-center text-center' => $isVerticalCard,
                'flex-col sm:flex-row sm:items-stretch sm:text-left' => ! $isVerticalCard,
            ])>
                <div @class([
                    'relative shrink-0',
                    'mx-auto sm:mx-0' => ! $isVerticalCard,
                    'w-full max-w-sm' => $isVerticalCard,
                ])>
                    <div @class([
                        'relative overflow-hidden rounded-2xl bg-gradient-to-br from-gray-400 to-gray-600 shadow-inner dark:from-gray-600 dark:to-gray-800',
                        'h-28 w-28 sm:h-32 sm:w-32' => ! $isVerticalCard,
                        'h-44 w-full' => $isVerticalCard,
                    ])>
                        <div class="absolute inset-0 backdrop-blur-md bg-white/10"></div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <svg class="h-12 w-12 text-white/90" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                    </div>
                    <span class="absolute -bottom-1 -right-1 flex h-9 w-9 items-center justify-center rounded-full bg-amber-500 text-white shadow-lg ring-2 ring-white dark:ring-gray-800" title="{{ __('interests.locked_badge') }}">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                    </span>
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100">
                        {{ __('interests.from_locked_title') }}
                    </p>
                    <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        {{ __('interests.from_locked_body') }}
                    </p>
                    <p class="mt-4">
                        <a href="{{ route('plans.index') }}" class="inline-flex items-center gap-1 rounded-full bg-rose-600 px-4 py-2 text-sm font-bold text-white shadow-md transition hover:bg-rose-700">
                            {{ __('interests.unlock_with_membership') }} <span aria-hidden="true">→</span>
                        </a>
                    </p>

                    <p class="mt-4 text-sm">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('interests.status') }}:</span>
                        @if ($interest->status === 'pending')
                            <span class="font-semibold text-amber-700 dark:text-amber-300">{{ __('interests.pending') }}</span>
                        @elseif ($interest->status === 'accepted')
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ __('interests.accepted') }}</span>
                        @elseif ($interest->status === 'rejected')
                            <span class="font-semibold text-red-600 dark:text-red-400">{{ __('interests.rejected') }}</span>
                        @endif
                    </p>

                    @if ($interest->status === 'pending')
                        @include('interests.partials.received-interest-pending-actions', [
                            'interest' => $interest,
                            'plansUrl' => $plansUrlPanel,
                            'acceptLocked' => true,
                            'containerClass' => 'mt-4',
                        ])
                    @endif
                </div>
            </div>
        @endif
    </article>
@empty
    <p class="col-span-full text-center text-gray-600 dark:text-gray-400">
        {{ ($statusFilter ?? 'all') !== 'all' ? __('interests.no_received_for_filter') : __('interests.no_received_interests') }}
    </p>
@endforelse
</div>
