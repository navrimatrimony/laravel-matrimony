@props([
    'profile',
    'profilePhotoVisible' => true,
    'galleryPhotos' => null,
    'photoLocked' => false,
    'photoLockMode' => 'all',
    'interestAlreadySent' => false,
    'contactRequestDisabled' => true,
    'contactRequestState' => null,
    'headline' => '',
    'dateOfBirthVisible' => true,
    'heightVisible' => true,
    'locationVisible' => true,
    'educationVisible' => true,
    'hideOverlayIdentity' => false,
])

@php
    $profile->loadMissing(['gender', 'city', 'district', 'state', 'maritalStatus', 'religion', 'caste', 'profession']);
    $age = null;
    if (! empty($profile->date_of_birth)) {
        try {
            $age = \Carbon\Carbon::parse($profile->date_of_birth)->age;
        } catch (\Throwable) {
            $age = null;
        }
    }
    $chips = [];
    if ($age !== null && ($dateOfBirthVisible ?? true) && ($hideOverlayIdentity ?? false)) {
        $chips[] = __('profile.show_age_years', ['age' => $age]);
    }
    if (($profile->height_cm ?? 0) > 0 && ($heightVisible ?? true)) {
        $chips[] = $profile->height_cm.' cm';
    }
    if ($profile->religion) {
        $chips[] = $profile->religion->label ?? '';
    }
    if ($profile->caste) {
        $chips[] = $profile->caste->label ?? '';
    }
    $locShort = trim(implode(', ', array_filter([$profile->city?->name, $profile->district?->name])));
    if ($locShort !== '' && ($locationVisible ?? true)) {
        $chips[] = $locShort;
    }
    if (($profile->highest_education ?? '') !== '' && ($educationVisible ?? true)) {
        $eduChip = \App\Support\ProfileDisplayCopy::formatEducationPhrase($profile->highest_education);
        $chips[] = $eduChip !== null ? \Illuminate\Support\Str::limit($eduChip, 48) : \Illuminate\Support\Str::limit($profile->highest_education, 48);
    }
    if ($profile->maritalStatus && ($profile->maritalStatus->label ?? '') !== '' && ($hideOverlayIdentity ?? false)) {
        $chips[] = $profile->maritalStatus->label;
    }
    $chips = array_values(array_filter(array_slice($chips, 0, 5)));

    $summaryParts = [];
    if (($educationVisible ?? true) && ($profile->highest_education ?? '') !== '') {
        $eduS = \App\Support\ProfileDisplayCopy::formatEducationPhrase(trim($profile->highest_education));
        if ($eduS !== null) {
            $summaryParts[] = \Illuminate\Support\Str::limit($eduS, 40);
        }
    }
    if (($profile->occupation_title ?? '') !== '') {
        $summaryParts[] = \Illuminate\Support\Str::limit(
            \App\Support\ProfileDisplayCopy::formatOccupationPhrase(trim($profile->occupation_title)),
            32
        );
    } elseif ($profile->profession && ($profile->profession->name ?? '') !== '') {
        $summaryParts[] = \Illuminate\Support\Str::limit(
            \App\Support\ProfileDisplayCopy::formatOccupationPhrase($profile->profession->name),
            32
        );
    }
    $locIdentity = \App\Support\ProfileDisplayCopy::compactLocationLine(
        $profile->city?->name,
        $profile->district?->name,
        $profile->state?->name
    );
    if ($locIdentity !== '' && ($locationVisible ?? true)) {
        $summaryParts[] = $locIdentity;
    }
    $summaryParts = array_slice(array_values(array_filter($summaryParts)), 0, 3);
    $builtSummary = implode(' • ', $summaryParts);
    $summaryLine = $builtSummary !== ''
        ? $builtSummary
        : ($headline !== '' ? str_replace([' · ', '·'], ' • ', $headline) : '');

    $metaRaw = trim((string) ($profile->gender?->label ?? $profile->user?->gender ?? ''));
    $metaLine = $metaRaw !== '' ? \Illuminate\Support\Str::title(mb_strtolower($metaRaw)) : '';

    $galleryItems = collect($galleryPhotos ?? [])->filter(function ($p) {
        return ! empty($p->file_path);
    })->values();
    $primaryPhotoUrl = $profile->profile_photo ? asset('uploads/matrimony_photos/'.$profile->profile_photo) : null;

    $albumPhotoUrls = [];
    if ($primaryPhotoUrl) {
        $albumPhotoUrls[] = $primaryPhotoUrl;
    }
    foreach ($galleryItems as $p) {
        $albumPhotoUrls[] = asset('uploads/matrimony_photos/'.$p->file_path);
    }
    $albumPhotoUrls = array_values(array_unique($albumPhotoUrls));
    $albumTotal = count($albumPhotoUrls);
    $extraCount = max(0, $albumTotal - 1);
    $isSinglePhoto = $albumTotal <= 1;
    $canBrowseAlbum = ! $photoLocked && $albumTotal > 1;
    $showLockedTeaser = $photoLocked && $albumTotal > 1;
