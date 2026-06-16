@extends('layouts.app')

@php
    $lookupLabel = static fn ($model): string => (string) ($model?->display_label ?? $model?->label ?? $model?->name ?? '');
    $ownRepresentationOptions = collect($ownRepresentationOptions ?? [])->values();
    $selectedOwnRepresentationId = (int) ($selectedOwnRepresentation['representation']['id'] ?? 0);
    $advancedFiltersActive = collect([
        $filters['marital_status_id'] ?? null,
        $filters['age_min'] ?? null,
        $filters['age_max'] ?? null,
        $filters['religion_id'] ?? null,
        $filters['caste_id'] ?? null,
    ])->contains(fn ($value): bool => filled($value));
    $safeOwnOptions = $ownRepresentationOptions
        ->map(function (array $option): array {
            $ownProfile = $option['own_profile'] ?? [];
            $searchText = collect([
                $option['option_label'] ?? null,
                $ownProfile['name'] ?? null,
                $ownProfile['gender'] ?? null,
                $ownProfile['age'] ?? null,
                $ownProfile['location'] ?? null,
                $ownProfile['education_job'] ?? null,
            ])->filter()->implode(' ');

            return [
                'id' => (int) ($option['representation']['id'] ?? 0),
                'label' => (string) ($option['option_label'] ?? 'Represented profile'),
                'name' => (string) ($ownProfile['name'] ?? 'Represented profile'),
                'gender' => (string) ($ownProfile['gender'] ?? ''),
                'age' => (string) ($ownProfile['age'] ?? ''),
                'location' => (string) ($ownProfile['location'] ?? ''),
                'education_job' => (string) ($ownProfile['education_job'] ?? ''),
                'photo_url' => (string) ($ownProfile['photo_url'] ?? asset('images/placeholders/default-profile.svg')),
                'search' => mb_strtolower($searchText),
            ];
        })
        ->filter(fn (array $option): bool => $option['id'] > 0)
        ->values();
    $communityText = static function (array $summary): string {
        $value = collect([$summary['community']['religion'] ?? null, $summary['community']['caste'] ?? null])
            ->filter()
            ->implode(' / ');

        return $value !== '' ? $value : 'Not available';
    };
    $locationText = static function (array $summary): string {
        $value = collect([$summary['location']['city'] ?? null, $summary['location']['district'] ?? null])
            ->filter()
            ->implode(', ');

        return $value !== '' ? $value : 'Broad location unavailable';
    };
    $ageHeightText = static function (array $summary): string {
        $age = isset($summary['basic']['age_years'])
            ? $summary['basic']['age_years'].' years'
            : ($summary['basic']['age_range'] ?? null);
        $height = $summary['basic']['height_feet_inches'] ?? ($summary['basic']['height_range'] ?? null);

        return collect([$age, $height])
            ->filter()
            ->implode(' / ') ?: 'Not available';
    };
    $educationJobText = static function (array $summary): string {
        return collect([$summary['education']['highest'] ?? null, $summary['occupation']['broad'] ?? null])
            ->filter()
            ->implode(' / ') ?: 'Not available';
    };
    $candidateLabel = static function (array $summary): string {
        $gender = trim((string) ($summary['basic']['gender'] ?? ''));

        return $gender !== '' ? $gender : 'Candidate';
    };
    $photoSource = static fn (array $summary): string => (string) (
        $summary['photo']['url']
        ?? $summary['photo']['placeholder_url']
        ?? asset('images/placeholders/default-profile.svg')
    );
@endphp

