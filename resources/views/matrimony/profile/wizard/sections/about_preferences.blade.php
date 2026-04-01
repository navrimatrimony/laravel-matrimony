{{-- Phase-5B: Partner preferences — criteria + pivot-based engine. About Me is a separate tab. --}}
@php
    use App\Support\HeightDisplay;
    $criteria = $preferenceCriteria ?? null;
    $oldCriteria = [
        'preferred_age_min' => old('preferred_age_min', $criteria?->preferred_age_min ?? null),
        'preferred_age_max' => old('preferred_age_max', $criteria?->preferred_age_max ?? null),
        'preferred_income_min' => old('preferred_income_min', $criteria?->preferred_income_min ?? null),
        'preferred_income_max' => old('preferred_income_max', $criteria?->preferred_income_max ?? null),
        'willing_to_relocate' => old('willing_to_relocate', $criteria?->willing_to_relocate ?? null),
        'marriage_type_preference_id' => old('marriage_type_preference_id', $criteria?->marriage_type_preference_id ?? null),
        'preferred_height_min_cm' => old('preferred_height_min_cm', $criteria?->preferred_height_min_cm ?? null),
        'preferred_height_max_cm' => old('preferred_height_max_cm', $criteria?->preferred_height_max_cm ?? null),
    ];
    $selectedReligionIds = collect(old('preferred_religion_ids', $preferredReligionIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedCasteIds = collect(old('preferred_caste_ids', $preferredCasteIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedCasteMap = [];
    foreach ($selectedCasteIds as $sid) {
        $selectedCasteMap[$sid] = true;
    }
    $selectedDistrictIds = collect(old('preferred_district_ids', $preferredDistrictIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedCountryIds = collect(old('preferred_country_ids', $preferredCountryIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedStateIds = collect(old('preferred_state_ids', $preferredStateIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedTalukaIds = collect(old('preferred_taluka_ids', $preferredTalukaIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedMasterEducationIds = collect(old('preferred_master_education_ids', $preferredMasterEducationIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedWorkingWithTypeIds = collect(old('preferred_working_with_type_ids', $preferredWorkingWithTypeIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedProfessionIds = collect(old('preferred_profession_ids', $preferredProfessionIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedDietIds = collect(old('preferred_diet_ids', $preferredDietIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedProfileManagedBy = old('preferred_profile_managed_by', $criteria?->preferred_profile_managed_by ?? null);
    $ageRangeDefault = (isset($profile) && $profile instanceof \App\Models\MatrimonyProfile)
        ? \App\Services\PartnerPreferenceSuggestionService::defaultPreferredAgeRange($profile)
        : null;
    $dAgeMin = $ageRangeDefault['min'] ?? 22;
    $dAgeMax = $ageRangeDefault['max'] ?? 35;
    $rawAgeMin = $oldCriteria['preferred_age_min'];
    $rawAgeMax = $oldCriteria['preferred_age_max'];
    $rawAgeMin = ($rawAgeMin === '' || $rawAgeMin === null) ? null : (int) $rawAgeMin;
    $rawAgeMax = ($rawAgeMax === '' || $rawAgeMax === null) ? null : (int) $rawAgeMax;
    $ageMinInit = $rawAgeMin ?? $dAgeMin;
    $ageMaxInit = $rawAgeMax ?? $dAgeMax;
    $ageMinInit = max(18, min(80, $ageMinInit));
    $ageMaxInit = max(18, min(80, $ageMaxInit));
    if ($ageMinInit > $ageMaxInit) {
        [$ageMinInit, $ageMaxInit] = [$ageMaxInit, $ageMinInit];
    }
    $heightSuggest = (isset($profile) && $profile instanceof \App\Models\MatrimonyProfile)
        ? \App\Services\PartnerPreferenceSuggestionService::defaultPreferredHeightRangeCm($profile)
        : null;
    $hasSavedHeightCm = ($criteria?->preferred_height_min_cm ?? null) !== null
        || ($criteria?->preferred_height_max_cm ?? null) !== null;
    $rawHMin = $oldCriteria['preferred_height_min_cm'];
    $rawHMax = $oldCriteria['preferred_height_max_cm'];
    $rawHMin = ($rawHMin === '' || $rawHMin === null) ? null : (int) $rawHMin;
    $rawHMax = ($rawHMax === '' || $rawHMax === null) ? null : (int) $rawHMax;
    $heightSliderMin = 120;
    $heightSliderMax = 220;
    $heightMinValue = $rawHMin;
    $heightMaxValue = $rawHMax;
    if ($heightMinValue === null && $heightMaxValue === null && $heightSuggest !== null) {
        $heightMinValue = $heightSuggest['min'];
        $heightMaxValue = $heightSuggest['max'];
    }
    $heightMinInit = $heightMinValue !== null ? (int) $heightMinValue : 135;
    $heightMaxInit = $heightMaxValue !== null ? (int) $heightMaxValue : 185;
    if ($rawHMin === null && $rawHMax === null && $heightSuggest === null) {
        $heightMinInit = 135;
        $heightMaxInit = 185;
    }
    $heightMinInit = max($heightSliderMin, min($heightSliderMax, $heightMinInit));
    $heightMaxInit = max($heightSliderMin, min($heightSliderMax, $heightMaxInit));
    if ($heightMinInit > $heightMaxInit) {
        [$heightMinInit, $heightMaxInit] = [$heightMaxInit, $heightMinInit];
    }
    $showHeightHint = ($heightSuggest !== null && ! $hasSavedHeightCm);
    $rupeesToLakhs = function ($v): ?int {
        if ($v === null || $v === '') {
            return null;
        }

        return max(0, min(500, (int) round((float) $v / 100000)));
    };
    $incMinLakhs = $rupeesToLakhs($oldCriteria['preferred_income_min'] ?? null);
    $incMaxLakhs = $rupeesToLakhs($oldCriteria['preferred_income_max'] ?? null);
    if ($incMinLakhs === null && $incMaxLakhs === null) {
        $incMinLakhs = 3;
        $incMaxLakhs = 25;
    } elseif ($incMinLakhs === null) {
        $incMinLakhs = max(0, $incMaxLakhs - 15);
    } elseif ($incMaxLakhs === null) {
        $incMaxLakhs = min(500, $incMinLakhs + 22);
    }
    if ($incMinLakhs > $incMaxLakhs) {
        [$incMinLakhs, $incMaxLakhs] = [$incMaxLakhs, $incMinLakhs];
    }
    $incomeFmtLakh = function (int $l): string {
        return $l === 0 ? __('wizard.income_lakh_zero') : __('wizard.income_lakh_format', ['num' => $l]);
    };
    $optionLabel = function ($row, string $field) {
        $key = $row->key ?? null;
        $dbLabel = $row->label ?? '';
        if ($key) {
            $tKey = 'components.options.' . $field . '.' . $key;
            $t = __($tKey);
            if ($t !== $tKey) {
                return $t;
            }
        }

        return $dbLabel;
    };
@endphp
@php
    $activePref = $partnerPrefSection ?? 'basics';
@endphp

<div class="space-y-5" id="partner-pref-workspace">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2 flex-1">{{ __('wizard.partner_preferences') }}</h2>
        <div class="ml-4 flex items-center gap-2 text-xs">
            <span class="text-gray-500 dark:text-gray-400">{{ __('wizard.preset') }}:</span>
            @php
                $currentPreset = old('preference_preset', $preferencePreset ?? 'balanced');
            @endphp
            <select id="preference_preset" name="preference_preset" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-xs px-2 py-1">
                <option value="custom" {{ $currentPreset === 'custom' ? 'selected' : '' }}>{{ __('wizard.preset_custom') }}</option>
                <option value="traditional" {{ $currentPreset === 'traditional' ? 'selected' : '' }}>{{ __('wizard.preset_traditional') }}</option>
                <option value="balanced" {{ $currentPreset === 'balanced' ? 'selected' : '' }}>{{ __('wizard.preset_balanced') }}</option>
                <option value="broad" {{ $currentPreset === 'broad' ? 'selected' : '' }}>{{ __('wizard.preset_broad') }}</option>
            </select>
        </div>
    </div>

    <p class="text-sm text-gray-600 dark:text-gray-400 -mt-1">{{ __('wizard.partner_pref_workspace_intro') }}</p>
    {{-- Day-38: trust copy only; request keys and save flow unchanged. --}}
    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-1">{{ __('wizard.partner_preferences_trust_note') }}</p>
    <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400">{{ __('wizard.partner_pref_section_' . $activePref) }}</p>

    <div class="space-y-4 {{ $activePref !== 'basics' ? 'hidden' : '' }}">
        @include('matrimony.profile.wizard.partials.partner_pref_basics_core')
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 p-4 space-y-4 mt-6 {{ $activePref !== 'location' ? 'hidden' : '' }}" id="partner-pref-location-section">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('wizard.partner_location_heading') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2 mb-1">{{ __('wizard.partner_pref_location_microcopy') }}</p>
        <div class="rounded-lg border border-indigo-200/90 dark:border-indigo-500/35 bg-indigo-50/80 dark:bg-indigo-950/30 p-3 sm:p-4 shadow-sm">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="willing_to_relocate" value="1"
                    class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-0 dark:focus:ring-offset-gray-900"
                    {{ $oldCriteria['willing_to_relocate'] ? 'checked' : '' }}>
                <span class="min-w-0 flex flex-col gap-0.5">
                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.willing_to_relocate') }}</span>
                    <span class="text-xs text-gray-600 dark:text-gray-400 leading-snug">{{ __('wizard.willing_to_relocate_hint') }}</span>
                </span>
            </label>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.preferred_countries') }}</label>
            <div class="flex flex-wrap gap-2" id="partner-location-country-chips">
                @foreach(($allCountries ?? collect()) as $country)
                    <label class="inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2.5 py-1 text-sm cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500">
                        <input type="checkbox" name="preferred_country_ids[]" value="{{ $country->id }}" class="partner-pref-country-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"
                            @if(in_array($country->id, $selectedCountryIds, true)) checked @endif>
                        <span class="text-gray-800 dark:text-gray-100">{{ $country->name }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.preferred_states') }}</label>
            <p id="partner-location-state-placeholder" class="text-xs text-gray-500 dark:text-gray-400 mb-2 min-h-[1rem]">{{ count($selectedCountryIds) ? '' : __('wizard.select_country_first') }}</p>
            <input type="search" id="partner-location-state-filter" class="{{ count($selectedCountryIds) ? '' : 'hidden' }} w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm mb-2" placeholder="{{ __('wizard.filter_locations') }}" autocomplete="off">
            <div id="partner-location-state-wrap" class="{{ count($selectedCountryIds) ? '' : 'hidden' }} max-h-36 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-gray-50 dark:bg-gray-900/40">
                <div id="partner-location-state-chips" class="flex flex-wrap gap-2 content-start"></div>
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.preferred_districts') }}</label>
            <p id="partner-location-district-placeholder" class="text-xs text-gray-500 dark:text-gray-400 mb-2 min-h-[1rem]">{{ count($selectedStateIds) ? '' : __('wizard.select_state_first') }}</p>
            <input type="search" id="partner-location-district-filter" class="{{ count($selectedStateIds) ? '' : 'hidden' }} w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm mb-2" placeholder="{{ __('wizard.filter_locations') }}" autocomplete="off">
            <div id="partner-location-district-wrap" class="{{ count($selectedStateIds) ? '' : 'hidden' }} max-h-36 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-gray-50 dark:bg-gray-900/40">
                <div id="partner-location-district-chips" class="flex flex-wrap gap-2 content-start"></div>
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.preferred_talukas') }}</label>
            <p id="partner-location-taluka-placeholder" class="text-xs text-gray-500 dark:text-gray-400 mb-2 min-h-[1rem]">{{ count($selectedDistrictIds) ? '' : __('wizard.select_district_first') }}</p>
            <input type="search" id="partner-location-taluka-filter" class="{{ count($selectedDistrictIds) ? '' : 'hidden' }} w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm mb-2" placeholder="{{ __('wizard.filter_locations') }}" autocomplete="off">
            <div id="partner-location-taluka-wrap" class="{{ count($selectedDistrictIds) ? '' : 'hidden' }} max-h-36 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-gray-50 dark:bg-gray-900/40">
                <div id="partner-location-taluka-chips" class="flex flex-wrap gap-2 content-start"></div>
            </div>
        </div>
    </div>

    <div class="{{ $activePref !== 'education' ? 'hidden' : '' }}">
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('wizard.partner_pref_education_microcopy') }}</p>
        @include('matrimony.profile.wizard.partials.partner_preferences_education_career')
    </div>

    <script>
        (function () {
            var apiBase = @json($partnerLocationApiBase ?? url('/api/internal/location'));
            var stateById = @json($partnerLocationStateById ?? []);
            var districtById = @json($partnerLocationDistrictById ?? []);
            var talukaById = @json($partnerLocationTalukaById ?? []);
            var selectedStateMap = @json(array_fill_keys($selectedStateIds, true));
            var selectedDistrictMap = @json(array_fill_keys($selectedDistrictIds, true));
            var selectedTalukaMap = @json(array_fill_keys($selectedTalukaIds, true));
            var orphanHint = @json(__('wizard.location_orphan_hint'));
            var debounceTimer;

            function getSelectedCountryIds() {
                var ids = [];
                document.querySelectorAll('input.partner-pref-country-cb:checked').forEach(function (cb) {
                    ids.push(parseInt(cb.value, 10));
                });
                return ids;
            }

            function getSelectedStateIds() {
                var ids = [];
                document.querySelectorAll('input.partner-pref-state-cb:checked').forEach(function (cb) {
                    ids.push(parseInt(cb.value, 10));
                });
                return ids;
            }

            function getSelectedDistrictIds() {
                var ids = [];
                document.querySelectorAll('input.partner-pref-district-cb:checked').forEach(function (cb) {
                    ids.push(parseInt(cb.value, 10));
                });
                return ids;
            }

            function getStateIdsFromMap() {
                return Object.keys(selectedStateMap)
                    .filter(function (k) {
                        return selectedStateMap[k];
                    })
                    .map(function (k) {
                        return parseInt(k, 10);
                    });
            }

            function getDistrictIdsFromMap() {
                return Object.keys(selectedDistrictMap)
                    .filter(function (k) {
                        return selectedDistrictMap[k];
                    })
                    .map(function (k) {
                        return parseInt(k, 10);
                    });
            }

            function pruneAfterCountryChange() {
                var cids = getSelectedCountryIds();
                Object.keys(selectedStateMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedStateMap[id]) {
                        return;
                    }
                    var meta = stateById[String(id)] || stateById[id];
                    if (meta && cids.indexOf(meta.country_id) === -1) {
                        delete selectedStateMap[id];
                    }
                });
                pruneAfterStateChange();
            }

            function pruneAfterStateChange() {
                var sids = getStateIdsFromMap();
                Object.keys(selectedDistrictMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedDistrictMap[id]) {
                        return;
                    }
                    var meta = districtById[String(id)] || districtById[id];
                    if (meta && sids.indexOf(meta.state_id) === -1) {
                        delete selectedDistrictMap[id];
                    }
                });
                pruneAfterDistrictChange();
            }

            function pruneAfterDistrictChange() {
                var dids = getDistrictIdsFromMap();
                Object.keys(selectedTalukaMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedTalukaMap[id]) {
                        return;
                    }
                    var meta = talukaById[String(id)] || talukaById[id];
                    if (meta && dids.indexOf(meta.district_id) === -1) {
                        delete selectedTalukaMap[id];
                    }
                });
            }

            function syncStateUi() {
                var ph = document.getElementById('partner-location-state-placeholder');
                var wrap = document.getElementById('partner-location-state-wrap');
                var filterEl = document.getElementById('partner-location-state-filter');
                var countryIds = getSelectedCountryIds();
                var hasOrphan = false;
                Object.keys(selectedStateMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedStateMap[id]) {
                        return;
                    }
                    var meta = stateById[String(id)] || stateById[id];
                    if (meta && countryIds.indexOf(meta.country_id) === -1) {
                        hasOrphan = true;
                    }
                });
                var show = countryIds.length > 0 || hasOrphan || Object.keys(selectedStateMap).length > 0;
                if (ph) {
                    if (countryIds.length === 0 && !hasOrphan) {
                        ph.textContent = @json(__('wizard.select_country_first'));
                        ph.classList.remove('hidden');
                    } else if (countryIds.length === 0 && hasOrphan) {
                        ph.textContent = orphanHint;
                        ph.classList.remove('hidden');
                    } else {
                        ph.textContent = '';
                        ph.classList.add('hidden');
                    }
                }
                if (wrap) {
                    wrap.classList.toggle('hidden', !show);
                }
                if (filterEl) {
                    filterEl.classList.toggle('hidden', !show);
                }
            }

            function syncDistrictUi() {
                var ph = document.getElementById('partner-location-district-placeholder');
                var wrap = document.getElementById('partner-location-district-wrap');
                var filterEl = document.getElementById('partner-location-district-filter');
                var stateIds = getStateIdsFromMap();
                var hasOrphan = false;
                Object.keys(selectedDistrictMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedDistrictMap[id]) {
                        return;
                    }
                    var meta = districtById[String(id)] || districtById[id];
                    if (meta && stateIds.indexOf(meta.state_id) === -1) {
                        hasOrphan = true;
                    }
                });
                var show = stateIds.length > 0 || hasOrphan || Object.keys(selectedDistrictMap).length > 0;
                if (ph) {
                    if (stateIds.length === 0 && !hasOrphan) {
                        ph.textContent = @json(__('wizard.select_state_first'));
                        ph.classList.remove('hidden');
                    } else if (stateIds.length === 0 && hasOrphan) {
                        ph.textContent = orphanHint;
                        ph.classList.remove('hidden');
                    } else {
                        ph.textContent = '';
                        ph.classList.add('hidden');
                    }
                }
                if (wrap) {
                    wrap.classList.toggle('hidden', !show);
                }
                if (filterEl) {
                    filterEl.classList.toggle('hidden', !show);
                }
            }

            function syncTalukaUi() {
                var ph = document.getElementById('partner-location-taluka-placeholder');
                var wrap = document.getElementById('partner-location-taluka-wrap');
                var filterEl = document.getElementById('partner-location-taluka-filter');
                var districtIds = getDistrictIdsFromMap();
                var hasOrphan = false;
                Object.keys(selectedTalukaMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedTalukaMap[id]) {
                        return;
                    }
                    var meta = talukaById[String(id)] || talukaById[id];
                    if (meta && districtIds.indexOf(meta.district_id) === -1) {
                        hasOrphan = true;
                    }
                });
                var show = districtIds.length > 0 || hasOrphan || Object.keys(selectedTalukaMap).length > 0;
                if (ph) {
                    if (districtIds.length === 0 && !hasOrphan) {
                        ph.textContent = @json(__('wizard.select_district_first'));
                        ph.classList.remove('hidden');
                    } else if (districtIds.length === 0 && hasOrphan) {
                        ph.textContent = orphanHint;
                        ph.classList.remove('hidden');
                    } else {
                        ph.textContent = '';
                        ph.classList.add('hidden');
                    }
                }
                if (wrap) {
                    wrap.classList.toggle('hidden', !show);
                }
                if (filterEl) {
                    filterEl.classList.toggle('hidden', !show);
                }
            }

            function mergeStateRows(rows) {
                var seen = {};
                var items = [];
                (rows || []).forEach(function (row) {
                    seen[row.id] = true;
                    items.push({ id: row.id, label: row.name, country_id: row.country_id, orphan: false });
                });
                Object.keys(selectedStateMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedStateMap[id] || seen[id]) {
                        return;
                    }
                    var meta = stateById[String(id)] || stateById[id];
                    if (meta) {
                        items.push({ id: meta.id, label: meta.name, country_id: meta.country_id, orphan: true });
                        seen[id] = true;
                    }
                });
                return items;
            }

            function mergeDistrictRows(rows) {
                var seen = {};
                var items = [];
                (rows || []).forEach(function (row) {
                    seen[row.id] = true;
                    items.push({ id: row.id, label: row.name, state_id: row.state_id, orphan: false });
                });
                Object.keys(selectedDistrictMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedDistrictMap[id] || seen[id]) {
                        return;
                    }
                    var meta = districtById[String(id)] || districtById[id];
                    if (meta) {
                        items.push({ id: meta.id, label: meta.name, state_id: meta.state_id, orphan: true });
                        seen[id] = true;
                    }
                });
                return items;
            }

            function mergeTalukaRows(rows) {
                var seen = {};
                var items = [];
                (rows || []).forEach(function (row) {
                    seen[row.id] = true;
                    items.push({ id: row.id, label: row.name, district_id: row.district_id, orphan: false });
                });
                Object.keys(selectedTalukaMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedTalukaMap[id] || seen[id]) {
                        return;
                    }
                    var meta = talukaById[String(id)] || talukaById[id];
                    if (meta) {
                        items.push({ id: meta.id, label: meta.name, district_id: meta.district_id, orphan: true });
                        seen[id] = true;
                    }
                });
                return items;
            }

            function esc(s) {
                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
            }

            function paintStateChips(items) {
                var inner = document.getElementById('partner-location-state-chips');
                if (!inner) {
                    return;
                }
                items.sort(function (a, b) {
                    return a.label.localeCompare(b.label);
                });
                var html = [];
                items.forEach(function (item) {
                    var checked = selectedStateMap[item.id] ? ' checked' : '';
                    var orphanClass = item.orphan ? ' border-amber-400 dark:border-amber-600 bg-amber-50/80 dark:bg-amber-900/20' : '';
                    var title = item.orphan ? orphanHint : '';
                    html.push(
                        '<label class="partner-state-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer' +
                            orphanClass +
                            '" data-chip-label="' +
                            esc(item.label) +
                            '"' +
                            (title ? ' title="' + esc(title) + '"' : '') +
                            '>' +
                            '<input type="checkbox" name="preferred_state_ids[]" value="' +
                            item.id +
                            '" class="partner-pref-state-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"' +
                            checked +
                            '>' +
                            '<span class="text-gray-800 dark:text-gray-100">' +
                            esc(item.label) +
                            '</span></label>'
                    );
                });
                inner.innerHTML = html.join('');
                inner.querySelectorAll('input.partner-pref-state-cb').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var id = parseInt(cb.value, 10);
                        if (cb.checked) {
                            selectedStateMap[id] = true;
                        } else {
                            delete selectedStateMap[id];
                        }
                        pruneAfterStateChange();
                        renderDistrictChips();
                    });
                });
                applyLocationFilter('partner-state-chip', 'partner-location-state-filter');
            }

            function paintDistrictChips(items) {
                var inner = document.getElementById('partner-location-district-chips');
                if (!inner) {
                    return;
                }
                items.sort(function (a, b) {
                    return a.label.localeCompare(b.label);
                });
                var html = [];
                items.forEach(function (item) {
                    var checked = selectedDistrictMap[item.id] ? ' checked' : '';
                    var orphanClass = item.orphan ? ' border-amber-400 dark:border-amber-600 bg-amber-50/80 dark:bg-amber-900/20' : '';
                    var title = item.orphan ? orphanHint : '';
                    html.push(
                        '<label class="partner-district-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer' +
                            orphanClass +
                            '" data-chip-label="' +
                            esc(item.label) +
                            '"' +
                            (title ? ' title="' + esc(title) + '"' : '') +
                            '>' +
                            '<input type="checkbox" name="preferred_district_ids[]" value="' +
                            item.id +
                            '" class="partner-pref-district-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"' +
                            checked +
                            '>' +
                            '<span class="text-gray-800 dark:text-gray-100">' +
                            esc(item.label) +
                            '</span></label>'
                    );
                });
                inner.innerHTML = html.join('');
                inner.querySelectorAll('input.partner-pref-district-cb').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var id = parseInt(cb.value, 10);
                        if (cb.checked) {
                            selectedDistrictMap[id] = true;
                        } else {
                            delete selectedDistrictMap[id];
                        }
                        pruneAfterDistrictChange();
                        renderTalukaChips();
                    });
                });
                applyLocationFilter('partner-district-chip', 'partner-location-district-filter');
            }

            function paintTalukaChips(items) {
                var inner = document.getElementById('partner-location-taluka-chips');
                if (!inner) {
                    return;
                }
                items.sort(function (a, b) {
                    return a.label.localeCompare(b.label);
                });
                var html = [];
                items.forEach(function (item) {
                    var checked = selectedTalukaMap[item.id] ? ' checked' : '';
                    var orphanClass = item.orphan ? ' border-amber-400 dark:border-amber-600 bg-amber-50/80 dark:bg-amber-900/20' : '';
                    var title = item.orphan ? orphanHint : '';
                    html.push(
                        '<label class="partner-taluka-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer' +
                            orphanClass +
                            '" data-chip-label="' +
                            esc(item.label) +
                            '"' +
                            (title ? ' title="' + esc(title) + '"' : '') +
                            '>' +
                            '<input type="checkbox" name="preferred_taluka_ids[]" value="' +
                            item.id +
                            '" class="partner-pref-taluka-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"' +
                            checked +
                            '>' +
                            '<span class="text-gray-800 dark:text-gray-100">' +
                            esc(item.label) +
                            '</span></label>'
                    );
                });
                inner.innerHTML = html.join('');
                inner.querySelectorAll('input.partner-pref-taluka-cb').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var id = parseInt(cb.value, 10);
                        if (cb.checked) {
                            selectedTalukaMap[id] = true;
                        } else {
                            delete selectedTalukaMap[id];
                        }
                    });
                });
                applyLocationFilter('partner-taluka-chip', 'partner-location-taluka-filter');
            }

            function applyLocationFilter(chipClass, filterId) {
                var q = ((document.getElementById(filterId) || {}).value || '').trim().toLowerCase();
                document.querySelectorAll('.' + chipClass).forEach(function (el) {
                    var lab = (el.getAttribute('data-chip-label') || el.textContent || '').toLowerCase();
                    el.style.display = !q || lab.indexOf(q) !== -1 ? '' : 'none';
                });
            }

            function renderStateChips() {
                syncStateUi();
                var countryIds = getSelectedCountryIds();
                if (countryIds.length === 0) {
                    paintStateChips(mergeStateRows([]));
                    return Promise.resolve();
                }
                var params = new URLSearchParams();
                countryIds.forEach(function (id) {
                    params.append('country_ids[]', id);
                });
                return fetch(apiBase + '/states?' + params.toString(), { headers: { Accept: 'application/json' } })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (data) {
                        if (!data.success || !Array.isArray(data.data)) {
                            return;
                        }
                        data.data.forEach(function (row) {
                            stateById[row.id] = { id: row.id, name: row.name, country_id: row.country_id };
                        });
                        paintStateChips(mergeStateRows(data.data));
                    })
                    .catch(function () {});
            }

            function renderDistrictChips() {
                syncDistrictUi();
                var stateIds = getSelectedStateIds();
                if (stateIds.length === 0) {
                    paintDistrictChips(mergeDistrictRows([]));
                    return Promise.resolve();
                }
                var params = new URLSearchParams();
                stateIds.forEach(function (id) {
                    params.append('state_ids[]', id);
                });
                return fetch(apiBase + '/districts?' + params.toString(), { headers: { Accept: 'application/json' } })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (data) {
                        if (!data.success || !Array.isArray(data.data)) {
                            return;
                        }
                        data.data.forEach(function (row) {
                            districtById[row.id] = { id: row.id, name: row.name, state_id: row.state_id };
                        });
                        paintDistrictChips(mergeDistrictRows(data.data));
                    })
                    .catch(function () {});
            }

            function renderTalukaChips() {
                syncTalukaUi();
                var districtIds = getSelectedDistrictIds();
                if (districtIds.length === 0) {
                    paintTalukaChips(mergeTalukaRows([]));
                    return Promise.resolve();
                }
                var params = new URLSearchParams();
                districtIds.forEach(function (id) {
                    params.append('district_ids[]', id);
                });
                return fetch(apiBase + '/talukas?' + params.toString(), { headers: { Accept: 'application/json' } })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (data) {
                        if (!data.success || !Array.isArray(data.data)) {
                            return;
                        }
                        data.data.forEach(function (row) {
                            talukaById[row.id] = { id: row.id, name: row.name, district_id: row.district_id };
                        });
                        paintTalukaChips(mergeTalukaRows(data.data));
                    })
                    .catch(function () {});
            }

            function scheduleRenderStates() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    pruneAfterCountryChange();
                    renderStateChips().then(function () {
                        return renderDistrictChips();
                    }).then(function () {
                        return renderTalukaChips();
                    });
                }, 200);
            }

            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('input.partner-pref-country-cb').forEach(function (cb) {
                    cb.addEventListener('change', scheduleRenderStates);
                });
                var sf = document.getElementById('partner-location-state-filter');
                var df = document.getElementById('partner-location-district-filter');
                var tf = document.getElementById('partner-location-taluka-filter');
                if (sf) {
                    sf.addEventListener('input', function () {
                        applyLocationFilter('partner-state-chip', 'partner-location-state-filter');
                    });
                }
                if (df) {
                    df.addEventListener('input', function () {
                        applyLocationFilter('partner-district-chip', 'partner-location-district-filter');
                    });
                }
                if (tf) {
                    tf.addEventListener('input', function () {
                        applyLocationFilter('partner-taluka-chip', 'partner-location-taluka-filter');
                    });
                }
                renderStateChips().then(function () {
                    return renderDistrictChips();
                }).then(function () {
                    return renderTalukaChips();
                });
            });
        })();
    </script>

    <div class="space-y-4 {{ $activePref !== 'community' ? 'hidden' : '' }}">
    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 p-4 space-y-4 mt-6">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('wizard.community') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2 mb-1">{{ __('wizard.partner_pref_community_microcopy') }}</p>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.preferred_religions') }}</label>
            <div class="flex flex-wrap gap-2" id="partner-community-religion-chips">
                @foreach(($allReligions ?? collect()) as $religion)
                    <label class="inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2.5 py-1 text-sm cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500">
                        <input type="checkbox" name="preferred_religion_ids[]" value="{{ $religion->id }}" class="partner-religion-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"
                            @if(in_array($religion->id, $selectedReligionIds, true)) checked @endif>
                        <span class="text-gray-800 dark:text-gray-100">{{ $optionLabel($religion, 'religion') }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.preferred_caste') }}</label>
            <p id="partner-community-caste-placeholder" class="text-xs text-gray-500 dark:text-gray-400 mb-2 min-h-[1rem] {{ count($selectedReligionIds) ? 'hidden' : '' }}">{{ __('wizard.select_religion_first') }}</p>
            <input type="search" id="partner-caste-filter" class="{{ count($selectedReligionIds) ? '' : 'hidden' }} w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm mb-2" placeholder="{{ __('wizard.filter_castes') }}" autocomplete="off">
            <div id="partner-community-caste-wrap" class="{{ count($selectedReligionIds) ? '' : 'hidden' }} max-h-36 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-gray-50 dark:bg-gray-900/40">
                <div id="partner-community-caste-chips" class="flex flex-wrap gap-2 content-start"></div>
            </div>
        </div>
        <div>
            <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <input type="checkbox" name="preferred_intercaste" value="1" class="rounded border-gray-300 dark:border-gray-600"
                    {{ old('preferred_intercaste', $interestedInIntercaste ?? false) ? 'checked' : '' }}>
                <span>{{ __('wizard.open_to_intercaste') }}</span>
            </label>
        </div>
    </div>
    <script>
        (function () {
            var byRel = @json($partnerCastesByReligion ?? []);
            var byId = @json($partnerCasteById ?? []);
            var selectedCasteState = @json($selectedCasteMap ?? []);
            var orphanHint = @json(__('wizard.caste_orphan_hint'));

            function getSelectedReligionIds() {
                var ids = [];
                document.querySelectorAll('input.partner-religion-cb:checked').forEach(function (cb) {
                    ids.push(parseInt(cb.value, 10));
                });
                return ids;
            }

            function renderCasteChips() {
                var relIds = getSelectedReligionIds();
                var wrap = document.getElementById('partner-community-caste-wrap');
                var inner = document.getElementById('partner-community-caste-chips');
                var placeholder = document.getElementById('partner-community-caste-placeholder');
                var filterEl = document.getElementById('partner-caste-filter');
                if (!wrap || !inner || !placeholder) {
                    return;
                }

                document.querySelectorAll('input.partner-caste-cb').forEach(function (cb) {
                    var id = parseInt(cb.value, 10);
                    if (cb.checked) {
                        selectedCasteState[id] = true;
                    } else {
                        delete selectedCasteState[id];
                    }
                });

                if (relIds.length === 0) {
                    wrap.classList.add('hidden');
                    inner.innerHTML = '';
                    if (filterEl) {
                        filterEl.classList.add('hidden');
                        filterEl.value = '';
                    }
                    placeholder.textContent = @json(__('wizard.select_religion_first'));
                    placeholder.classList.remove('hidden');
                    return;
                }

                placeholder.classList.add('hidden');
                wrap.classList.remove('hidden');
                if (filterEl) {
                    filterEl.classList.remove('hidden');
                }

                var seen = {};
                var items = [];
                relIds.forEach(function (rid) {
                    var list = byRel[String(rid)] || [];
                    list.forEach(function (item) {
                        if (seen[item.id]) {
                            return;
                        }
                        seen[item.id] = true;
                        items.push({ id: item.id, label: item.label, orphan: false });
                    });
                });

                Object.keys(selectedCasteState).forEach(function (k) {
                    var cid = parseInt(k, 10);
                    if (!selectedCasteState[cid]) {
                        return;
                    }
                    if (seen[cid]) {
                        return;
                    }
                    var meta = byId[String(cid)] || byId[cid];
                    if (meta) {
                        items.push({ id: meta.id, label: meta.label, orphan: true });
                        seen[cid] = true;
                    }
                });

                items.sort(function (a, b) {
                    return a.label.localeCompare(b.label);
                });

                var html = [];
                items.forEach(function (item) {
                    var checked = selectedCasteState[item.id] ? ' checked' : '';
                    var orphanClass = item.orphan ? ' border-amber-400 dark:border-amber-600 bg-amber-50/80 dark:bg-amber-900/20' : '';
                    var title = item.orphan ? orphanHint : '';
                    html.push(
                        '<label class="partner-caste-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer' + orphanClass + '" data-caste-label="' + String(item.label).replace(/"/g, '&quot;') + '"' + (title ? ' title="' + String(title).replace(/"/g, '&quot;') + '"' : '') + '>' +
                        '<input type="checkbox" name="preferred_caste_ids[]" value="' + item.id + '" class="partner-caste-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"' + checked + '>' +
                        '<span class="text-gray-800 dark:text-gray-100">' + String(item.label).replace(/</g, '&lt;') + '</span></label>'
                    );
                });
                inner.innerHTML = html.join('');

                inner.querySelectorAll('input.partner-caste-cb').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var id = parseInt(cb.value, 10);
                        if (cb.checked) {
                            selectedCasteState[id] = true;
                        } else {
                            delete selectedCasteState[id];
                        }
                    });
                });

                applyCasteFilter();
            }

            function applyCasteFilter() {
                var q = (document.getElementById('partner-caste-filter') || {}).value || '';
                q = q.trim().toLowerCase();
                document.querySelectorAll('.partner-caste-chip').forEach(function (el) {
                    var lab = (el.getAttribute('data-caste-label') || el.textContent || '').toLowerCase();
                    el.style.display = !q || lab.indexOf(q) !== -1 ? '' : 'none';
                });
            }

            document.querySelectorAll('input.partner-religion-cb').forEach(function (cb) {
                cb.addEventListener('change', renderCasteChips);
            });
            var filterIn = document.getElementById('partner-caste-filter');
            if (filterIn) {
                filterIn.addEventListener('input', applyCasteFilter);
            }

            window.__partnerCommunityRefreshCastes = renderCasteChips;
            window.__partnerCommunityApplyCasteIds = function (ids) {
                selectedCasteState = {};
                (ids || []).forEach(function (id) {
                    selectedCasteState[parseInt(id, 10)] = true;
                });
                renderCasteChips();
            };

            document.addEventListener('DOMContentLoaded', renderCasteChips);
        })();
    </script>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 p-4 space-y-4 mt-6 {{ $activePref !== 'lifestyle' ? 'hidden' : '' }}">
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('wizard.partner_pref_lifestyle_microcopy') }}</p>
        @include('matrimony.profile.wizard.partials.partner_pref_lifestyle_diet')
    </div>
    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 p-4 space-y-4 mt-6 {{ $activePref !== 'family' ? 'hidden' : '' }}">
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('wizard.partner_pref_family_microcopy') }}</p>
        @include('matrimony.profile.wizard.partials.partner_pref_family_managed')
    </div>

    @php
        $prefOrder = ['basics', 'community', 'location', 'education', 'lifestyle', 'family'];
        $pi = array_search($activePref, $prefOrder, true);
        $prevPref = $pi !== false && $pi > 0 ? $prefOrder[$pi - 1] : null;
        $nextPref = $pi !== false && $pi < count($prefOrder) - 1 ? $prefOrder[$pi + 1] : null;
    @endphp
    <div class="flex flex-wrap items-center justify-between gap-3 pt-6 mt-4 border-t border-gray-200 dark:border-gray-700">
        <div>
            @if($prevPref)
                <a href="{{ route('matrimony.profile.wizard.section', array_merge(['section' => 'about-preferences'], ['pref' => $prevPref])) }}" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('wizard.partner_pref_prev_section') }}</a>
            @endif
        </div>
        <div>
            @if($nextPref)
                <a href="{{ route('matrimony.profile.wizard.section', array_merge(['section' => 'about-preferences'], ['pref' => $nextPref])) }}" class="inline-flex items-center px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium">{{ __('wizard.partner_pref_next_section') }}</a>
            @endif
        </div>
    </div>
</div>
