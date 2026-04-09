@props([
    'preferenceMatch' => [],
    'profile' => null,
])

@php
    $pm = is_array($preferenceMatch) ? $preferenceMatch : [];
    $viewer = auth()->user();
    $viewerProfile = $viewer?->matrimonyProfile;
    $viewerPhotoSrc = null;
    if ($viewerProfile && $viewerProfile->profile_photo && $viewerProfile->photo_approved !== false) {
        $viewerPhotoSrc = app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($viewerProfile->profile_photo);
    } else {
        $viewerGender = $viewerProfile->gender ?? $viewer->gender ?? null;
        if ($viewerGender === 'male') {
            $viewerPhotoSrc = asset('images/placeholders/male-profile.svg');
        } elseif ($viewerGender === 'female') {
            $viewerPhotoSrc = asset('images/placeholders/female-profile.svg');
        } else {
            $viewerPhotoSrc = asset('images/placeholders/default-profile.svg');
        }
    }
    $viewedPhotoSrc = null;
    if ($profile->profile_photo && $profile->photo_approved !== false) {
        $viewedPhotoSrc = app(\App\Services\Image\ProfilePhotoUrlService::class)->publicUrl($profile->profile_photo);
    } else {
        $viewedGender = $profile->gender?->key ?? $profile->gender;
        if ($viewedGender === 'male') {
            $viewedPhotoSrc = asset('images/placeholders/male-profile.svg');
        } elseif ($viewedGender === 'female') {
            $viewedPhotoSrc = asset('images/placeholders/female-profile.svg');
        } else {
            $viewedPhotoSrc = asset('images/placeholders/default-profile.svg');
        }
    }
    $fitKey = $pm['fit_badge'] ?? 'partial_fit';
    $fitLabel = match ($fitKey) {
        'strong_fit' => __('preference_match.fit_strong'),
        'good_fit' => __('preference_match.fit_good'),
        'needs_discussion' => __('preference_match.fit_needs_discussion'),
        default => __('preference_match.fit_partial'),
    };
    $fitClass = match ($fitKey) {
        'strong_fit' => 'bg-emerald-100/90 text-emerald-900 ring-1 ring-emerald-200/80 dark:bg-emerald-900/35 dark:text-emerald-100 dark:ring-emerald-700/60',
        'good_fit' => 'bg-sky-100/90 text-sky-900 ring-1 ring-sky-200/80 dark:bg-sky-900/35 dark:text-sky-100 dark:ring-sky-700/60',
        'needs_discussion' => 'bg-rose-100/90 text-rose-900 ring-1 ring-rose-200/80 dark:bg-rose-900/35 dark:text-rose-100 dark:ring-rose-800/60',
        default => 'bg-amber-100/90 text-amber-950 ring-1 ring-amber-200/80 dark:bg-amber-900/35 dark:text-amber-100 dark:ring-amber-800/60',
    };
    $counts = $pm['counts'] ?? ['match' => 0, 'flexible' => 0, 'not_matched' => 0, 'unknown' => 0];
    $targetHasPrefs = $pm['target_has_preferences'] ?? false;
    $unknownCount = (int) ($counts['unknown'] ?? 0);
@endphp