@section('content')
<div
    class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8"
    x-data="{
        open: false,
        result: null,
        filtersExpanded: @js($advancedFiltersActive),
        requestingId: @js($selectedOwnRepresentationId > 0 ? (string) $selectedOwnRepresentationId : ''),
        ownOptions: @js($safeOwnOptions),
        profilePickerOpen: false,
        modalProfilePickerOpen: false,
        profileQuery: '',
        modalProfileQuery: '',
        init() {
            const label = this.optionLabel(this.requestingId);
            if (this.requestingId) {
                this.profileQuery = label;
                this.modalProfileQuery = label;
            }
        },
        openResult(payload) {
            this.result = payload;
            this.open = true;
            this.modalProfileQuery = this.requestingId ? this.optionLabel(this.requestingId) : '';
            document.body.classList.add('overflow-hidden');
        },
        close() {
            this.open = false;
            this.result = null;
            document.body.classList.remove('overflow-hidden');
        },
        optionLabel(id) {
            const found = this.ownOptions.find((option) => String(option.id) === String(id));
            return found ? found.label : 'Select your represented profile';
        },
        selectedOwnOption() {
            return this.ownOptions.find((option) => String(option.id) === String(this.requestingId)) || null;
        },
        filteredOwnOptions(query) {
            const needle = String(query || '').trim().toLowerCase();
            const source = needle
                ? this.ownOptions.filter((option) => String(option.search || option.label || '').toLowerCase().includes(needle))
                : this.ownOptions;

            return source.slice(0, 12);
        },
        selectOwnOption(option) {
            if (!option) {
                return;
            }

            this.requestingId = String(option.id);
            this.profileQuery = option.label;
            this.modalProfileQuery = option.label;
            this.profilePickerOpen = false;
            this.modalProfilePickerOpen = false;
        },
        clearOwnOption() {
            this.requestingId = '';
            this.profileQuery = '';
            this.modalProfileQuery = '';
        },
        join(items, fallback) {
            const clean = (items || []).filter(Boolean);
            return clean.length ? clean.join(' · ') : fallback;
        },
    }"
    @keydown.escape.window="close()"
