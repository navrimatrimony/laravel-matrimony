@php
    $publicMatrimonyLayout = $publicMatrimonyLayout ?? false;
    $profile->loadMissing(['city', 'district', 'state', 'taluka', 'country', 'maritalStatus', 'religion', 'caste', 'subCaste', 'familyType', 'complexion', 'physicalBuild', 'bloodGroup', 'seriousIntent', 'diet', 'smokingStatus', 'drinkingStatus', 'birthCity', 'birthTaluka', 'birthDistrict', 'birthState', 'nativeCity', 'nativeTaluka', 'nativeDistrict', 'nativeState', 'profession']);
    $locationLine = \App\Support\ProfileDisplayCopy::formatResidenceDisplay(
        $profile->city?->name,
        $profile->taluka?->name,
        $profile->district?->name,
        $profile->state?->name,
        $profile->country?->name,
    );
    $age = null;
    if ($dateOfBirthVisible && ($profile->date_of_birth ?? '') !== '') {
        try {
            $age = \Carbon\Carbon::parse($profile->date_of_birth)->age;
        } catch (\Throwable) {
            $age = null;
        }
    }
    $overviewHighlights = [];
    if ($age !== null) {
        $overviewHighlights[] = __('profile.show_age_years', ['age' => $age]);
    }
    if ($heightVisible && ($profile->height_cm ?? '') !== '') {
        $overviewHighlights[] = $profile->height_cm.' cm';
    }
    if ($profile->religion) {
        $overviewHighlights[] = $profile->religion->label ?? '';
    }
    if ($profile->caste) {
        $overviewHighlights[] = $profile->caste->label ?? '';
    }
    if ($locationLine !== '' && ($locationVisible ?? true)) {
        $overviewHighlights[] = \Illuminate\Support\Str::limit($locationLine, 56);
    }
    if (($educationVisible ?? true) && ($profile->highest_education ?? '') !== '') {
        $eduH = \App\Support\ProfileDisplayCopy::formatEducationPhrase($profile->highest_education);
        if ($eduH !== null) {
            $overviewHighlights[] = \Illuminate\Support\Str::limit($eduH, 42);
        }
    }
    $overviewHighlights = array_slice(array_values(array_filter($overviewHighlights)), 0, 5);
    $workCityName = $profile->work_city_id ? \App\Models\City::where('id', $profile->work_city_id)->value('name') : null;
    $workStateName = $profile->work_state_id ? \App\Models\State::where('id', $profile->work_state_id)->value('name') : null;
    $workLocationLine = trim(implode(', ', array_filter([$workCityName, $workStateName])));
    $hasWorkLocation = $workLocationLine !== '';
    $siblings = $profile->siblings ?? collect();
    $brothersFromEngine = $siblings->where('relation_type', 'brother')->count();
    $sistersFromEngine = $siblings->where('relation_type', 'sister')->count();
    $hasFamily = ($profile->father_name ?? '') !== '' || ($profile->father_occupation ?? '') !== '' || ($profile->mother_name ?? '') !== '' || ($profile->mother_occupation ?? '') !== '' || $brothersFromEngine > 0 || $sistersFromEngine > 0 || $profile->familyType;
    $incomeService = app(\App\Services\IncomeEngineService::class);
    $profileArr = $profile->toArray();
    $personalIncomeDisplay = $incomeService->formatForDisplay($profileArr, 'income', $profile->incomeCurrency);
    $familyIncomeDisplay = $incomeService->formatForDisplay($profileArr, 'family_income', $profile->familyIncomeCurrency ?? $profile->incomeCurrency);
    $hasPersonalIncome = ($profile->income_value_type ?? null) !== null || ($profile->income_amount ?? null) !== null || ($profile->income_min_amount ?? null) !== null || ($profile->annual_income ?? null) !== null;
    $hasFamilyIncome = ($profile->family_income_value_type ?? null) !== null || ($profile->family_income_amount ?? null) !== null || ($profile->family_income_min_amount ?? null) !== null || ($profile->family_income ?? null) !== null;
    $hasEduCareer = ($educationVisible && ($profile->highest_education ?? '') !== '') || ($profile->specialization ?? '') !== '' || ($profile->occupation_title ?? '') !== '' || ($profile->company_name ?? '') !== '' || $hasPersonalIncome || ($profile->annual_income ?? null) !== null || $profile->incomeCurrency;
    $eduCareerSubtitleParts = [];
    if ($educationVisible && ($profile->highest_education ?? '') !== '') {
        $eduFrag = \App\Support\ProfileDisplayCopy::formatEducationPhrase($profile->highest_education);
        if (($profile->specialization ?? '') !== '') {
            $eduCareerSubtitleParts[] = ($eduFrag ? $eduFrag.' in ' : '').\Illuminate\Support\Str::title(mb_strtolower($profile->specialization));
        } elseif ($eduFrag !== null) {
            $eduCareerSubtitleParts[] = $eduFrag;
        }
    }
    if (($profile->occupation_title ?? '') !== '') {
        $eduCareerSubtitleParts[] = \App\Support\ProfileDisplayCopy::formatOccupationPhrase($profile->occupation_title);
    }
    if (($profile->company_name ?? '') !== '') {
        $eduCareerSubtitleParts[] = \App\Support\ProfileDisplayCopy::formatCompanyName($profile->company_name);
    }
    $eduCareerSubtitle = implode(' • ', array_filter($eduCareerSubtitleParts));
    $familySubtitle = null;
    if ($profile->familyType && ($profile->familyType->label ?? '') !== '') {
        $ft = \Illuminate\Support\Str::lower(trim((string) $profile->familyType->label));
        $article = preg_match('/^[aeiou]/i', $ft) ? 'an' : 'a';
        $familySubtitle = __('profile.show_family_summary_type', ['article' => $article, 'type' => $ft]);
    }
    $birthPlaceLine = implode(', ', array_filter([
        $profile->birthCity?->name,
        $profile->birthTaluka?->name,
        $profile->birthDistrict?->name,
        $profile->birthState?->name,
    ]));
    $nativePlaceLine = implode(', ', array_filter([
        $profile->nativeCity?->name,
        $profile->nativeTaluka?->name,
        $profile->nativeDistrict?->name,
        $profile->nativeState?->name,
    ]));
    $hasBirthPlace = $birthPlaceLine !== '' || $profile->birth_city_id || $profile->birth_taluka_id || $profile->birth_district_id || $profile->birth_state_id;
    $hasNativePlace = $nativePlaceLine !== '' || $profile->native_city_id || $profile->native_taluka_id || $profile->native_district_id || $profile->native_state_id;
    $hasPhysical = ($heightVisible && ($profile->height_cm ?? '') !== '') || ($profile->weight_kg ?? null) !== null || $profile->complexion || $profile->physicalBuild || $profile->bloodGroup;
    $hasBasicSection = ($dateOfBirthVisible && ($profile->date_of_birth ?? '') !== '') || (($profile->birth_time ?? '') !== '') || ($maritalStatusVisible && $profile->maritalStatus) || $profile->religion || $profile->caste || $profile->subCaste || $profile->seriousIntent;
    $basicSubtitle = null;
    if ($maritalStatusVisible && $profile->maritalStatus) {
        $place = \App\Support\ProfileDisplayCopy::compactLocationLine(
            $profile->city?->name,
            $profile->district?->name,
            $profile->state?->name
        );
        if ($place !== '') {
            $basicSubtitle = __('profile.show_basic_summary', ['marital' => $profile->maritalStatus->label ?? '', 'place' => $place]);
        }
    }
    $hasResidence = ($locationVisible ?? true) && (($locationLine !== '' || ($profile->address_line ?? '') !== ''));
    $hasAddressesSection = $profile->addresses?->isNotEmpty() ?? false;
    $nativeSameAsBirth = $birthPlaceLine !== '' && $nativePlaceLine !== '' && strcasecmp(trim($birthPlaceLine), trim($nativePlaceLine)) === 0;
    $hasLocationSection = $hasResidence || $hasWorkLocation || $hasBirthPlace || $hasNativePlace || $hasAddressesSection;
    $locationSubtitle = null;
    $residenceForCompare = trim($locationLine);
    $workForCompare = trim($workLocationLine);
    $workSameAsResidence = $hasResidence && $hasWorkLocation && $residenceForCompare !== '' && $workForCompare !== '' && strcasecmp($residenceForCompare, $workForCompare) === 0;
    if ($residenceForCompare !== '' && $workForCompare !== '' && strcasecmp($residenceForCompare, $workForCompare) === 0) {
        $locationSubtitle = __('profile.show_location_same_residence_work', ['place' => $residenceForCompare]);
    } elseif ($residenceForCompare !== '' && $workForCompare !== '') {
        $locationSubtitle = __('profile.show_location_connected_work', ['residence' => $residenceForCompare, 'work' => $workForCompare]);
    } elseif ($residenceForCompare !== '' && ($locationVisible ?? true)) {
        $locationSubtitle = __('profile.show_location_connected_only', ['residence' => $residenceForCompare]);
    } elseif ($workForCompare !== '') {
        $locationSubtitle = __('profile.show_location_work_only', ['work' => $workForCompare]);
    } elseif ($hasBirthPlace || $hasNativePlace) {
        $locationSubtitle = __('profile.show_location_summary');
    }
    $regFor = $profile->user?->registering_for ?? null;
    $regLabel = match ($regFor) {
        'self' => __('onboarding.registering_for_self'),
        'parent_guardian' => __('onboarding.registering_for_parent_guardian'),
        'sibling' => __('onboarding.registering_for_sibling'),
        'relative' => __('onboarding.registering_for_relative'),
        'friend' => __('onboarding.registering_for_friend'),
        'other' => __('onboarding.registering_for_other'),
        default => null,
    };
    $hasLifestyle = $profile->diet || $profile->smokingStatus || $profile->drinkingStatus;
    $showFamilySection = $hasFamily || $hasFamilyIncome || ($profile->siblings?->isNotEmpty()) || ($profile->children?->isNotEmpty());
    $showEducationSection = $hasEduCareer || ($profile->educationHistory && $profile->educationHistory->isNotEmpty()) || ($profile->career?->isNotEmpty());
    $basicScanRow1 = implode(' · ', array_values(array_filter([
        $age !== null ? __('profile.show_age_years', ['age' => $age]) : null,
        ($maritalStatusVisible && $profile->maritalStatus) ? ($profile->maritalStatus->label ?? '') : null,
        ($profile->seriousIntent && ($profile->seriousIntent->name ?? '') !== '') ? $profile->seriousIntent->name : null,
    ])));
    $basicScanRow2 = implode(', ', array_filter([
        $profile->religion?->label,
        $profile->caste?->label,
        $profile->subCaste?->label,
    ]));
    $basicScanRow3 = '';
    if (($locationVisible ?? true) && $locationLine !== '') {
        $basicScanRow3 = __('profile.scan_lives_in').' '.$locationLine;
    }
    if (($profile->address_line ?? '') !== '') {
        $basicScanRow3 = $basicScanRow3 !== '' ? ($profile->address_line.' · '.$basicScanRow3) : $profile->address_line;
    }
    $basicScanRow4 = $eduCareerSubtitle;
    $basicScanRow5 = implode(' · ', array_values(array_filter([
        $hasPersonalIncome ? $personalIncomeDisplay : null,
        $hasFamilyIncome ? $familyIncomeDisplay : null,
        ($heightVisible && ($profile->height_cm ?? '') !== '') ? $profile->height_cm.' cm' : null,
        ($profile->birth_time ?? '') !== '' ? $profile->birth_time : null,
    ])));