<div {{ $attributes->merge(['class' => 'mb-6 rounded-2xl border border-stone-200/80 bg-gradient-to-b from-stone-50/95 via-white to-white dark:border-gray-700 dark:from-gray-900/50 dark:via-gray-900/40 dark:to-gray-900/30 p-5 shadow-sm sm:p-7']) }}>
    <header class="mb-6 text-center sm:mb-8">
        <h3 class="text-xl font-semibold tracking-tight text-stone-900 dark:text-stone-100 sm:text-2xl">{{ __('preference_match.title') }}</h3>
        <p class="mx-auto mt-2 max-w-2xl text-sm leading-relaxed text-stone-600 dark:text-stone-400">{{ __('preference_match.subtitle') }}</p>
    </header>

    <div class="mb-8 flex flex-col items-center justify-center gap-6 sm:flex-row sm:gap-8">
        <div class="flex flex-col items-center text-center">
            <img src="{{ $viewedPhotoSrc }}" alt="" class="h-16 w-16 rounded-full border-2 border-stone-300 object-cover dark:border-stone-600 sm:h-[4.5rem] sm:w-[4.5rem]" width="72" height="72" />
            <span class="mt-2 max-w-[10rem] truncate text-xs text-stone-600 dark:text-stone-400">{{ $profile->full_name }}</span>
        </div>
        <span class="text-stone-300 dark:text-stone-600" aria-hidden="true">
            <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
        </span>
        <div class="flex flex-col items-center text-center">
            <img src="{{ $viewerPhotoSrc }}" alt="" class="h-16 w-16 rounded-full border-2 border-stone-300 object-cover dark:border-stone-600 sm:h-[4.5rem] sm:w-[4.5rem]" width="72" height="72" />
            <span class="mt-2 text-xs text-stone-600 dark:text-stone-400">{{ __('preference_match.your_label') }}</span>
        </div>
    </div>

    @if (! $targetHasPrefs)
        <div class="rounded-xl border border-dashed border-stone-300/90 bg-stone-50/80 px-5 py-10 text-center dark:border-gray-600 dark:bg-gray-800/40">
            <p class="text-base font-medium text-stone-900 dark:text-stone-100">{{ __('preference_match.empty_state_title') }}</p>
            <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-stone-600 dark:text-stone-400">{{ __('preference_match.empty_state_body') }}</p>
        </div>
    @else
        <p class="mb-4 text-center text-xs text-stone-500 dark:text-stone-500">{{ __('preference_match.summary_note') }}</p>

        <div class="mb-5 flex flex-wrap justify-center gap-2 sm:gap-2.5">
            <span class="inline-flex min-w-0 max-w-full items-center gap-2 rounded-full border border-emerald-200/90 bg-emerald-50/95 px-3 py-1.5 text-xs font-medium text-emerald-900 shadow-sm dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-100 sm:text-[13px]">
                <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-500 dark:bg-emerald-400" aria-hidden="true"></span>
                <span class="min-w-0 break-words">{{ __('preference_match.pill_matched') }} · {{ $counts['match'] ?? 0 }}</span>
            </span>
            <span class="inline-flex min-w-0 max-w-full items-center gap-2 rounded-full border border-amber-200/90 bg-amber-50/95 px-3 py-1.5 text-xs font-medium text-amber-950 shadow-sm dark:border-amber-800/60 dark:bg-amber-950/35 dark:text-amber-100 sm:text-[13px]">
                <span class="h-2 w-2 shrink-0 rounded-full bg-amber-500 dark:bg-amber-400" aria-hidden="true"></span>
                <span class="min-w-0 break-words">{{ __('preference_match.pill_flexible') }} · {{ $counts['flexible'] ?? 0 }}</span>
            </span>
            <span class="inline-flex min-w-0 max-w-full items-center gap-2 rounded-full border border-rose-200/90 bg-rose-50/95 px-3 py-1.5 text-xs font-medium text-rose-900 shadow-sm dark:border-rose-800/60 dark:bg-rose-950/40 dark:text-rose-100 sm:text-[13px]">
                <span class="h-2 w-2 shrink-0 rounded-full bg-rose-500 dark:bg-rose-400" aria-hidden="true"></span>
                <span class="min-w-0 break-words">{{ __('preference_match.pill_not_matched') }} · {{ $counts['not_matched'] ?? 0 }}</span>
            </span>
            <span class="inline-flex min-w-0 max-w-full items-center gap-2 rounded-full border border-stone-200 bg-stone-100/95 px-3 py-1.5 text-xs font-medium text-stone-800 shadow-sm dark:border-gray-600 dark:bg-gray-800/80 dark:text-stone-200 sm:text-[13px]">
                <span class="h-2 w-2 shrink-0 rounded-full bg-stone-400 dark:bg-stone-500" aria-hidden="true"></span>
                <span class="min-w-0 break-words">{{ __('preference_match.pill_unknown') }} · {{ $unknownCount }}</span>
            </span>
        </div>

        <div class="mb-6 flex justify-center">
            <span class="inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold {{ $fitClass }}">{{ $fitLabel }}</span>
        </div>

        @if ($unknownCount > 0)
            <p class="mb-6 rounded-lg border border-stone-200/80 bg-stone-50/90 px-4 py-3 text-center text-xs leading-relaxed text-stone-600 dark:border-gray-700 dark:bg-gray-800/50 dark:text-stone-400">{{ __('preference_match.unknown_comparison_note') }}</p>
        @endif

        @if ($pm['viewer_profile_incomplete'] ?? false)
            <p class="mb-6 rounded-lg border border-indigo-200/80 bg-indigo-50/90 px-4 py-3 text-center text-sm text-indigo-900 dark:border-indigo-800/60 dark:bg-indigo-950/40 dark:text-indigo-200">{{ __('preference_match.complete_profile_hint') }}</p>
        @endif

        @php
            $groupLabels = [
                'basic' => __('preference_match.group_basic'),
                'community' => __('preference_match.group_community'),
                'location' => __('preference_match.group_location'),
                'education_career' => __('preference_match.group_education_career'),
                'lifestyle' => __('preference_match.group_lifestyle'),
            ];
            $strictLabels = [
                'open' => __('preference_match.strict_open'),
                'preferred' => __('preference_match.strict_preferred'),
                'must_match' => __('preference_match.strict_must_match'),
            ];
            $statusLabels = [
                'match' => __('preference_match.status_match'),
                'flexible' => __('preference_match.status_flexible'),
                'not_matched' => __('preference_match.status_not_matched'),
                'unknown' => __('preference_match.status_unknown'),
            ];
            $borderAccent = [
                'match' => 'border-l-emerald-500',
                'flexible' => 'border-l-amber-400',
                'not_matched' => 'border-l-rose-400',
                'unknown' => 'border-l-stone-400 dark:border-l-stone-500',
            ];
            $statusRowClass = [
                'match' => 'border-emerald-200/90 bg-emerald-50/50 dark:border-emerald-800/50 dark:bg-emerald-950/25',
                'flexible' => 'border-amber-200/90 bg-amber-50/40 dark:border-amber-800/50 dark:bg-amber-950/25',
                'not_matched' => 'border-rose-200/90 bg-rose-50/35 dark:border-rose-800/50 dark:bg-rose-950/25',
                'unknown' => 'border-stone-200/90 bg-stone-50/70 dark:border-gray-600 dark:bg-gray-800/45',
            ];
            $statusBadgeClass = [
                'match' => 'bg-emerald-600/95 text-white dark:bg-emerald-700',
                'flexible' => 'bg-amber-500/95 text-white dark:bg-amber-600',
                'not_matched' => 'bg-rose-600/95 text-white dark:bg-rose-700',
                'unknown' => 'bg-stone-500 text-white dark:bg-gray-600',
            ];
        @endphp

        <div class="space-y-8">
            @foreach (($pm['groups'] ?? []) as $gKey => $rows)
                @if (empty($rows))
                    @continue
                @endif
                <div>
                    <h4 class="mb-3 text-xs font-semibold uppercase tracking-wider text-stone-500 dark:text-stone-400">{{ $groupLabels[$gKey] ?? $gKey }}</h4>
                    <div class="space-y-3">
                        @foreach ($rows as $row)
                            @php
                                $st = $row['status'] ?? 'unknown';
                                $rc = $statusRowClass[$st] ?? $statusRowClass['unknown'];
                                $ba = $borderAccent[$st] ?? $borderAccent['unknown'];
                                $sb = $statusBadgeClass[$st] ?? $statusBadgeClass['unknown'];
                            @endphp
                            <div class="rounded-xl border border-stone-200/80 {{ $rc }} border-l-4 {{ $ba }} p-4 shadow-sm dark:border-gray-700/80 sm:p-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold leading-snug text-stone-900 dark:text-stone-100">{{ $row['label'] ?? '' }}</p>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <span class="inline-flex items-center rounded-md bg-white/90 px-2 py-0.5 text-[11px] font-medium text-stone-700 ring-1 ring-stone-200/90 dark:bg-gray-800/80 dark:text-stone-300 dark:ring-gray-600">{{ $strictLabels[$row['strictness'] ?? 'open'] ?? $row['strictness'] }}</span>
                                        </div>
                                    </div>
                                    <div class="shrink-0">
                                        <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold {{ $sb }}">{{ $statusLabels[$st] ?? $st }}</span>
                                    </div>
                                </div>
                                <dl class="mt-4 grid grid-cols-1 gap-4 border-t border-stone-200/60 pt-4 dark:border-gray-700/80 sm:grid-cols-2">
                                    <div class="min-w-0">
                                        <dt class="text-[11px] font-medium uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('preference_match.their_label') }}</dt>
                                        <dd class="mt-1 break-words text-sm font-medium leading-relaxed text-stone-900 dark:text-stone-100">{{ $row['their_preference'] ?? '—' }}</dd>
                                    </div>
                                    <div class="min-w-0">
                                        <dt class="text-[11px] font-medium uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ __('preference_match.your_label') }}</dt>
                                        <dd class="mt-1 break-words text-sm font-medium leading-relaxed text-stone-900 dark:text-stone-100">{{ $row['your_value'] ?? '—' }}</dd>
                                    </div>
                                </dl>
                                @if (! empty($row['reason']))
                                    <p class="mt-3 rounded-md bg-white/60 px-3 py-2 text-xs leading-relaxed text-stone-600 ring-1 ring-stone-200/70 dark:bg-gray-900/40 dark:text-stone-400 dark:ring-gray-600/80">{{ $row['reason'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @php
        $topics = $pm['discussion_topics'] ?? [];
    @endphp
    @if ($targetHasPrefs && ! empty($topics))
        <div class="mt-8 border-t border-stone-200/80 pt-6 dark:border-gray-700">
            <h4 class="mb-3 text-sm font-semibold text-stone-900 dark:text-stone-100">{{ __('preference_match.discussion_title') }}</h4>
            <ul class="list-inside list-disc space-y-1.5 text-sm leading-relaxed text-stone-700 dark:text-stone-300">
                @foreach ($topics as $t)
                    <li class="pl-0.5">{{ $t }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($pm['helper_text'] ?? ''))
        <p class="mt-6 text-center text-sm leading-relaxed text-stone-600 dark:text-stone-400">{{ $pm['helper_text'] }}</p>
    @endif

    @if (isset($actions))
        <div class="mt-8 border-t border-stone-200/80 bg-stone-50/50 px-0 pt-6 dark:border-gray-700 dark:bg-gray-900/20 sm:-mx-2 sm:rounded-b-xl sm:px-2">
            @if ($targetHasPrefs)
                @if (in_array($fitKey, ['strong_fit', 'good_fit'], true))
                    <p class="mb-4 text-center text-xs text-stone-500 dark:text-stone-400">{{ __('preference_match.cta_helper_strong') }}</p>
                @else
                    <p class="mb-4 text-center text-xs text-stone-500 dark:text-stone-400">{{ __('preference_match.cta_helper_partial') }}</p>
                @endif
            @else
                <p class="mb-4 text-center text-xs text-stone-500 dark:text-stone-400">{{ __('preference_match.cta_helper_no_prefs') }}</p>
            @endif
            <div class="flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:flex-wrap sm:justify-center">
                {{ $actions }}
            </div>
        </div>
    @endif
</div>
