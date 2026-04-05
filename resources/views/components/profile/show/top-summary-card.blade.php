@props([
    'profile',
    'profileHeadline' => '',
    'dateOfBirthVisible' => true,
    'heightVisible' => true,
    'locationVisible' => true,
    'educationVisible' => true,
    'maritalStatusVisible' => true,
    'verificationItems' => [],
    'isOwnProfile' => false,
    'interestAlreadySent' => false,
    'contactRequestDisabled' => true,
    'contactRequestState' => null,
    'canSendContactRequest' => false,
    'inShortlist' => false,
    'contactGrantReveal' => null,
    'contactAccess' => null,
])

@php
    $contactAccess = $contactAccess ?? ['show_contact_request_rail' => true];
    $profile->loadMissing(['city', 'district', 'state', 'maritalStatus', 'religion', 'caste', 'profession', 'incomeCurrency', 'familyIncomeCurrency']);
    $age = null;
    if ($dateOfBirthVisible && ($profile->date_of_birth ?? '') !== '') {
        try {
            $age = \Carbon\Carbon::parse($profile->date_of_birth)->age;
        } catch (\Throwable) {
            $age = null;
        }
    }
    $locationLine = \App\Support\ProfileDisplayCopy::compactLocationLine(
        $profile->city?->name,
        $profile->district?->name,
        $profile->state?->name
    );
    $incomeService = app(\App\Services\IncomeEngineService::class);
    $profileArr = $profile->toArray();
    $personalIncomeDisplay = $incomeService->formatForDisplay($profileArr, 'income', $profile->incomeCurrency);
    $hasPersonalIncome = ($profile->income_value_type ?? null) !== null || ($profile->income_amount ?? null) !== null || ($profile->income_min_amount ?? null) !== null || ($profile->annual_income ?? null) !== null;
    $incomeLine = $hasPersonalIncome ? $personalIncomeDisplay : null;
    $eduDisplay = ($educationVisible && ($profile->highest_education ?? '') !== '')
        ? (\App\Support\ProfileDisplayCopy::formatEducationPhrase($profile->highest_education) ?? $profile->highest_education)
        : null;
    $occDisplay = ($profile->occupation_title ?? '') !== ''
        ? \App\Support\ProfileDisplayCopy::formatOccupationPhrase($profile->occupation_title)
        : ($profile->profession?->name ? \App\Support\ProfileDisplayCopy::formatOccupationPhrase($profile->profession->name) : null);
    $trustCount = is_array($verificationItems) ? count(array_filter($verificationItems)) : 0;
    $nameDisplay = \App\Support\ProfileDisplayCopy::formatPersonName($profile->full_name);
@endphp

<section class="rounded-2xl border border-stone-200/70 bg-gradient-to-b from-white via-white to-stone-50/90 p-4 shadow-[0_8px_30px_-12px_rgba(28,25,23,0.12)] ring-1 ring-stone-100/90 dark:border-gray-700/80 dark:from-gray-900 dark:via-gray-900 dark:to-gray-950/90 dark:ring-gray-800/80 sm:p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-xl font-semibold tracking-tight text-stone-900 dark:text-stone-100 sm:text-2xl">{{ $nameDisplay }}</h2>
                @if ($trustCount > 0)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-800 ring-1 ring-emerald-200/80 dark:bg-emerald-950/50 dark:text-emerald-200 dark:ring-emerald-800/60">{{ __('profile.show_top_verified_badge') }}</span>
                @endif
            </div>
            @if (($profileHeadline ?? '') !== '')
                <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ $profileHeadline }}</p>
            @endif
            <p class="mt-1 text-xs text-stone-500 dark:text-stone-500">{{ __('profile.show_profile_ref', ['id' => $profile->id]) }}</p>
        </div>
    </div>

    @php
        $facts = [];
        if ($age !== null) {
            $facts[] = ['label' => __('profile.show_top_fact_age'), 'value' => __('profile.show_age_years', ['age' => $age])];
        }
        if ($heightVisible && ($profile->height_cm ?? '') !== '') {
            $facts[] = ['label' => __('profile.show_top_fact_height'), 'value' => $profile->height_cm.' cm'];
        }
        if ($maritalStatusVisible && $profile->maritalStatus) {
            $facts[] = ['label' => __('profile.show_top_fact_marital'), 'value' => $profile->maritalStatus->label ?? '—'];
        }
        if ($profile->religion) {
            $facts[] = ['label' => __('profile.show_top_fact_religion'), 'value' => $profile->religion->label ?? '—'];
        }
        if ($profile->caste) {
            $facts[] = ['label' => __('profile.show_top_fact_community'), 'value' => $profile->caste->label ?? '—'];
        }
        if ($locationLine !== '' && $locationVisible) {
            $facts[] = ['label' => __('profile.show_top_fact_location'), 'value' => $locationLine];
        }
        if ($eduDisplay !== null) {
            $facts[] = ['label' => __('profile.show_top_fact_education'), 'value' => \Illuminate\Support\Str::limit($eduDisplay, 48)];
        }
        if ($occDisplay !== null) {
            $facts[] = ['label' => __('profile.show_top_fact_occupation'), 'value' => \Illuminate\Support\Str::limit($occDisplay, 44)];
        }
        if ($incomeLine !== null) {
            $facts[] = ['label' => __('profile.show_top_fact_income'), 'value' => $incomeLine];
        }
    @endphp

    @if (count($facts) > 0)
        <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 border-t border-stone-200/70 pt-4 sm:grid-cols-2 dark:border-gray-700/80">
            @foreach ($facts as $fact)
                <div class="flex min-w-0 flex-col gap-0.5 sm:flex-row sm:items-baseline sm:gap-2">
                    <dt class="shrink-0 text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ $fact['label'] }}</dt>
                    <dd class="min-w-0 text-sm font-semibold leading-snug text-stone-900 dark:text-stone-100">{{ $fact['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if (auth()->check() && !$isOwnProfile)
        <div class="mt-4 border-t border-stone-200/70 pt-4 dark:border-gray-700/80">
            <p class="mb-3 text-center text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ __('profile.show_primary_actions_heading') }}</p>
            @include('matrimony.profile.partials.preference-match-actions', [
                'profile' => $profile,
                'isOwnProfile' => $isOwnProfile,
                'interestAlreadySent' => $interestAlreadySent,
                'contactRequestDisabled' => $contactRequestDisabled,
                'contactRequestState' => $contactRequestState,
                'canSendContactRequest' => $canSendContactRequest,
                'inShortlist' => $inShortlist,
                'contactGrantReveal' => $contactGrantReveal,
                'contactAccess' => $contactAccess,
            ])
        </div>
    @endif
</section>