@endphp

@php
    $viewerBrowsingOther = $publicMatrimonyLayout && !($isOwnProfile ?? false);
    $sectionMb = $viewerBrowsingOther ? 'mb-6' : 'mb-8';
    $sectionCardHeading = '[&>h2]:border-b [&>h2]:border-stone-200/70 [&>h2]:pb-2.5 [&>h2]:text-base [&>h2]:font-semibold [&>h2]:tracking-tight [&>h2]:text-stone-900 [&>h2]:dark:border-gray-700/75 [&>h2]:dark:text-stone-100 [&>p]:!mt-1 [&>p]:text-sm [&>p]:leading-relaxed [&>p]:text-stone-500 [&>p]:dark:text-stone-400';
@endphp

@if ($viewerBrowsingOther)
<div id="profile-detailed" class="scroll-mt-28 space-y-7">
@endif

{{-- When viewing someone else: key highlights first (summary-first), then completeness --}}
@if ($viewerBrowsingOther && count($overviewHighlights) > 0)
    <x-profile.show.profile-section-card
        class="{{ $sectionMb }} {{ $sectionCardHeading }}"
        :title="__('profile.show_at_a_glance')"
    >
        <x-profile.show.profile-highlight-list :items="$overviewHighlights" />
    </x-profile.show.profile-section-card>
