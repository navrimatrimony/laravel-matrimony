@props([
    'profile',
    'profilePhotoVisible' => true,
    /** @var array{slots?: list<array{url: string, blur: bool}>, message_key?: ?string}|null */
    'photoAlbumPresentation' => null,
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

    $presentation = is_array($photoAlbumPresentation) ? $photoAlbumPresentation : null;
    $slotsFromPresentation = $presentation['slots'] ?? null;
    if (is_array($slotsFromPresentation) && count($slotsFromPresentation) > 0) {
        $albumPhotoSlots = collect($slotsFromPresentation)->values();
    } else {
        $galleryItems = collect($galleryPhotos ?? [])->filter(function ($p) {
            return ! empty($p->file_path);
        })->values();
        $primaryPhotoUrl = ($profile->profile_photo && $profile->photo_approved !== false)
            ? asset('uploads/matrimony_photos/'.$profile->profile_photo)
            : null;
        $albumPhotoSlots = collect();
        if ($primaryPhotoUrl) {
            $albumPhotoSlots->push(['url' => $primaryPhotoUrl, 'blur' => false]);
        }
        foreach ($galleryItems as $p) {
            $albumPhotoSlots->push(['url' => asset('uploads/matrimony_photos/'.$p->file_path), 'blur' => false]);
        }
    }
    $albumTotal = $albumPhotoSlots->count();
    $extraCount = max(0, $albumTotal - 1);
    $isSinglePhoto = $albumTotal <= 1;
    $canBrowseAlbum = ! $photoLocked && $albumTotal > 1;
    $showLockedTeaser = $photoLocked && $albumTotal > 1;

    $photoBlurTier = (is_array($presentation) && isset($presentation['tier']))
        ? (string) $presentation['tier']
        : 'own_profile';
@endphp

@php
    $chipClass = 'inline-flex max-w-full items-center truncate rounded-full bg-white/95 px-2.5 py-1 text-[10px] font-medium text-stone-800/95 shadow-sm ring-1 ring-stone-200/65 transition-colors duration-200 dark:bg-stone-800/95 dark:text-stone-100 dark:ring-stone-600/40 sm:text-[11px] lg:px-2.5 lg:py-1 lg:text-[10px]';
@endphp

<div {{ $attributes->merge(['class' => 'group/hero overflow-hidden rounded-3xl lg:rounded-2xl bg-white shadow-[0_6px_24px_-8px_rgba(28,25,23,0.11)] ring-1 ring-stone-200/60 transition-[box-shadow,ring-color] duration-300 ease-out dark:bg-gray-900 dark:shadow-[0_8px_28px_-10px_rgba(0,0,0,0.32)] dark:ring-gray-700/70 lg:hover:shadow-[0_12px_36px_-12px_rgba(28,25,23,0.14)] lg:hover:ring-stone-300/55 dark:lg:hover:ring-gray-600/65']) }}>
    @if ($profilePhotoVisible)
        <div
            class="relative aspect-[4/5] max-h-[24rem] w-full overflow-hidden bg-stone-100 dark:bg-gray-800 landscape:max-h-[min(52vh,20rem)] md:max-h-[22rem] lg:max-h-[12.5rem] xl:max-h-[13.5rem]"
            x-data="{
                currentPhoto: 0,
                photos: @js($albumPhotoSlots->values()->all()),
                count: {{ $albumTotal }},
                blurTier: @js($photoBlurTier),
                photoInfoOpen: false,
                msgPremium: @js(__('upgrade_nudge.photo_premium')),
                msgUpload: @js(__('upgrade_nudge.photo_upload')),
                labelUpgrade: @js(__('subscriptions.pricing_cta_upgrade')),
                labelUpload: @js(__('profile.photo_overlay_cta_upload')),
                overlayPremium: @js(__('profile.photo_overlay_premium_required')),
                overlayUpload: @js(__('profile.photo_overlay_upload_to_unlock')),
                subPremium: @js(__('profile.photo_overlay_sub_premium')),
                subUpload: @js(__('profile.photo_overlay_sub_upload')),
                ctaPremium: @js(__('profile.photo_overlay_cta_plans')),
                ctaUpload: @js(__('profile.photo_overlay_cta_upload')),
                plansUrl: @js(route('plans.index')),
                uploadUrl: @js(route('matrimony.profile.upload-photo')),
                blurHeadline() {
                    return this.blurTier === 'free_own_photo' ? this.overlayPremium : this.overlayUpload;
                },
                blurSub() {
                    return this.blurTier === 'free_own_photo' ? this.subPremium : this.subUpload;
                },
                blurCtaLabel() {
                    return this.blurTier === 'free_own_photo' ? this.ctaPremium : this.ctaUpload;
                },
                blurCtaUrl() {
                    return this.blurTier === 'free_own_photo' ? this.plansUrl : this.uploadUrl;
                },
                photoNudgeBody() {
                    return this.blurTier === 'free_own_photo' ? this.msgPremium : this.msgUpload;
                },
                photoNudgeHref() {
                    return this.blurTier === 'free_own_photo' ? this.plansUrl : this.uploadUrl;
                },
                photoNudgeCta() {
                    return this.blurTier === 'free_own_photo' ? this.labelUpgrade : this.labelUpload;
                },
            }"
        >
            @php
                $genderKey = $profile->gender?->key ?? $profile->gender;
                $placeholderSrc = $genderKey === 'male'
                    ? asset('images/placeholders/male-profile.svg')
                    : ($genderKey === 'female' ? asset('images/placeholders/female-profile.svg') : asset('images/placeholders/default-profile.svg'));
            @endphp
            @if ($albumTotal > 0)
                <img
                    :src="photos[currentPhoto].url"
                    alt=""
                    :class="photos[currentPhoto].blur ? 'h-full w-full object-cover blur-2xl scale-105 brightness-90 transition duration-500 ease-out' : 'h-full w-full object-cover transition duration-500 ease-out group-hover/hero:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover/hero:scale-100'"
                />
            @else
                <img src="{{ $placeholderSrc }}" alt="" class="h-full w-full object-cover transition duration-500 ease-out group-hover/hero:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover/hero:scale-100" />
            @endif

            {{-- Tier blur: lock + CTA (conversion) --}}
            <div
                x-show="count > 0 && photos[currentPhoto].blur && (blurTier === 'free_own_photo' || blurTier === 'free_no_photo')"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 z-[11] flex items-center justify-center bg-gradient-to-b from-stone-950/55 via-stone-900/65 to-stone-950/80 p-4 backdrop-blur-[1px]"
                role="presentation"
            >
                <button
                    type="button"
                    @click.stop="photoInfoOpen = true"
                    class="absolute right-3 top-3 z-[13] flex h-9 w-9 items-center justify-center rounded-full border border-white/35 bg-black/40 text-sm font-bold text-white shadow-md backdrop-blur transition hover:bg-black/55"
                    aria-label="{{ __('upgrade_nudge.photo_info_aria') }}"
                >ⓘ</button>
                <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_50%_30%,rgba(251,191,36,0.12),transparent_55%)]" aria-hidden="true"></div>
                <div class="relative w-full max-w-[15.5rem] rounded-2xl border border-white/25 bg-gradient-to-b from-white/[0.18] to-white/[0.07] px-5 py-6 text-center shadow-[0_24px_64px_-12px_rgba(0,0,0,0.65)] ring-1 ring-amber-300/35 backdrop-blur-md dark:from-white/10 dark:to-white/[0.04] dark:ring-amber-400/25">
                    <div class="mx-auto mb-3.5 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-400 to-rose-500 text-white shadow-lg shadow-amber-900/40 ring-2 ring-white/40 motion-safe:animate-[pulse_2.5s_ease-in-out_infinite]">
                        <svg class="h-7 w-7 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12 1.5a5.25 5.25 0 0 0-5.25 5.25v3a3 3 0 0 0-3 3v6.75a3 3 0 0 0 3 3h10.5a3 3 0 0 0 3-3v-6.75a3 3 0 0 0-3-3v-3c0-2.9-2.35-5.25-5.25-5.25Zm3.75 8.25v-3a3.75 3.75 0 1 0-7.5 0v3h7.5Z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <p class="text-base font-bold leading-snug tracking-tight text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.45)]" x-text="blurHeadline()"></p>
                    <p class="mt-1.5 text-xs font-medium leading-relaxed text-white/80" x-text="blurSub()"></p>
                    <a
                        :href="blurCtaUrl()"
                        class="pointer-events-auto mt-4 inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-amber-500 to-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-rose-900/30 ring-1 ring-white/20 transition hover:from-amber-400 hover:to-rose-500 hover:shadow-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 focus-visible:ring-offset-2 focus-visible:ring-offset-stone-900"
                        x-text="blurCtaLabel()"
                    ></a>
                </div>
            </div>

            <div
                x-show="photoInfoOpen"
                x-cloak
                x-transition
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4"
                role="dialog"
                aria-modal="true"
                @click.self="photoInfoOpen = false"
            >
                <div class="relative w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-5 pt-6 shadow-2xl dark:border-gray-700 dark:bg-gray-900" @click.stop>
                    <button
                        type="button"
                        class="absolute right-2.5 top-2.5 rounded-lg p-1.5 text-stone-500 transition hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-gray-800"
                        @click="photoInfoOpen = false"
                        aria-label="{{ __('upgrade_nudge.close') }}"
                    >✕</button>
                    <p class="pr-8 text-sm leading-relaxed text-stone-700 dark:text-stone-200" x-text="photoNudgeBody()"></p>
                    <a
                        :href="photoNudgeHref()"
                        class="mt-4 flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-md transition hover:bg-indigo-700"
                        x-text="photoNudgeCta()"
                    ></a>
                    <a
                        x-show="blurTier === 'free_no_photo'"
                        x-cloak
                        :href="plansUrl"
                        class="mt-3 block text-center text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                    >{{ __('subscriptions.nav_plans') }}</a>
                    <button
                        type="button"
                        class="mt-4 w-full text-center text-sm font-semibold text-stone-500 hover:text-stone-800 dark:text-stone-400 dark:hover:text-stone-200"
                        @click="photoInfoOpen = false"
                    >{{ __('upgrade_nudge.close') }}</button>
                </div>
            </div>

            <div
                x-show="count > 0 && !photos[currentPhoto].blur"
                class="pointer-events-none absolute inset-x-0 bottom-0 h-[62%] bg-gradient-to-t {{ ($hideOverlayIdentity ?? false) ? 'from-stone-950/35 via-stone-900/12 to-transparent dark:from-black/40 dark:via-stone-950/10' : 'from-black/92 via-black/55 to-transparent dark:from-black/95 dark:via-black/60' }}"
                aria-hidden="true"
            ></div>
            @if (!($hideOverlayIdentity ?? false))
            {{-- Photo overlay: marital + age one line at bottom (no name / no city) --}}
            <div x-show="count > 0 && !photos[currentPhoto].blur" class="absolute inset-x-0 bottom-0 z-10 px-4 pb-4 pt-12 sm:px-5 sm:pb-5 sm:pt-14">
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
