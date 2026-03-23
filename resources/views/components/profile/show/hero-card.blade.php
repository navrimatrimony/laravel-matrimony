@props([
    'profile',
    'profilePhotoVisible' => true,
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
    'verificationItems' => [],
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
    if ($age !== null && ($dateOfBirthVisible ?? true)) {
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
    if ($profile->maritalStatus && ($profile->maritalStatus->label ?? '') !== '') {
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
    $trustItems = is_array($verificationItems) ? array_values(array_filter($verificationItems)) : [];
@endphp

@php
    $chipClass = 'inline-flex items-center rounded-full bg-white/90 px-3 py-1.5 text-[11px] font-medium text-stone-800 shadow-sm ring-1 ring-stone-200/80 dark:bg-stone-800/90 dark:text-stone-100 dark:ring-stone-600/50 sm:text-xs';
@endphp

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-3xl bg-white shadow-[0_16px_40px_-12px_rgba(28,25,23,0.14)] ring-1 ring-stone-200/70 dark:bg-gray-900 dark:shadow-[0_16px_36px_-12px_rgba(0,0,0,0.38)] dark:ring-gray-700/75']) }}>
    @if ($profilePhotoVisible)
        <div class="relative aspect-[4/5] max-h-[24rem] w-full bg-stone-100 dark:bg-gray-800 landscape:max-h-[min(52vh,20rem)] md:max-h-[22rem] xl:max-h-[24rem]">
            @if ($profile->profile_photo)
                <img
                    src="{{ asset('uploads/matrimony_photos/'.$profile->profile_photo) }}"
                    alt=""
                    class="h-full w-full object-cover"
                    style="{{ $photoLocked ? 'filter: blur(10px); transform: scale(1.04);' : '' }}"
                />
            @else
                @php
                    $genderKey = $profile->gender?->key ?? $profile->gender;
                    $placeholderSrc = $genderKey === 'male'
                        ? asset('images/placeholders/male-profile.svg')
                        : ($genderKey === 'female' ? asset('images/placeholders/female-profile.svg') : asset('images/placeholders/default-profile.svg'));
                @endphp
                <img src="{{ $placeholderSrc }}" alt="" class="h-full w-full object-cover" style="{{ $photoLocked ? 'filter: blur(10px);' : '' }}" />
            @endif
            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-[52%] bg-gradient-to-t {{ ($hideOverlayIdentity ?? false) ? 'from-stone-950/35 via-stone-900/12 to-transparent dark:from-black/40 dark:via-stone-950/10' : 'from-stone-950/60 via-stone-900/25 to-transparent dark:from-black/65 dark:via-stone-950/20' }}" aria-hidden="true"></div>
            @if (!($hideOverlayIdentity ?? false))
            <div class="absolute inset-x-0 bottom-0 z-10 px-5 pb-5 pt-20 sm:px-6 sm:pb-6">
                <h2 class="text-2xl font-semibold leading-tight tracking-tight text-white drop-shadow-sm sm:text-[1.65rem]">{{ \App\Support\ProfileDisplayCopy::formatPersonName($profile->full_name) }}</h2>
                @if ($summaryLine !== '')
                    <p class="mt-2 line-clamp-2 text-sm font-medium leading-snug text-white/95">{{ $summaryLine }}</p>
                @endif
                @if ($metaLine !== '')
                    <p class="mt-2 text-xs font-medium tracking-wide text-white/70">{{ $metaLine }}</p>
                @endif
            </div>
            @endif
            @if ($photoLocked)
                <div class="absolute inset-0 z-20 flex items-center justify-center bg-black/30 px-4 backdrop-blur-[2px]">
                    <div class="max-w-xs text-center text-white">
                        <p class="mb-3 text-sm font-semibold">{{ __('profile.profile_photo') }} — private</p>
                        @if (($photoLockMode ?? 'all') === 'premium')
                            @if (! $contactRequestDisabled && $contactRequestState !== null && auth()->check())
                                <button type="button" @click="$root.openRequestModal = true" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm">{{ __('Request Contact') }}</button>
                            @endif
                        @else
                            @if (! $interestAlreadySent && auth()->check())
                                <form method="POST" action="{{ route('interests.send', $profile) }}" class="inline">@csrf
                                    <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-medium text-white shadow-sm">{{ __('Send Interest') }}</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @else
        <div class="bg-gradient-to-br from-stone-50 to-stone-100/90 px-5 py-7 dark:from-gray-800 dark:to-gray-900 sm:px-6">
            <h2 class="text-2xl font-semibold leading-tight tracking-tight text-stone-900 dark:text-stone-100 sm:text-[1.65rem]">{{ \App\Support\ProfileDisplayCopy::formatPersonName($profile->full_name) }}</h2>
            @if ($summaryLine !== '')
                <p class="mt-2 line-clamp-2 text-sm font-medium leading-snug text-stone-700 dark:text-stone-300">{{ $summaryLine }}</p>
            @endif
            @if ($metaLine !== '')
                <p class="mt-2 text-xs font-medium text-stone-500 dark:text-stone-400">{{ $metaLine }}</p>
            @endif
        </div>
    @endif

    @if (! empty($chips))
        <div class="border-t border-stone-200/80 bg-stone-50/90 px-4 py-3.5 dark:border-gray-700/80 dark:bg-gray-950/80 sm:px-5">
            <div class="flex flex-wrap gap-2">
                @foreach ($chips as $chip)
                    <span class="{{ $chipClass }}">{{ $chip }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if (count($trustItems) > 0)
        <div class="border-t border-stone-200/80 bg-white px-4 py-3 dark:border-gray-700/80 dark:bg-gray-950/90 sm:px-5">
            <p class="mb-2 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('profile.show_hero_trust_heading') }}</p>
            <div class="flex flex-wrap gap-x-3 gap-y-2">
                @foreach ($trustItems as $item)
                    <span class="inline-flex min-w-0 max-w-full items-center gap-1.5 text-xs font-medium text-stone-800 dark:text-stone-200">
                        <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300" aria-hidden="true">
                            <svg class="h-2.5 w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <span class="break-words leading-snug">{{ $item['label'] ?? '' }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif
</div>