@endif

@if ($isOwnProfile ?? false)
{{-- Profile Completeness: own profile only --}}
<x-profile.show.profile-section-card class="{{ $sectionMb }} {{ $sectionCardHeading }}" :title="__('profile.profile_completeness')">
    <div class="flex items-center justify-end gap-3">
        <span class="text-lg font-bold tabular-nums text-rose-700 dark:text-rose-400">{{ $completenessPct }}%</span>
    </div>
    <div class="mt-3 w-full rounded-full bg-stone-200 dark:bg-gray-600">
        <div class="h-2 rounded-full bg-rose-700 transition-all duration-300 dark:bg-rose-500" style="width: {{ $completenessPct }}%;"></div>
    </div>
</x-profile.show.profile-section-card>
@endif

@if ((count($overviewHighlights) > 0 || (!($showProfileSidebarLayout ?? false) && !empty($profileHeadline))) && !$viewerBrowsingOther)
    <x-profile.show.profile-section-card
        class="{{ $sectionMb }} {{ $sectionCardHeading }}"
        :title="($showProfileSidebarLayout ?? false) ? __('profile.show_at_a_glance') : __('profile.show_section_overview')"
        :subtitle="(!($showProfileSidebarLayout ?? false) && !empty($profileHeadline)) ? $profileHeadline : null"
    >
        @if (count($overviewHighlights) > 0)
            <x-profile.show.profile-highlight-list :items="$overviewHighlights" />
        @endif
    </x-profile.show.profile-section-card>
@endif

@if (isset($extendedAttributes) && trim($extendedAttributes->narrative_about_me ?? '') !== '')
    <x-profile.show.profile-section-card
        class="{{ $sectionMb }} {{ $sectionCardHeading }} {{ $viewerBrowsingOther ? 'border-rose-100/70 bg-gradient-to-br from-rose-50/40 via-white to-white shadow-[0_4px_24px_-8px_rgba(225,29,72,0.08)] dark:border-rose-900/30 dark:from-rose-950/15 dark:via-gray-900 dark:to-gray-900' : '' }}"
        :title="__('profile.show_about_title')"
        :subtitle="__('profile.narrative_intro_label')"
    >
        @if ($viewerBrowsingOther && $regLabel)
            <div class="mb-4 flex flex-wrap gap-2">
                <span class="inline-flex rounded-full bg-stone-100 px-2.5 py-1 text-[11px] font-medium text-stone-700 ring-1 ring-stone-200/80 dark:bg-gray-800 dark:text-stone-300 dark:ring-gray-600">{{ __('profile.show_managed_by') }}: {{ $regLabel }}</span>
            </div>
        @endif
        <p class="max-w-prose text-[15px] leading-[1.65] text-stone-800 dark:text-stone-100 sm:text-base whitespace-pre-wrap">{{ $extendedAttributes->narrative_about_me }}</p>
    </x-profile.show.profile-section-card>
@endif

@if (!empty($profileIntroGenerated) && trim((string) ($extendedAttributes->narrative_about_me ?? '')) === '')
    <x-profile.show.profile-section-card
        class="{{ $sectionMb }} {{ $sectionCardHeading }} border-dashed border-stone-300/80 dark:border-gray-600 {{ $viewerBrowsingOther ? 'border-rose-200/80 bg-gradient-to-br from-rose-50/35 via-white to-stone-50/30 dark:border-rose-900/40 dark:from-rose-950/20 dark:via-gray-900 dark:to-gray-950' : '' }}"
        :title="__('profile.show_about_title')"
        :subtitle="__('profile.show_section_intro')"
    >
        @if ($viewerBrowsingOther && $regLabel)
            <div class="mb-4 flex flex-wrap gap-2">
                <span class="inline-flex rounded-full bg-stone-100 px-2.5 py-1 text-[11px] font-medium text-stone-700 ring-1 ring-stone-200/80 dark:bg-gray-800 dark:text-stone-300 dark:ring-gray-600">{{ __('profile.show_managed_by') }}: {{ $regLabel }}</span>
            </div>
        @endif
        <p class="max-w-prose text-[15px] leading-[1.65] text-stone-800 dark:text-stone-100 sm:text-base">{{ $profileIntroGenerated }}</p>
    </x-profile.show.profile-section-card>
@endif