@endphp

@php
    $chipClass = 'inline-flex max-w-full items-center truncate rounded-full bg-white/95 px-2.5 py-1 text-[10px] font-medium text-stone-800/95 shadow-sm ring-1 ring-stone-200/65 transition-colors duration-200 dark:bg-stone-800/95 dark:text-stone-100 dark:ring-stone-600/40 sm:text-[11px] lg:px-2.5 lg:py-1 lg:text-[10px]';
@endphp

<div {{ $attributes->merge(['class' => 'group/hero overflow-hidden rounded-3xl lg:rounded-2xl bg-white shadow-[0_6px_24px_-8px_rgba(28,25,23,0.11)] ring-1 ring-stone-200/60 transition-[box-shadow,ring-color] duration-300 ease-out dark:bg-gray-900 dark:shadow-[0_8px_28px_-10px_rgba(0,0,0,0.32)] dark:ring-gray-700/70 lg:hover:shadow-[0_12px_36px_-12px_rgba(28,25,23,0.14)] lg:hover:ring-stone-300/55 dark:lg:hover:ring-gray-600/65']) }}>
    @if ($profilePhotoVisible)
        <div
            class="relative aspect-[4/5] max-h-[24rem] w-full overflow-hidden bg-stone-100 dark:bg-gray-800 landscape:max-h-[min(52vh,20rem)] md:max-h-[22rem] lg:max-h-[12.5rem] xl:max-h-[13.5rem]"
            x-data="{ currentPhoto: 0, photos: @js($albumPhotoUrls), count: {{ $albumTotal }} }"
        >
            @php
                $genderKey = $profile->gender?->key ?? $profile->gender;
                $placeholderSrc = $genderKey === 'male'
                    ? asset('images/placeholders/male-profile.svg')
                    : ($genderKey === 'female' ? asset('images/placeholders/female-profile.svg') : asset('images/placeholders/default-profile.svg'));
            @endphp
            @if ($albumTotal > 0)
                <img
                    :src="photos[currentPhoto]"
                    alt=""
                    class="h-full w-full object-cover transition duration-500 ease-out group-hover/hero:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover/hero:scale-100"
                />
            @else
                <img src="{{ $placeholderSrc }}" alt="" class="h-full w-full object-cover transition duration-500 ease-out group-hover/hero:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover/hero:scale-100" />
            @endif
            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-[62%] bg-gradient-to-t {{ ($hideOverlayIdentity ?? false) ? 'from-stone-950/35 via-stone-900/12 to-transparent dark:from-black/40 dark:via-stone-950/10' : 'from-black/92 via-black/55 to-transparent dark:from-black/95 dark:via-black/60' }}" aria-hidden="true"></div>
            @if (!($hideOverlayIdentity ?? false))
            {{-- Photo overlay: marital + age one line at bottom (no name / no city) --}}
            <div class="absolute inset-x-0 bottom-0 z-10 px-4 pb-4 pt-12 sm:px-5 sm:pb-5 sm:pt-14">
                @php
                    $overlayMarital = ($profile->maritalStatus && ($profile->maritalStatus->label ?? '') !== '') ? (string) $profile->maritalStatus->label : '';
                    $overlayAgeLine = ($age !== null && ($dateOfBirthVisible ?? true)) ? (string) __('profile.show_age_years', ['age' => $age]) : '';
                @endphp
                @if ($overlayMarital !== '' || $overlayAgeLine !== '')
                    <p class="text-sm font-semibold leading-snug text-white drop-shadow-[0_2px_12px_rgba(0,0,0,0.88)] sm:text-base">
                        @if ($overlayMarital !== '')
                            <span class="font-bold">{{ $overlayMarital }}</span>
                        @endif
                        @if ($overlayMarital !== '' && $overlayAgeLine !== '')
                            <span class="mx-1.5 text-white/85" aria-hidden="true">·</span>
                        @endif
                        @if ($overlayAgeLine !== '')
                            <span class="tabular-nums text-white/95">{{ $overlayAgeLine }}</span>
                        @endif
                    </p>
                @endif
            </div>
            @endif
            @if ($photoLocked)
                <div class="absolute inset-0 z-20 flex items-center justify-center bg-black/24 px-4">
                    <div class="max-w-xs rounded-xl border border-white/25 bg-black/35 px-4 py-3 text-center text-white backdrop-blur-[2px]">
                        <p class="mb-1 text-sm font-semibold">{{ __('profile.profile_photo') }} — private album</p>
                        @if ($extraCount > 0)
                            <p class="mb-3 text-xs text-white/85">This profile has {{ $extraCount }} more photo{{ $extraCount > 1 ? 's' : '' }}. Full album is restricted.</p>
                        @endif
                        @if (($photoLockMode ?? 'all') === 'premium')
                            @if (! $contactRequestDisabled && $contactRequestState !== null && auth()->check())
                                <button type="button" @click="$root.openRequestModal = true" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm">{{ __('View full album') }}</button>
                            @endif
                        @else
                            @if (! $interestAlreadySent && auth()->check())
                                <form method="POST" action="{{ route('interests.send', $profile) }}" class="inline">@csrf
                                    <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-medium text-white shadow-sm">{{ __('Unlock album') }}</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            @endif

            @if ($albumTotal > 1)
                <div class="absolute left-3 top-3 z-20 inline-flex items-center gap-1 rounded-full border border-white/50 bg-white/80 px-2.5 py-1 text-[11px] font-semibold text-stone-700 shadow-sm backdrop-blur dark:border-gray-600/70 dark:bg-gray-900/80 dark:text-stone-100">
                    @if ($showLockedTeaser)
                        <span>🔒 Album locked</span>
                    @else
                        <span>Album</span>
                    @endif
                </div>
                <div class="absolute bottom-3 left-1/2 z-20 -translate-x-1/2 rounded-full border border-white/40 bg-black/45 px-2.5 py-1 text-[11px] font-semibold text-white shadow-sm backdrop-blur">
                    @if ($showLockedTeaser)
                        Album locked • <span x-text="`${currentPhoto + 1} of ${count}`"></span>
                    @else
                        <span x-text="`${currentPhoto + 1} of ${count}`"></span>
                    @endif
                </div>
            @endif

            @if ($albumTotal > 1)
                <button
                    type="button"
                    class="absolute left-3 bottom-11 z-20 rounded-full border border-white/35 bg-black/35 p-2 text-white shadow-md backdrop-blur transition hover:bg-black/50 disabled:cursor-not-allowed disabled:opacity-40"
                    @click="if ({{ $canBrowseAlbum ? 'true' : 'false' }}) { currentPhoto = (currentPhoto - 1 + count) % count }"
                    @if (!$canBrowseAlbum) disabled @endif
                    aria-label="Previous photo"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button
                    type="button"
                    class="absolute right-3 bottom-11 z-20 rounded-full border border-white/35 bg-black/35 p-2 text-white shadow-md backdrop-blur transition hover:bg-black/50 disabled:cursor-not-allowed disabled:opacity-40"
                    @click="if ({{ $canBrowseAlbum ? 'true' : 'false' }}) { currentPhoto = (currentPhoto + 1) % count }"
                    @if (!$canBrowseAlbum) disabled @endif
                    aria-label="Next photo"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M9 5l7 7-7 7"/></svg>
                </button>
            @endif

            @if ($showLockedTeaser)
                <div class="pointer-events-none absolute right-3 top-12 z-10">
                    <div class="relative h-16 w-12 rotate-6 rounded-lg border border-white/35 bg-white/20 shadow-sm backdrop-blur-sm"></div>
                    <div class="absolute -left-6 top-1 h-16 w-12 -rotate-6 rounded-lg border border-white/25 bg-white/15 shadow-sm backdrop-blur-sm"></div>
                </div>
            @endif
        </div>
    @else
        <div class="bg-gradient-to-br from-stone-50 to-stone-100/90 px-5 py-7 dark:from-gray-800 dark:to-gray-900 sm:px-6">
            <h2 class="text-2xl font-extrabold uppercase leading-tight tracking-tight text-stone-900 dark:text-stone-100 sm:text-3xl break-words">{{ \App\Support\ProfileDisplayCopy::formatPersonName($profile->full_name) }}</h2>
            @if ($summaryLine !== '')
                <p class="mt-2 line-clamp-2 text-sm font-medium leading-snug text-stone-700 dark:text-stone-300">{{ $summaryLine }}</p>
            @endif
            @if ($metaLine !== '')
                <p class="mt-2 text-xs font-medium text-stone-500 dark:text-stone-400">{{ $metaLine }}</p>
            @endif
        </div>
    @endif

    @if (! empty($chips))
        <div class="border-t border-stone-200/70 bg-gradient-to-b from-stone-50/98 to-stone-50/90 px-3 py-2.5 dark:border-gray-700/70 dark:from-gray-950/90 dark:to-gray-950/80 sm:px-4 lg:px-3.5 lg:py-2">
            <div class="flex flex-wrap gap-1.5 lg:gap-1.5">
                @foreach ($chips as $chip)
                    <span class="{{ $chipClass }}">{{ $chip }}</span>
                @endforeach
            </div>
        </div>
    @endif
</div>