>
    <style>
        @media (min-width: 1024px) {
            .suchak-search-main-row {
                display: flex !important;
                align-items: center;
                gap: 0.5rem;
                flex-wrap: nowrap;
            }

            .suchak-search-main-row .suchak-search-field {
                min-width: 0;
            }

            .suchak-search-main-row .suchak-search-profile {
                flex: 1.25 1 0;
            }

            .suchak-search-main-row .suchak-search-query {
                flex: 0.9 1 0;
            }

            .suchak-search-main-row .suchak-search-gender {
                flex: 0 0 11rem;
            }

            .suchak-search-main-row .suchak-search-actions {
                display: flex !important;
                flex: 0 0 auto;
                gap: 0.5rem;
            }

            .suchak-search-main-row .suchak-search-actions > * {
                min-width: 6.25rem;
                white-space: nowrap;
            }

            .suchak-search-main-row .suchak-search-label,
            .suchak-search-main-row .suchak-search-profile-help {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }

            .suchak-search-main-row select,
            .suchak-search-main-row input {
                margin-top: 0 !important;
            }
        }
    </style>

    <div class="mb-5 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Find Matches</h1>
            <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Search collaboration-ready masked profiles from other Suchaks. Identity and contact stay hidden until governed collaboration rules allow exchange.
            </p>
        </div>
        <a href="{{ route('suchak.collaborations.index') }}" class="inline-flex w-fit rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
            Collaboration Center
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <form method="GET" action="{{ route('suchak.search.index') }}" class="suchak-search-form mb-5 rounded-lg border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="suchak-search-main-row grid gap-3 lg:items-center">
            <div class="suchak-search-field suchak-search-profile">
                <label for="requesting_representation_search" class="suchak-search-label block text-xs font-semibold text-gray-600 dark:text-gray-300">Profile to search for</label>
                <div class="relative mt-1" @click.outside="profilePickerOpen = false">
                    <input type="hidden" name="requesting_representation_id" :value="requestingId">
                    <div class="flex h-10 rounded-md border border-gray-300 bg-white shadow-sm focus-within:border-red-600 focus-within:ring-1 focus-within:ring-red-600 dark:border-gray-700 dark:bg-gray-900">
                        <input
                            id="requesting_representation_search"
                            type="search"
                            x-model="profileQuery"
                            @focus="profilePickerOpen = true"
                            @input="profilePickerOpen = true; if (requestingId && profileQuery !== optionLabel(requestingId)) requestingId = ''"
                            placeholder="Search your represented profile"
                            class="min-w-0 flex-1 border-0 bg-transparent px-3 text-sm text-gray-900 placeholder-gray-500 focus:ring-0 dark:text-gray-100"
                            @disabled($ownRepresentationOptions->isEmpty())
                        >
                        <button type="button" x-show="requestingId" @click="clearOwnOption(); profilePickerOpen = true" class="px-2 text-xs font-semibold text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100">Clear</button>
                    </div>
                    <div x-show="profilePickerOpen" x-cloak class="absolute left-0 right-0 z-30 mt-1 max-h-80 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900">
                        <template x-if="filteredOwnOptions(profileQuery).length === 0">
                            <div class="px-3 py-3 text-sm text-gray-500 dark:text-gray-400">No represented profile matched.</div>
                        </template>
                        <template x-for="option in filteredOwnOptions(profileQuery)" :key="option.id">
                            <button type="button" @click="selectOwnOption(option)" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-800">
                                <img :src="option.photo_url" alt="" class="h-9 w-9 rounded-full border border-gray-200 object-cover dark:border-gray-700">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="option.name"></span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="join([option.age, option.gender, option.location, option.education_job], option.label)"></span>
                                </span>
                            </button>
                        </template>
                        <template x-if="ownOptions.length > 12 && !profileQuery">
                            <div class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">Showing first 12. Type name, age, location, education, or gender to narrow.</div>
                        </template>
                    </div>
                </div>
                <p class="suchak-search-profile-help mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                    Type to find your side profile. Search results and fit signals use this selection.
                </p>
            </div>
            <div class="suchak-search-field suchak-search-query">
                <label for="q" class="suchak-search-label block text-xs font-semibold text-gray-600 dark:text-gray-300">Education/job</label>
                <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80" placeholder="Education/job" class="mt-1 h-10 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div class="suchak-search-field suchak-search-gender">
                <label for="gender_id" class="suchak-search-label block text-xs font-semibold text-gray-600 dark:text-gray-300">Gender</label>
                <select id="gender_id" name="gender_id" class="mt-1 h-10 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any gender</option>
                    @foreach ($genderOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['gender_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="suchak-search-actions grid gap-2 sm:grid-cols-3">
                <button type="submit" class="h-10 rounded-md bg-red-700 px-4 text-sm font-semibold text-white hover:bg-red-800">Search</button>
                <a href="{{ route('suchak.search.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900">Reset</a>
                <button type="button" @click="filtersExpanded = !filtersExpanded" class="h-10 rounded-md border border-gray-300 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900" x-text="filtersExpanded ? 'Compact' : 'Full search'"></button>
            </div>
        </div>

        <div x-show="filtersExpanded" x-cloak class="mt-3 grid gap-3 border-t border-gray-100 pt-3 dark:border-gray-700 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label for="marital_status_id" class="block text-xs font-semibold text-gray-600 dark:text-gray-300">Marital status</label>
                <select id="marital_status_id" name="marital_status_id" class="mt-1 h-10 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any status</option>
                    @foreach ($maritalStatusOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['marital_status_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="age_min" class="block text-xs font-semibold text-gray-600 dark:text-gray-300">Min age</label>
                <input id="age_min" name="age_min" type="number" min="18" max="100" value="{{ $filters['age_min'] ?? '' }}" class="mt-1 h-10 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label for="age_max" class="block text-xs font-semibold text-gray-600 dark:text-gray-300">Max age</label>
                <input id="age_max" name="age_max" type="number" min="18" max="100" value="{{ $filters['age_max'] ?? '' }}" class="mt-1 h-10 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label for="religion_id" class="block text-xs font-semibold text-gray-600 dark:text-gray-300">Religion</label>
                <select id="religion_id" name="religion_id" class="mt-1 h-10 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any religion</option>
                    @foreach ($religionOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['religion_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="caste_id" class="block text-xs font-semibold text-gray-600 dark:text-gray-300">Caste</label>
                <select id="caste_id" name="caste_id" class="mt-1 h-10 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Any caste</option>
                    @foreach ($casteOptions as $option)
                        <option value="{{ $option->id }}" @selected((int) ($filters['caste_id'] ?? 0) === (int) $option->id)>{{ $lookupLabel($option) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if ($ownRepresentationOptions->isEmpty())
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">No active represented profiles are available for request creation.</p>
        @endif
    </form>

    <div class="mb-3 flex flex-col gap-2 text-sm text-gray-600 dark:text-gray-300 md:flex-row md:items-center md:justify-between">
        <p>
            Showing {{ $results->firstItem() ?? 0 }}-{{ $results->lastItem() ?? 0 }} of {{ number_format($results->total()) }} profiles
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Requests go only to the selected target Suchak. No phone, email, exact address, family names, PDFs, or private notes are shown here.
        </p>
    </div>

    @if ($results->isNotEmpty())
        <div class="hidden overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800 md:block">
            <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-3">Photo</th>
                        <th class="px-3 py-3">Candidate</th>
                        <th class="px-3 py-3">Age/Height</th>
                        <th class="px-3 py-3">Community</th>
                        <th class="px-3 py-3">Location</th>
                        <th class="px-3 py-3">Education/Job</th>
                        <th class="px-3 py-3">Available through</th>
                        <th class="px-3 py-3">Fit</th>
                        <th class="px-3 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($results as $result)
                        @php
                            $targetCommunity = $communityText($result);
                            $targetLocation = $locationText($result);
                            $targetAgeHeight = $ageHeightText($result);
                            $targetEducationJob = $educationJobText($result);
                            $targetCandidateLabel = $candidateLabel($result);
                            $targetPhotoSource = $photoSource($result);
                            $targetPhotoLabel = (string) ($result['photo']['label'] ?? '');
                            $modalResult = [
                                'target_representation_id' => (int) ($result['representation']['id'] ?? 0),
                                'candidate_label' => $targetCandidateLabel,
                                'age_height' => $targetAgeHeight,
                                'community' => $targetCommunity,
                                'location' => $targetLocation,
                                'education_job' => $targetEducationJob,
                                'marital_status' => (string) ($result['basic']['marital_status'] ?? 'Not available'),
                                'target_suchak_label' => (string) ($result['target_suchak_label'] ?? 'Target Suchak'),
                                'fit_label' => (string) ($result['fit_label'] ?? 'Select your side profile'),
                                'fit_summary' => (string) ($result['fit_summary'] ?? 'Select your represented profile above to compare fit signals.'),
                                'reasons' => collect($result['reasons'] ?? [])->filter()->values()->all(),
                                'warnings' => collect($result['warnings'] ?? [])->filter()->values()->all(),
                            ];
                        @endphp
                        <tr class="align-top hover:bg-gray-50 dark:hover:bg-gray-900/60">
                            <td class="px-3 py-4">
                                <div class="flex items-center gap-2">
                                    <img src="{{ $targetPhotoSource }}" alt="" class="h-14 w-14 rounded-md border border-gray-200 object-cover object-center dark:border-gray-700" loading="lazy">
                                    @if (($result['photo']['url'] ?? null) === null)
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $targetPhotoLabel }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-4">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $targetCandidateLabel }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $result['basic']['marital_status'] ?? 'Marital status unavailable' }}</div>
                            </td>
                            <td class="px-3 py-4 text-gray-700 dark:text-gray-300">{{ $targetAgeHeight }}</td>
                            <td class="px-3 py-4 text-gray-700 dark:text-gray-300">{{ $targetCommunity }}</td>
                            <td class="px-3 py-4 text-gray-700 dark:text-gray-300">{{ $targetLocation }}</td>
                            <td class="max-w-[14rem] px-3 py-4 text-gray-700 dark:text-gray-300">{{ $targetEducationJob }}</td>
                            <td class="max-w-[12rem] px-3 py-4 text-gray-700 dark:text-gray-300">
                                {{ $result['target_suchak_label'] ?? 'Target Suchak' }}
                            </td>
                            <td class="max-w-[13rem] px-3 py-4">
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $result['fit_label'] ?? 'Select your side profile' }}</div>
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $result['fit_summary'] ?? 'Select your represented profile above to compare fit signals.' }}</div>
                            </td>
                            <td class="px-3 py-4 text-right">
                                <div class="flex justify-end gap-2">
                                    <button type="button" data-result='@json($modalResult, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)' @click="openResult(JSON.parse($el.dataset.result))" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900">
                                        Details
                                    </button>
                                    <button type="button" data-result='@json($modalResult, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)' @click="openResult(JSON.parse($el.dataset.result))" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                                        Request
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="space-y-3 md:hidden">
            @foreach ($results as $result)
                @php
                    $targetCommunity = $communityText($result);
                    $targetLocation = $locationText($result);
                    $targetAgeHeight = $ageHeightText($result);
                    $targetEducationJob = $educationJobText($result);
                    $targetCandidateLabel = $candidateLabel($result);
                    $targetPhotoSource = $photoSource($result);
                    $targetPhotoLabel = (string) ($result['photo']['label'] ?? '');
                    $modalResult = [
                        'target_representation_id' => (int) ($result['representation']['id'] ?? 0),
                        'candidate_label' => $targetCandidateLabel,
                        'age_height' => $targetAgeHeight,
                        'community' => $targetCommunity,
                        'location' => $targetLocation,
                        'education_job' => $targetEducationJob,
                        'marital_status' => (string) ($result['basic']['marital_status'] ?? 'Not available'),
                        'target_suchak_label' => (string) ($result['target_suchak_label'] ?? 'Target Suchak'),
                        'fit_label' => (string) ($result['fit_label'] ?? 'Select your side profile'),
                        'fit_summary' => (string) ($result['fit_summary'] ?? 'Select your represented profile above to compare fit signals.'),
                        'reasons' => collect($result['reasons'] ?? [])->filter()->values()->all(),
                        'warnings' => collect($result['warnings'] ?? [])->filter()->values()->all(),
                    ];
                @endphp
                <article class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 gap-3">
                            <img src="{{ $targetPhotoSource }}" alt="" class="h-14 w-14 shrink-0 rounded-md border border-gray-200 object-cover object-center dark:border-gray-700" loading="lazy">
                            <div class="min-w-0">
                                <p class="truncate text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $targetPhotoLabel }}</p>
                                <h2 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $targetCandidateLabel }}</h2>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $result['basic']['marital_status'] ?? 'Marital status unavailable' }}</p>
                            </div>
                        </div>
                        <div class="min-w-0 text-right">
                            <h2 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $result['fit_label'] ?? 'Select your side profile' }}</h2>
                        </div>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Age/Height</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $targetAgeHeight }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Community</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $targetCommunity }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Location</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $targetLocation }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Education/Job</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $targetEducationJob }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Available through: {{ $result['target_suchak_label'] ?? 'Target Suchak' }}</p>
                    <div class="mt-4 flex gap-2">
                        <button type="button" data-result='@json($modalResult, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)' @click="openResult(JSON.parse($el.dataset.result))" class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900">
                            Details
                        </button>
                        <button type="button" data-result='@json($modalResult, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)' @click="openResult(JSON.parse($el.dataset.result))" class="flex-1 rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Request
                        </button>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
            No profiles matched the current filters.
        </div>
    @endif

    <div class="mt-6">
        {{ $results->links() }}
    </div>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="suchak-search-request-title" @click.self="close()">
        <div x-show="open" x-transition class="max-h-[92vh] w-full overflow-y-auto rounded-t-lg bg-white shadow-xl dark:bg-gray-800 sm:max-w-2xl sm:rounded-lg">
            <div class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-800">
                <div>
                    <h2 id="suchak-search-request-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">Details and request</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-text="result ? result.candidate_label : 'Candidate'"></p>
                </div>
                <button type="button" @click="close()" class="rounded-md border border-gray-300 px-2.5 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900" aria-label="Close request modal">
                    Close
                </button>
            </div>

            <div class="space-y-5 px-5 py-5">
                <section class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Target summary</div>
                        <dl class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                            <div class="flex justify-between gap-3"><dt>Age/Height</dt><dd class="text-right font-medium" x-text="result ? result.age_height : '-'"></dd></div>
                            <div class="flex justify-between gap-3"><dt>Marital</dt><dd class="text-right font-medium" x-text="result ? result.marital_status : '-'"></dd></div>
                            <div class="flex justify-between gap-3"><dt>Community</dt><dd class="text-right font-medium" x-text="result ? result.community : '-'"></dd></div>
                            <div class="flex justify-between gap-3"><dt>Location</dt><dd class="text-right font-medium" x-text="result ? result.location : '-'"></dd></div>
                            <div class="flex justify-between gap-3"><dt>Education/Job</dt><dd class="text-right font-medium" x-text="result ? result.education_job : '-'"></dd></div>
                        </dl>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Routing</div>
                        <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="result ? result.target_suchak_label : 'Target Suchak'"></p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                            Request goes only to this Suchak/representation. Contact exchange remains locked until acceptance and commission acknowledgement.
                        </p>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Fit explanation</div>
                            <h3 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100" x-text="result ? result.fit_label : 'Select your side profile'"></h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-text="result ? result.fit_summary : 'Select your represented profile above to compare fit signals.'"></p>
                        </div>
                        <div class="rounded-md bg-gray-50 px-3 py-2 text-xs font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-300" x-text="optionLabel(requestingId)"></div>
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Reasons</div>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                                <template x-if="!result || !result.reasons || result.reasons.length === 0">
                                    <li>No deterministic reason yet.</li>
                                </template>
                                <template x-for="reason in (result ? result.reasons : [])" :key="reason">
                                    <li x-text="reason"></li>
                                </template>
                            </ul>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Review notes</div>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                                <template x-if="!result || !result.warnings || result.warnings.length === 0">
                                    <li>No warning notes.</li>
                                </template>
                                <template x-for="warning in (result ? result.warnings : [])" :key="warning">
                                    <li x-text="warning"></li>
                                </template>
                            </ul>
                        </div>
                    </div>
                </section>

                <form method="POST" action="{{ route('suchak.collaborations.store') }}" class="space-y-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    @csrf
                    <input type="hidden" name="target_representation_id" :value="result ? result.target_representation_id : ''">
                    <input type="hidden" name="split_type" value="{{ \App\Models\SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED }}">
                    <input type="hidden" name="currency" value="INR">

                    <div>
                        <label for="modal_requesting_representation_search" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">My side represented profile</label>
                        <div class="relative mt-1" @click.outside="modalProfilePickerOpen = false">
                            <input type="hidden" name="requesting_representation_id" :value="requestingId">
                            <div class="flex min-h-11 rounded-md border border-gray-300 bg-white shadow-sm focus-within:border-emerald-600 focus-within:ring-1 focus-within:ring-emerald-600 dark:border-gray-700 dark:bg-gray-950">
                                <input
                                    id="modal_requesting_representation_search"
                                    type="search"
                                    x-model="modalProfileQuery"
                                    @focus="modalProfilePickerOpen = true"
                                    @input="modalProfilePickerOpen = true; if (requestingId && modalProfileQuery !== optionLabel(requestingId)) requestingId = ''"
                                    placeholder="Search by name, age, location, education"
                                    class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-sm text-gray-900 placeholder-gray-500 focus:ring-0 dark:text-gray-100"
                                    @disabled($ownRepresentationOptions->isEmpty())
                                >
                                <button type="button" x-show="requestingId" @click="clearOwnOption(); modalProfilePickerOpen = true" class="px-3 text-xs font-semibold text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100">Clear</button>
                            </div>
                            <div x-show="modalProfilePickerOpen" x-cloak class="absolute left-0 right-0 z-30 mt-1 max-h-80 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900">
                                <template x-if="filteredOwnOptions(modalProfileQuery).length === 0">
                                    <div class="px-3 py-3 text-sm text-gray-500 dark:text-gray-400">No represented profile matched.</div>
                                </template>
                                <template x-for="option in filteredOwnOptions(modalProfileQuery)" :key="option.id">
                                    <button type="button" @click="selectOwnOption(option)" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <img :src="option.photo_url" alt="" class="h-10 w-10 rounded-full border border-gray-200 object-cover dark:border-gray-700">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="option.name"></span>
                                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="join([option.age, option.gender, option.location, option.education_job], option.label)"></span>
                                        </span>
                                    </button>
                                </template>
                                <template x-if="ownOptions.length > 12 && !modalProfileQuery">
                                    <div class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">Showing first 12. Type to narrow large profile lists.</div>
                                </template>
                            </div>
                        </div>
                        @if ($ownRepresentationOptions->isEmpty())
                            <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                Collaboration request करण्यासाठी तुमच्या बाजूचा active consented represented profile आवश्यक आहे.
                            </div>
                        @endif
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Type to select from your active consented profiles. Search again if you want table fit signals refreshed.</p>
                    </div>

                    <div>
                        <label for="message" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Message</label>
                        <textarea id="message" name="message" rows="3" maxlength="2000" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="Do not write phone, email, address, or family contact details."></textarea>
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        Privacy note: no full name, phone, WhatsApp, email, exact address, family names, PDFs, or private notes are shared from this screen.
                    </div>

                    <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="commission_ack" value="1" required class="mt-1">
                        <span>मी या match साठी commission / credit sharing terms मान्य करतो.</span>
                    </label>

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" @click="close()" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Cancel</button>
                        <button type="submit" :disabled="!result || !result.target_representation_id || !requestingId" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-gray-400">
                            Send collaboration request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