@if ($profilePhotoVisible)
    @php
        $galleryPhotosQuery = \App\Models\ProfilePhoto::query()
            ->where('profile_id', $profile->id)
            ->where('is_primary', false);

        if (! $isOwnProfile) {
            $galleryPhotosQuery->where('approved_status', 'approved');
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('profile_photos', 'sort_order')) {
            $galleryPhotosQuery->orderBy('sort_order')->orderBy('id');
        } else {
            $galleryPhotosQuery->orderByDesc('created_at')->orderBy('id');
        }

        $galleryPhotos = $galleryPhotosQuery->take(12)->get();
    @endphp

    @if ($galleryPhotos->isNotEmpty())
        <div class="mb-6">
            <div class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 text-center">Photo gallery</div>
            @if ($photoLocked)
                <div class="mb-3 text-center">
                    <p class="text-xs text-gray-600 dark:text-gray-300 mb-2">Photo gallery is private</p>
                    @if (($photoLockMode ?? 'all') === 'premium')
                        @if (! $contactRequestDisabled && $contactRequestState !== null)
                            @if (auth()->check())
                                <button type="button"
                                        @click="$root.openRequestModal = true"
                                        style="background-color: #10b981; color: white; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; border: none; cursor: pointer;">
                                    {{ __('Request Contact') }}
                                </button>
                            @else
                                <button type="button" disabled
                                        style="background-color: #9ca3af; color: white; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; border: none; cursor: not-allowed;">
                                    {{ __('Login to Request Contact') }}
                                </button>
                            @endif
                        @else
                            <button type="button" disabled
                                    style="background-color: #9ca3af; color: white; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; border: none; cursor: not-allowed;">
                                {{ __('Request Contact') }}
                            </button>
                        @endif
                    @else
                        @if ($interestAlreadySent)
                            <button type="button" disabled
                                    style="background-color: #9ca3af; color: white; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; border: none; cursor: not-allowed;">
                                {{ __('Interest Sent') }}
                            </button>
                        @else
                            @if (auth()->check())
                                <form method="POST" action="{{ route('interests.send', $profile) }}" style="display: inline;">
                                    @csrf
                                    <button type="submit"
                                            style="background-color: #ec4899; color: white; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; border: none; cursor: pointer;">
                                        {{ __('Send Interest') }}
                                    </button>
                                </form>
                            @else
                                <button type="button" disabled
                                        style="background-color: #9ca3af; color: white; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; border: none; cursor: not-allowed;">
                                    {{ __('Login to Send Interest') }}
                                </button>
                            @endif
                        @endif
                    @endif
                </div>
            @endif
            <div
                class="flex gap-3 overflow-x-auto px-2 py-2 snap-x snap-mandatory"
                style="scrollbar-width: none; -ms-overflow-style: none;"
            >
                @foreach ($galleryPhotos as $photo)
                    @php
                        $status = (string) ($photo->approved_status ?? '');
                        $statusLabel = $status === 'approved' ? 'approved' : ($status === 'pending' ? 'pending' : 'rejected');
                    @endphp

                    <div class="snap-start flex-none w-24 sm:w-28">
                        <div class="relative overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white/70 dark:bg-gray-800/40 shadow-sm">
                            <img
                                src="{{ asset('uploads/matrimony_photos/'.$photo->file_path) }}"
                                alt="Profile photo"
                                class="w-full h-24 sm:h-28 object-cover"
                                style="{{ $photoLocked ? 'filter: blur(8px);' : '' }}"
                            />

                            @if ($isOwnProfile)
                                @php
                                    $badgeBg = $status === 'approved' ? '#dcfce7' : ($status === 'pending' ? '#fef3c7' : '#fee2e2');
                                    $badgeBorder = $status === 'approved' ? '#86efac' : ($status === 'pending' ? '#fbbf24' : '#fca5a5');
                                @endphp
                                <div
                                    class="absolute bottom-2 left-2 px-2 py-1 rounded-full"
                                    style="background: {{ $badgeBg }}; border: 1px solid {{ $badgeBorder }};"
                                >
                                    <span class="text-[11px] font-extrabold text-gray-900 dark:text-gray-900">
                                        {{ $statusLabel }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    @if ($isOwnProfile && $profile->profile_photo && $profile->photo_approved === false && empty($profile->photo_rejected_at))
        <p class="mt-4 text-sm text-amber-800 dark:text-amber-200 bg-amber-50 dark:bg-amber-900/30 px-3 py-2 rounded-lg border border-amber-200/80 dark:border-amber-800">{{ __('dashboard.photo_under_review') }}</p>
    @endif
@endif

{{-- Desktop: name line when hero is not in sidebar (browse chrome uses hero rail) --}}
@if (!($showProfileSidebarLayout ?? false))
<div class="hidden lg:block mb-8">
    <h2 class="text-2xl font-semibold text-stone-900 dark:text-stone-100 tracking-tight">
        {{ \App\Support\ProfileDisplayCopy::formatPersonName($profile->full_name) }}
        @if ($isOwnProfile && $profile->admin_edited_fields && in_array('full_name', $profile->admin_edited_fields ?? []))
            <span class="ml-2 text-xs text-amber-600 dark:text-amber-400" title="This field was corrected by admin">(Admin corrected)</span>
        @endif
    </h2>
    @if (!empty($profileHeadline))<p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ $profileHeadline }}</p>@endif
    <p class="text-sm text-stone-500 dark:text-stone-400 mt-1">{{ $profile->gender?->label ?? $profile->user?->gender ?? '—' }}</p>
</div>
@endif

@if ($isOwnProfile && $profile->photo_rejection_reason)
    <div style="margin-bottom:1.5rem; padding:1rem; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; color:#991b1b;">
        <p style="font-weight:600; margin-bottom:0.5rem;">Your profile photo was removed by admin.</p>
        <p style="margin:0;"><strong>Reason:</strong> {{ $profile->photo_rejection_reason }}</p>
    </div>
@endif

{{-- Main profile details: basic → education → family → location → lifestyle → property --}}
@if ($hasBasicSection)
    <x-profile.show.profile-section-card class="{{ $sectionMb }} {{ $sectionCardHeading }}" :title="__('profile.show_section_basic')" :subtitle="$basicSubtitle">
        <div class="space-y-3.5">
            @if ($basicScanRow1 !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-1 inline-flex h-4 w-4 shrink-0 text-rose-500/90 dark:text-rose-400" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
                    </span>
                    <p class="text-[15px] font-semibold leading-snug tracking-tight text-stone-900 dark:text-stone-50">{{ $basicScanRow1 }}</p>
                </div>
            @endif
            @if ($basicScanRow2 !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-rose-500/90 dark:text-rose-400" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/></svg>
                    </span>
                    <p class="text-sm leading-snug text-stone-800 dark:text-stone-100">{{ $basicScanRow2 }}</p>
                </div>
            @endif
            @if ($basicScanRow3 !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-rose-500/90 dark:text-rose-400" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                    </span>
                    <p class="text-sm leading-relaxed text-stone-700 dark:text-stone-200">{{ $basicScanRow3 }}</p>
                </div>
            @endif
            @if ($basicScanRow4 !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-rose-500/90 dark:text-rose-400" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5"/></svg>
                    </span>
                    <p class="text-sm leading-snug text-stone-800 dark:text-stone-100">{{ $basicScanRow4 }}</p>
                </div>
            @endif
            @if ($basicScanRow5 !== '')
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125H18.75v-7.5h-3.75v7.5h-3.75v-7.5H9v7.5H5.25v-7.5H2.25"/></svg>
                    </span>
                    <p class="text-sm leading-snug text-stone-800 dark:text-stone-100">{{ $basicScanRow5 }}</p>
                </div>
            @endif
            @if ($dateOfBirthVisible && ($profile->date_of_birth ?? '') !== '')
                <p class="text-xs text-stone-500 dark:text-stone-400">{{ __('Date of Birth') }}: <span class="font-medium text-stone-700 dark:text-stone-300">{{ $profile->date_of_birth }}</span></p>
            @endif
        </div>
    </x-profile.show.profile-section-card>
@endif

{{-- Physical: details below basic identity (summary-first hierarchy) --}}
@if ($hasPhysical)
    <x-profile.show.profile-section-card class="{{ $sectionMb }} {{ $sectionCardHeading }}" :title="__('Physical')">
        <div class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
            @if ($heightVisible && ($profile->height_cm ?? '') !== '')
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Height') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profile->height_cm }} cm</p>
                </div>
            @endif
            @if (($profile->weight_kg ?? null) !== null && $profile->weight_kg !== '')
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Weight') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profile->weight_kg }} kg</p>
                </div>
            @endif
            @if ($profile->complexion)
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Complexion') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profile->complexion->label ?? '—' }}</p>
                </div>
            @endif
            @if ($profile->physicalBuild)
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Physical Build') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profile->physicalBuild->label ?? '—' }}</p>
                </div>
            @endif
            @if ($profile->bloodGroup)
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Blood Group') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profile->bloodGroup->label ?? '—' }}</p>
                </div>
            @endif
        </div>
    </x-profile.show.profile-section-card>
@endif

@if ($showEducationSection)
    <x-profile.show.profile-section-card class="{{ $sectionMb }} {{ $sectionCardHeading }}" :title="__('profile.show_section_education_career')" :subtitle="$eduCareerSubtitle ?: null">
        @if ($hasEduCareer)
            <div class="space-y-3">
                @if ($eduCareerSubtitle !== '')
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-indigo-500 dark:text-indigo-400" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5"/></svg>
                        </span>
                        <p class="text-sm font-semibold leading-snug text-stone-900 dark:text-stone-100">{{ $eduCareerSubtitle }}</p>
                    </div>
                @endif
                @if ($hasPersonalIncome)
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125H18.75v-7.5h-3.75v7.5h-3.75v-7.5H9v7.5H5.25v-7.5H2.25"/></svg>
                        </span>
                        <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $personalIncomeDisplay }}</p>
                    </div>
                @endif
                @if ($hasFamilyIncome)
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125H18.75v-7.5h-3.75v7.5h-3.75v-7.5H9v7.5H5.25v-7.5H2.25"/></svg>
                        </span>
                        <p class="text-sm font-semibold text-stone-900 dark:text-stone-100"><span class="text-stone-500 dark:text-stone-400">{{ __('Family Income') }}:</span> {{ $familyIncomeDisplay }}</p>
                    </div>
                @endif
                @if ($profile->incomeCurrency && ! $hasPersonalIncome && ! $hasFamilyIncome)
                    <p class="text-xs text-stone-500 dark:text-stone-400">{{ __('Income Currency') }}: <span class="font-medium text-stone-800 dark:text-stone-200">{{ $profile->incomeCurrency->displaySymbol() }} {{ $profile->incomeCurrency->code ?? '—' }}</span></p>
                @endif
            </div>
        @endif
        @if ($profile->educationHistory && $profile->educationHistory->isNotEmpty())
            <div class="{{ $hasEduCareer ? 'mt-6 border-t border-stone-200/80 pt-5 dark:border-gray-700' : '' }}">
                <p class="mb-3 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('Education History') }}</p>
                <ul class="space-y-2 text-sm leading-relaxed text-stone-800 dark:text-stone-100">
                    @foreach ($profile->educationHistory as $edu)
                        <li>
                            {{ $edu->degree ?: '—' }}{{ $edu->specialization ? ' – ' . $edu->specialization : '' }}{{ $edu->university ? ' (' . $edu->university . ')' : '' }}{{ $edu->year_completed ? ', ' . $edu->year_completed : '' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($profile->career?->isNotEmpty())
            <div class="{{ $hasEduCareer || ($profile->educationHistory && $profile->educationHistory->isNotEmpty()) ? 'mt-6 border-t border-stone-200/80 pt-5 dark:border-gray-700' : '' }}">
                <p class="mb-3 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('Career History') }}</p>
                <ul class="space-y-2 text-sm leading-relaxed text-stone-800 dark:text-stone-100">
                    @foreach ($profile->career as $job)
                        <li>
                            {{ $job->designation ?: '—' }}{{ $job->company ? ' at ' . $job->company : '' }}{{ $job->start_year || $job->end_year ? ' (' . ($job->start_year ?? '') . '–' . ($job->end_year ?? '') . ')' : '' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-profile.show.profile-section-card>
@endif

@if ($showFamilySection)
    <x-profile.show.profile-section-card class="{{ $sectionMb }} {{ $sectionCardHeading }}" :title="__('profile.show_section_family')" :subtitle="$familySubtitle">
        <div class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
            @if (($profile->father_name ?? '') !== '')
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Father') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profile->father_name }}{{ ($profile->father_occupation ?? '') !== '' ? ' · ' . $profile->father_occupation : '' }}</p>
                </div>
            @endif
            @if (($profile->mother_name ?? '') !== '')
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Mother') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profile->mother_name }}{{ ($profile->mother_occupation ?? '') !== '' ? ' · ' . $profile->mother_occupation : '' }}</p>
                </div>
            @endif
            @if ($brothersFromEngine > 0 || $sistersFromEngine > 0)
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Siblings') }}</p>
                    @php
                        $b = $brothersFromEngine > 0 ? $brothersFromEngine . ' brother' . ($brothersFromEngine !== 1 ? 's' : '') : '';
                        $s = $sistersFromEngine > 0 ? $sistersFromEngine . ' sister' . ($sistersFromEngine !== 1 ? 's' : '') : '';
                        $siblingsText = trim($b . ($b && $s ? ', ' : '') . $s);
                    @endphp
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $siblingsText }}</p>
                </div>
            @endif
            @if ($profile->familyType)
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Family Type') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profile->familyType->label ?? '—' }}</p>
                </div>
            @endif
            @if ($hasFamilyIncome)
                <div class="space-y-1">
                    <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Family Income') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $familyIncomeDisplay }}</p>
                </div>
            @endif
        </div>
        @if ($profile->children?->isNotEmpty())
            <div class="mt-6 border-t border-stone-200/80 pt-5 dark:border-gray-700">
                <p class="mb-3 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('Children') }}</p>
                <ul class="space-y-2 text-sm leading-relaxed text-stone-800 dark:text-stone-100">
                    @foreach ($profile->children as $child)
                        <li>
                            {{ $child->child_name ?: '—' }}{{ $child->age ? ', ' . $child->age . ' yrs' : '' }}{{ $child->gender ? ' (' . $child->gender . ')' : '' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($profile->siblings?->isNotEmpty())
            @php
                $siblingsByGender = $profile->siblings->groupBy(function ($s) { return ($s->gender ?? 'other') ?: 'other'; });
            @endphp
            <div class="mt-6 border-t border-stone-200/80 pt-5 dark:border-gray-700">
                <p class="mb-3 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('profile.show_sibling_details') }}</p>
                @foreach ($siblingsByGender as $gender => $items)
                    <div class="mb-3 last:mb-0">
                        <p class="mb-1 text-xs font-medium text-stone-500 dark:text-stone-400">{{ ucfirst($gender) }}</p>
                        @foreach ($items as $sib)
                            <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">
                                {{ $sib->occupation ?: '—' }}{{ $sib->marital_status ? ' · ' . ucfirst($sib->marital_status) : '' }}{{ $sib->city?->name ? ' · ' . $sib->city->name : '' }}{{ $sib->notes ? ' · ' . \Illuminate\Support\Str::limit($sib->notes, 50) : '' }}
                            </p>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </x-profile.show.profile-section-card>
@endif

@if ($hasLocationSection)
    <x-profile.show.profile-section-card class="{{ $sectionMb }} {{ $sectionCardHeading }}" :title="__('profile.show_section_location')" :subtitle="$locationSubtitle">
        @if ($hasResidence)
            <div class="mb-5 flex items-start gap-2.5">
                <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 text-sky-500 dark:text-sky-400" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                </span>
                <div class="min-w-0 space-y-1">
                    @if (($profile->address_line ?? '') !== '')
                        <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profile->address_line }}</p>
                    @endif
                    @if ($locationLine !== '')
                        <p class="text-sm font-semibold leading-snug text-stone-800 dark:text-stone-100">{{ __('profile.scan_lives_in') }} {{ $locationLine }}</p>
                    @endif
                </div>
            </div>
        @endif
        @if ($hasWorkLocation)
            <div class="mb-5">
                <p class="mb-1 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('profile.show_works_in') }}</p>
                @if ($workSameAsResidence)
                    <p class="text-sm text-stone-600 dark:text-stone-400">{{ __('profile.show_same_as_residence') }}</p>
                @else
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $workLocationLine }}</p>
                @endif
            </div>
        @endif
        @if ($nativeSameAsBirth && $birthPlaceLine !== '')
            <div class="mb-5">
                <p class="mb-1 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('profile.show_birth_native_same') }}</p>
                <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $birthPlaceLine }}</p>
            </div>
        @else
            @if ($hasBirthPlace && $birthPlaceLine !== '')
                <div class="mb-5">
                    <p class="mb-1 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('profile.show_born_in') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $birthPlaceLine }}</p>
                </div>
            @endif
            @if ($hasNativePlace && $nativePlaceLine !== '' && ! $nativeSameAsBirth)
                <div class="mb-5">
                    <p class="mb-1 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('profile.show_native_to') }}</p>
                    <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $nativePlaceLine }}</p>
                </div>
            @endif
        @endif
        @if ($hasAddressesSection)
            <div class="mb-5">
                <p class="mb-2 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('Address') }}</p>
                <ul class="space-y-2 text-sm leading-relaxed text-stone-800 dark:text-stone-100">
                    @foreach ($profile->addresses as $addr)
                        <li>
                            {{ implode(', ', array_filter([
                                trim($addr->village?->name ?? ''),
                                $addr->city?->name,
                                $addr->taluka?->name,
                                $addr->district?->name,
                                $addr->state?->name,
                                $addr->country?->name,
                            ])) ?: '—' }}{{ trim($addr->postal_code ?? '') ? ' – ' . $addr->postal_code : '' }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-profile.show.profile-section-card>
@endif

@if ($hasLifestyle || $regLabel)
    <x-profile.show.profile-section-card class="{{ $sectionMb }} {{ $sectionCardHeading }}" :title="__('profile.show_section_lifestyle')">
        @if ($hasLifestyle)
            <div class="flex flex-wrap gap-2">
                @if ($profile->diet)
                    <span class="inline-flex rounded-full border border-stone-200 dark:border-gray-600 bg-stone-50/90 px-3 py-1.5 text-xs font-medium text-stone-800 dark:bg-gray-800/80 dark:text-stone-200">{{ $profile->diet->label }}</span>
                @endif
                @if ($profile->smokingStatus)
                    <span class="inline-flex rounded-full border border-stone-200 dark:border-gray-600 bg-stone-50/90 px-3 py-1.5 text-xs font-medium text-stone-800 dark:bg-gray-800/80 dark:text-stone-200">{{ $profile->smokingStatus->label }}</span>
                @endif
                @if ($profile->drinkingStatus)
                    <span class="inline-flex rounded-full border border-stone-200 dark:border-gray-600 bg-stone-50/90 px-3 py-1.5 text-xs font-medium text-stone-800 dark:bg-gray-800/80 dark:text-stone-200">{{ $profile->drinkingStatus->label }}</span>
                @endif
            </div>
        @endif
        @if ($regLabel)
            <p class="{{ $hasLifestyle ? 'mt-4' : '' }} text-sm text-stone-600 dark:text-stone-400">
                <span class="text-stone-500 dark:text-stone-500">{{ __('profile.show_managed_by') }}</span> {{ $regLabel }}
            </p>
        @endif
    </x-profile.show.profile-section-card>
@endif

@if (($profilePropertySummary ?? null) && ($profilePropertySummary->owns_agriculture ?? false) && (($profilePropertySummary->agriculture_type ?? '') !== ''))
    <x-profile.show.profile-section-card class="{{ $sectionMb }} {{ $sectionCardHeading }}" :title="__('Property')">
        <div class="space-y-1">
            <p class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('Agriculture type') }}</p>
            <p class="text-sm font-semibold text-stone-900 dark:text-stone-100">{{ $profilePropertySummary->agriculture_type }}</p>
        </div>
    </x-profile.show.profile-section-card>
@endif

@if (!empty($enableRelativesSection) && $profile->relatives?->isNotEmpty())
@php
    $relativesByType = $profile->relatives->groupBy(function ($r) { return $r->relation_type ?: 'Other'; });
    $relativeRelationLabels = [
        'paternal_uncle' => 'Paternal Uncle', 'wife_paternal_uncle' => 'Wife of Paternal Uncle',
        'paternal_aunt' => 'Paternal Aunt', 'husband_paternal_aunt' => 'Husband of Paternal Aunt',
        'maternal_uncle' => 'Maternal Uncle', 'wife_maternal_uncle' => 'Wife of Maternal Uncle',
        'maternal_aunt' => 'Maternal Aunt', 'husband_maternal_aunt' => 'Husband of Maternal Aunt',
        'Cousin' => 'Cousin',
        'paternal_grandfather' => 'Paternal Grandfather', 'paternal_grandmother' => 'Paternal Grandmother',
        'maternal_grandfather' => 'Maternal Grandfather', 'maternal_grandmother' => 'Maternal Grandmother',
        'great_uncle' => 'Great Uncle', 'great_aunt' => 'Great Aunt', 'other_grandparents_family' => 'Other (Grandparents\' family)',
        'maternal_cousin' => 'Cousin (maternal)', 'other_maternal' => 'Other (maternal)',
        'Uncle' => 'Uncle', 'Aunt' => 'Aunt', 'Grandfather' => 'Grandfather', 'Grandmother' => 'Grandmother', 'Other' => 'Other',
    ];
@endphp
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Relatives & Family Network') }}</h3>
    @foreach($relativesByType as $relationType => $relatives)
        <div class="mb-3">
            <p class="text-gray-500 text-sm font-medium mb-1">{{ $relativeRelationLabels[$relationType] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', $relationType)) }}</p>
            @foreach($relatives as $rel)
                <p class="font-medium text-base ml-2">
                    {{ $rel->name ?: '—' }}{{ $rel->occupation ? ' · ' . $rel->occupation : '' }}{{ ($rel->city?->name || $rel->state?->name) ? ' (' . trim(implode(', ', array_filter([$rel->city?->name, $rel->state?->name]))) . ')' : '' }}{{ $rel->contact_number ? ' · ' . $rel->contact_number : '' }}{{ $rel->notes ? ' · ' . \Illuminate\Support\Str::limit($rel->notes, 60) : '' }}
                </p>
            @endforeach
        </div>
    @endforeach
</div>
@endif

@if ($profile->allianceNetworks?->isNotEmpty())
@php
    $allianceByLocation = $profile->allianceNetworks->groupBy(function ($a) {
        $parts = array_filter([$a->city?->name, $a->taluka?->name, $a->district?->name, $a->state?->name]);
        return implode(', ', $parts) ?: 'Other';
    });
@endphp
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Relatives & Native Network') }}</h3>
    @foreach($allianceByLocation as $locationLabel => $items)
        <div class="mb-3">
            <p class="text-gray-500 text-sm font-medium mb-1">{{ $locationLabel }}</p>
            @foreach($items as $a)
                <p class="font-medium text-base ml-2">
                    {{ $a->surname ?: '—' }}{{ $a->notes ? ' · ' . \Illuminate\Support\Str::limit($a->notes, 60) : '' }}
                </p>
            @endforeach
        </div>
    @endforeach
</div>
@endif

@php
    $hasPrefCriteria = isset($preferenceCriteria) && $preferenceCriteria;
    $hasAnyPrefs = false;
    if ($hasPrefCriteria) {
        $hasAnyPrefs = ($preferenceCriteria->preferred_city_id ?? null) !== null
            || ($preferenceCriteria->preferred_education ?? '') !== ''
            || ($preferenceCriteria->preferred_age_min ?? null) !== null
            || ($preferenceCriteria->preferred_age_max ?? null) !== null
            || ($preferenceCriteria->preferred_height_min_cm ?? null) !== null
            || ($preferenceCriteria->preferred_height_max_cm ?? null) !== null
            || ($preferenceCriteria->preferred_marital_status_id ?? null) !== null
            || ! empty($preferredMaritalStatusIds ?? [])
            || ($preferenceCriteria->partner_profile_with_children ?? null) !== null;
    }
    $hasAnyPrefs = $hasAnyPrefs
        || !empty($preferredReligionIds ?? [])
        || !empty($preferredCasteIds ?? [])
        || !empty($preferredDistrictIds ?? [])
        || !empty($preferredMaritalStatusIds ?? []);
@endphp
@if ($hasAnyPrefs)
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Partner preferences') }}</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
        @if($hasPrefCriteria && ($preferenceCriteria->preferred_age_min ?? null) !== null || ($preferenceCriteria->preferred_age_max ?? null) !== null)
            <p><span class="text-gray-500">{{ __('Age:') }}</span> {{ $preferenceCriteria->preferred_age_min ?? '—' }}–{{ $preferenceCriteria->preferred_age_max ?? '—' }}</p>
        @endif
        @if($hasPrefCriteria && (($preferenceCriteria->preferred_height_min_cm ?? null) !== null || ($preferenceCriteria->preferred_height_max_cm ?? null) !== null))
            @php
                $hMin = (int) ($preferenceCriteria->preferred_height_min_cm ?? 0);
                $hMax = (int) ($preferenceCriteria->preferred_height_max_cm ?? 0);
            @endphp
            <p><span class="text-gray-500">{{ __('wizard.preferred_height_range') }}:</span>
                @if($hMin > 0 && $hMax > 0)
                    {{ \App\Support\HeightDisplay::formatCmRange($hMin, $hMax) }}
                @else
                    {{ $preferenceCriteria->preferred_height_min_cm ?? '—' }}–{{ $preferenceCriteria->preferred_height_max_cm ?? '—' }} cm
                @endif
            </p>
        @endif
        @if($hasPrefCriteria && ($preferenceCriteria->preferred_education ?? '') !== '')
            <p><span class="text-gray-500">{{ __('Education:') }}</span> {{ $preferenceCriteria->preferred_education }}</p>
        @endif
        @if($hasPrefCriteria && ($preferenceCriteria->preferred_city_id ?? null))
            @php $prefCityName = \App\Models\City::where('id', $preferenceCriteria->preferred_city_id)->value('name'); @endphp
            @if($prefCityName)<p><span class="text-gray-500">{{ __('City:') }}</span> {{ $prefCityName }}</p>@endif
        @endif
        @if(!empty($preferredMaritalStatusIds ?? []))
            @php
                $prefMsLabels = \App\Models\MasterMaritalStatus::whereIn('id', $preferredMaritalStatusIds)->orderBy('label')->pluck('label')->all();
            @endphp
            @if($prefMsLabels)
                <p><span class="text-gray-500">{{ __('wizard.marital_status_preference') }}:</span> {{ implode(', ', $prefMsLabels) }}</p>
            @endif
        @elseif($hasPrefCriteria && ($preferenceCriteria->preferred_marital_status_id ?? null))
            @php
                $prefMs = \App\Models\MasterMaritalStatus::where('id', $preferenceCriteria->preferred_marital_status_id)->first();
            @endphp
            @if($prefMs)
                <p><span class="text-gray-500">{{ __('wizard.marital_status_preference') }}:</span> {{ $prefMs->label }}</p>
            @endif
        @endif
        @if($hasPrefCriteria && in_array($preferenceCriteria->partner_profile_with_children ?? null, ['no', 'yes_if_live_separate', 'yes'], true))
            @php
                $pwc = $preferenceCriteria->partner_profile_with_children;
                $pwcLabel = match ($pwc) {
                    'no' => __('wizard.partner_children_no'),
                    'yes_if_live_separate' => __('wizard.partner_children_yes_if_live_separate'),
                    'yes' => __('wizard.partner_children_yes'),
                    default => $pwc,
                };
            @endphp
            <p><span class="text-gray-500">{{ __('wizard.profile_with_children_partner') }}:</span> {{ $pwcLabel }}</p>
        @endif
        @if(!empty($preferredReligionIds ?? []))
            @php $prefReligions = \App\Models\Religion::whereIn('id', $preferredReligionIds)->pluck('label')->all(); @endphp
            @if($prefReligions)<p><span class="text-gray-500">{{ __('Religions:') }}</span> {{ implode(', ', $prefReligions) }}</p>@endif
        @endif
        @if(!empty($preferredCasteIds ?? []))
            @php $prefCastes = \App\Models\Caste::whereIn('id', $preferredCasteIds)->pluck('label')->all(); @endphp
            @if($prefCastes)<p><span class="text-gray-500">{{ __('Castes:') }}</span> {{ implode(', ', $prefCastes) }}</p>@endif
        @endif
        @if(!empty($preferredDistrictIds ?? []))
            @php $prefDistricts = \App\Models\District::whereIn('id', $preferredDistrictIds)->pluck('name')->all(); @endphp
            @if($prefDistricts)<p><span class="text-gray-500">{{ __('Districts:') }}</span> {{ implode(', ', $prefDistricts) }}</p>@endif
        @endif
    </div>
</div>
@endif

@if (isset($extendedAttributes) && trim($extendedAttributes->narrative_expectations ?? '') !== '')
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Expectations') }}</h3>
    <p class="font-medium text-base whitespace-pre-wrap">{{ $extendedAttributes->narrative_expectations }}</p>
</div>
@endif

@if ($profile->horoscope && ($profile->horoscope->rashi_id || $profile->horoscope->nakshatra_id || $profile->horoscope->gan_id || $profile->horoscope->nadi_id || $profile->horoscope->mangal_dosh_type_id || $profile->horoscope->yoni_id || $profile->horoscope->charan || $profile->horoscope->devak || $profile->horoscope->kul || $profile->horoscope->gotra || $profile->horoscope->navras_name || $profile->horoscope->birth_weekday))
<div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('Horoscope') }}</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
        @if ($profile->horoscope->rashi)<p><span class="text-gray-500">Rashi:</span> {{ $profile->horoscope->rashi->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->nakshatra)<p><span class="text-gray-500">Nakshatra:</span> {{ $profile->horoscope->nakshatra->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->gan)<p><span class="text-gray-500">Gan:</span> {{ $profile->horoscope->gan->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->nadi)<p><span class="text-gray-500">Nadi:</span> {{ $profile->horoscope->nadi->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->mangalDoshType)<p><span class="text-gray-500">Mangal Dosh:</span> {{ $profile->horoscope->mangalDoshType->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->yoni)<p><span class="text-gray-500">Yoni:</span> {{ $profile->horoscope->yoni->label ?? '—' }}</p>@endif
        @if ($profile->horoscope->charan)<p><span class="text-gray-500">Charan:</span> {{ $profile->horoscope->charan }}</p>@endif
        @if ($profile->horoscope->devak)<p><span class="text-gray-500">Devak:</span> {{ $profile->horoscope->devak }}</p>@endif
        @if ($profile->horoscope->kul)<p><span class="text-gray-500">Kul:</span> {{ $profile->horoscope->kul }}</p>@endif
        @if ($profile->horoscope->gotra)<p><span class="text-gray-500">Gotra:</span> {{ $profile->horoscope->gotra }}</p>@endif
        @if ($profile->horoscope->navras_name)<p><span class="text-gray-500">Navras à¤¨à¤¾à¤µ:</span> {{ $profile->horoscope->navras_name }}</p>@endif
        @if ($profile->horoscope->birth_weekday)<p><span class="text-gray-500">à¤œà¤¨à¥à¤®à¤µà¤¾à¤°:</span> {{ $profile->horoscope->birth_weekday }}</p>@endif
    </div>
</div>
@endif

<div class="mt-8 rounded-2xl border border-stone-200/80 dark:border-gray-700 bg-stone-50/60 dark:bg-gray-800/40 p-5 sm:p-6">
    <p class="mb-2 text-[11px] font-semibold text-stone-500 dark:text-stone-400">{{ __('Contact Information') }}</p>
    <p class="font-medium text-base text-stone-900 dark:text-stone-100">
        @if ($isOwnProfile)
            @if ($primaryContactPhone)
                {{ $primaryContactPhone }}
            @else
                {{ __('No contact number added.') }}
            @endif
        @elseif ($canViewContact)
            {{ $primaryContactPhone }}
        @else
            {{ __('Contact details will be available after interest acceptance.') }}
        @endif
    </p>
    <p class="mt-3 text-sm text-stone-600 dark:text-stone-400 leading-relaxed">{{ __('profile.show_contact_trust') }}</p>
    @if (!$isOwnProfile && !$canViewContact)
        <div class="mt-3 text-sm text-stone-600 dark:text-stone-400 border-t border-stone-200/80 dark:border-gray-600 pt-3">
            <span class="font-medium text-stone-700 dark:text-stone-300">{{ __('Contact policy:') }}</span>
            {{ __('Contact number is shared only after the other person accepts your interest. We do not reveal contact without mutual interest.') }}
        </div>
    @endif
</div>

@if(!empty($extendedValues))
    @php
        $filteredExtended = array_filter($extendedValues, function($v) {
            return $v !== null && $v !== '';
        });
    @endphp

    @if(!empty($filteredExtended))
        <div class="mt-8">
            <h3 class="text-lg font-semibold mb-4">{{ __('Additional Details') }}</h3>

            @foreach($filteredExtended as $label => $value)
                <div class="mb-2">
                    <p class="text-gray-500 text-sm">{{ $extendedMeta[$label] ?? $label }}</p>
                    <p class="font-medium text-base">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    @endif
@endif

@if ($viewerBrowsingOther)
</div>
@endif
