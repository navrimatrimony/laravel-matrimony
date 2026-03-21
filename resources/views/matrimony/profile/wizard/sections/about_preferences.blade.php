{{-- Phase-5B: Partner preferences — criteria + pivot-based engine. About Me is a separate tab. --}}
@php
    $criteria = $preferenceCriteria ?? null;
    $oldCriteria = [
        'preferred_age_min' => old('preferred_age_min', $criteria?->preferred_age_min ?? null),
        'preferred_age_max' => old('preferred_age_max', $criteria?->preferred_age_max ?? null),
        'preferred_income_min' => old('preferred_income_min', $criteria?->preferred_income_min ?? null),
        'preferred_income_max' => old('preferred_income_max', $criteria?->preferred_income_max ?? null),
        'preferred_education' => old('preferred_education', $criteria?->preferred_education ?? ''),
        'preferred_city_id' => old('preferred_city_id', $criteria?->preferred_city_id ?? null),
        'willing_to_relocate' => old('willing_to_relocate', $criteria?->willing_to_relocate ?? null),
        'settled_city_preference_id' => old('settled_city_preference_id', $criteria?->settled_city_preference_id ?? null),
        'marriage_type_preference_id' => old('marriage_type_preference_id', $criteria?->marriage_type_preference_id ?? null),
    ];
    $settledCityDisplay = '';
    if (!empty($oldCriteria['settled_city_preference_id'])) {
        $settledCityDisplay = \App\Models\City::where('id', $oldCriteria['settled_city_preference_id'])->value('name') ?? '';
    }
    $selectedReligionIds = collect(old('preferred_religion_ids', $preferredReligionIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedCasteIds = collect(old('preferred_caste_ids', $preferredCasteIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedDistrictIds = collect(old('preferred_district_ids', $preferredDistrictIds ?? []))->map(fn($id) => (int) $id)->all();
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
@endphp

<div class="space-y-6">
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

    @php
        $preferredCities = old('preferred_cities', []);
        if (!is_array($preferredCities)) { $preferredCities = []; }
        if (count($preferredCities) === 0 && $oldCriteria['preferred_city_id']) {
            $preferredCities = [['city_id' => $oldCriteria['preferred_city_id']]];
        }
        $preferredCitiesContainerId = 'preferred-cities-container';

        $preferredDistrictContainerId = 'preferred-districts-container';
        $preferredDistrictRows = [];
        if (!empty($selectedDistrictIds)) {
            foreach ($selectedDistrictIds as $i => $did) {
                $preferredDistrictRows[] = ['district_id' => $did];
            }
        }
        if (count($preferredDistrictRows) === 0) {
            $preferredDistrictRows = [['district_id' => null]];
        }
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="space-y-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.preferred_cities') }}</label>
            <div id="{{ $preferredCitiesContainerId }}" class="space-y-2" data-repeater-container data-name-prefix="preferred_cities" data-row-class="preferred-city-row" data-min-rows="1">
                @forelse($preferredCities as $idx => $cityRow)
                    @php
                        $row = is_array($cityRow) ? $cityRow : (array) $cityRow;
                        $cityId = $row['city_id'] ?? $row['preferred_city_id'] ?? null;
                        $cityName = $cityId ? (\App\Models\City::where('id', $cityId)->value('name') ?? '') : '';
                    @endphp
                    <div class="preferred-city-row p-2 bg-gray-50 dark:bg-gray-800/60 rounded space-y-2">
                        <input type="hidden" name="preferred_cities[{{ $idx }}][city_id]" value="{{ $cityId }}">
                        <x-profile.location-typeahead
                            context="alliance"
                            :namePrefix="'preferred_cities['.$idx.']'"
                            :value="$cityName"
                            placeholder="{{ __('wizard.preferred_city_placeholder') }}"
                            label=""
                            :data-city-id="$cityId ?? ''"
                        />
                        <div class="flex justify-between items-center">
                            <div class="preferred-city-add-wrap">
                                <span role="button" tabindex="0" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 cursor-pointer font-medium text-xs" data-repeater-add data-repeater-for="{{ $preferredCitiesContainerId }}">
                                    <span aria-hidden="true">+</span> {{ __('wizard.add_city') }}
                                </span>
                            </div>
                            <button type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline" data-repeater-remove>{{ __('wizard.remove_city') }}</button>
                        </div>
                    </div>
                @empty
                    <div class="preferred-city-row p-2 bg-gray-50 dark:bg-gray-800/60 rounded space-y-2">
                        <input type="hidden" name="preferred_cities[0][city_id]" value="">
                        <x-profile.location-typeahead
                            context="alliance"
                            :namePrefix="'preferred_cities[0]'"
                            :value="''"
                            placeholder="{{ __('wizard.preferred_city_placeholder') }}"
                            label=""
                            :data-city-id="''"
                        />
                        <div class="flex justify-between items-center">
                            <div class="preferred-city-add-wrap">
                                <span role="button" tabindex="0" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 cursor-pointer font-medium text-xs" data-repeater-add data-repeater-for="{{ $preferredCitiesContainerId }}">
                                    <span aria-hidden="true">+</span> {{ __('wizard.add_city') }}
                                </span>
                            </div>
                            <button type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline" data-repeater-remove>{{ __('wizard.remove_city') }}</button>
                        </div>
                    </div>
                @endforelse
            </div>
            <style>
            .preferred-city-row:not(:last-child) .preferred-city-add-wrap { display: none; }
            </style>
        </div>
        <div class="space-y-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.preferred_districts') }}</label>
            <div id="{{ $preferredDistrictContainerId }}" class="space-y-2" data-repeater-container data-name-prefix="preferred_districts" data-row-class="preferred-district-row" data-min-rows="1">
                @foreach($preferredDistrictRows as $idx => $row)
                    @php $districtId = $row['district_id'] ?? null; @endphp
                    <div class="preferred-district-row p-2 bg-gray-50 dark:bg-gray-800/60 rounded space-y-2">
                        <select name="preferred_district_ids[]" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1 text-sm h-[42px]">
                            <option value="">{{ __('wizard.select_district') }}</option>
                            @foreach(($allDistricts ?? collect()) as $district)
                                <option value="{{ $district->id }}" @if($districtId === $district->id) selected @endif>{{ $district->name }}</option>
                            @endforeach
                        </select>
                        <div class="flex justify-between items-center">
                            <div class="preferred-district-add-wrap">
                                <span role="button" tabindex="0" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 cursor-pointer font-medium text-xs" data-repeater-add data-repeater-for="{{ $preferredDistrictContainerId }}">
                                    <span aria-hidden="true">+</span> {{ __('wizard.add_district') }}
                                </span>
                            </div>
                            <button type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline" data-repeater-remove>{{ __('wizard.remove_district') }}</button>
                        </div>
                    </div>
                @endforeach
            </div>
            <style>
            .preferred-district-row:not(:last-child) .preferred-district-add-wrap { display: none; }
            </style>
        </div>
    </div>

    @if(!empty($preferencePresetDefaults ?? []))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var presets = @json($preferencePresetDefaults);
                var selectEl = document.getElementById('preference_preset');
                if (!selectEl || !presets) {
                    return;
                }

                function applyPreset(presetKey) {
                    var preset = presets[presetKey];
                    if (!preset) {
                        return;
                    }

                    var ageMin = document.getElementById('partner-age-min-hidden');
                    var ageMax = document.getElementById('partner-age-max-hidden');
                    var incomeMin = document.querySelector('input[name="preferred_income_min"]');
                    var incomeMax = document.querySelector('input[name="preferred_income_max"]');
                    var education = document.querySelector('input[name="preferred_education"]');
                    var religionsSelect = document.querySelector('select[name="preferred_religion_ids[]"]');
                    var castesSelect = document.querySelector('select[name="preferred_caste_ids[]"]');
                    var districtsSelect = document.querySelector('select[name="preferred_district_ids[]"]');
                    var cityHidden = document.querySelector('input[name="preferred_cities[0][city_id]"]');
                    var cityInput = document.querySelector('#{{ $preferredCitiesContainerId }} .preferred-city-row .location-typeahead-input');

                    if (typeof window.__setPartnerAgeRange === 'function'
                        && preset.preferred_age_min !== undefined && preset.preferred_age_min !== null
                        && preset.preferred_age_max !== undefined && preset.preferred_age_max !== null) {
                        window.__setPartnerAgeRange(preset.preferred_age_min, preset.preferred_age_max);
                    } else {
                        if (ageMin && preset.preferred_age_min !== undefined && preset.preferred_age_min !== null) {
                            ageMin.value = preset.preferred_age_min;
                        }
                        if (ageMax && preset.preferred_age_max !== undefined && preset.preferred_age_max !== null) {
                            ageMax.value = preset.preferred_age_max;
                        }
                    }
                    if (typeof window.__setPartnerIncomeRange === 'function'
                        && preset.preferred_income_min !== undefined && preset.preferred_income_min !== null) {
                        window.__setPartnerIncomeRange(
                            preset.preferred_income_min,
                            preset.preferred_income_max !== undefined && preset.preferred_income_max !== null
                                ? preset.preferred_income_max
                                : null
                        );
                    } else {
                        if (incomeMin && preset.preferred_income_min !== undefined && preset.preferred_income_min !== null) {
                            incomeMin.value = preset.preferred_income_min;
                        }
                        if (incomeMax && preset.preferred_income_max !== undefined && preset.preferred_income_max !== null) {
                            incomeMax.value = preset.preferred_income_max;
                        }
                    }
                    if (education && preset.preferred_education !== undefined && preset.preferred_education !== null) {
                        education.value = preset.preferred_education;
                    }
                    if (religionsSelect && Array.isArray(preset.preferred_religion_ids)) {
                        var relIds = preset.preferred_religion_ids.map(function (v) { return parseInt(v, 10); });
                        Array.prototype.forEach.call(religionsSelect.options, function (opt) {
                            var val = parseInt(opt.value || '0', 10);
                            opt.selected = relIds.indexOf(val) !== -1;
                        });
                    }
                    if (castesSelect && Array.isArray(preset.preferred_caste_ids)) {
                        var casteIds = preset.preferred_caste_ids.map(function (v) { return parseInt(v, 10); });
                        Array.prototype.forEach.call(castesSelect.options, function (opt) {
                            var val = parseInt(opt.value || '0', 10);
                            opt.selected = casteIds.indexOf(val) !== -1;
                        });
                    }
                    if (districtsSelect && Array.isArray(preset.preferred_district_ids) && preset.preferred_district_ids.length > 0) {
                        districtsSelect.value = String(preset.preferred_district_ids[0]);
                    }
                    if (cityHidden && preset.preferred_city_id) {
                        cityHidden.value = preset.preferred_city_id;
                    }
                    if (cityInput && preset.preferred_city_name) {
                        cityInput.value = preset.preferred_city_name;
                    }
                }

                selectEl.addEventListener('change', function () {
                    var value = this.value;
                    if (value === 'custom') {
                        return;
                    }
                    applyPreset(value);
                });
            });
        </script>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="flex items-center gap-2">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="willing_to_relocate" value="1" class="rounded border-gray-300 dark:border-gray-600"
                    {{ $oldCriteria['willing_to_relocate'] ? 'checked' : '' }}>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('wizard.willing_to_relocate') }}</span>
            </label>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.settled_city_preference') }}</label>
            <x-profile.location-typeahead
                context="alliance"
                namePrefix="settled_preference"
                :value="$settledCityDisplay"
                :placeholder="__('wizard.city_to_settle_in')"
                label=""
                :data-city-id="$oldCriteria['settled_city_preference_id'] ?? ''"
            />
        </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
        @php
            $optionLabel = function ($row, string $field) {
                $key = $row->key ?? null;
                $dbLabel = $row->label ?? '';
                if ($key) {
                    $tKey = 'components.options.' . $field . '.' . $key;
                    $t = __($tKey);
                    if ($t !== $tKey) return $t;
                }
                return $dbLabel;
            };
        @endphp
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.marriage_type_preference') }}</label>
            <select name="marriage_type_preference_id" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-3 py-2">
                <option value="">{{ __('common.select_placeholder') }}</option>
                @foreach($marriageTypePreferences ?? [] as $mtp)
                    <option value="{{ $mtp->id }}" {{ (string)($oldCriteria['marriage_type_preference_id'] ?? '') === (string)$mtp->id ? 'selected' : '' }}>{{ $optionLabel($mtp, 'marriage_type_preference') }}</option>
                @endforeach
            </select>
        </div>
        <div></div>
    </div>
    <div class="space-y-4">
        <style>
            .partner-pref-dual-range { pointer-events: none; }
            .partner-pref-dual-range::-webkit-slider-thumb { pointer-events: auto; -webkit-appearance: none; appearance: none; height: 1.125rem; width: 1.125rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.15); cursor: grab; margin-top: -5px; }
            .partner-pref-dual-range::-moz-range-thumb { pointer-events: auto; height: 1.125rem; width: 1.125rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.15); cursor: grab; }
            .partner-pref-dual-range::-webkit-slider-runnable-track { -webkit-appearance: none; appearance: none; height: 8px; background: transparent; }
            .partner-pref-dual-range::-moz-range-track { height: 8px; background: transparent; }
        </style>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-stretch">
            <div class="rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 p-4 space-y-2 shadow-sm min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('wizard.preferred_age_range') }}</label>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.preferred_age_range_hint') }}</p>
            <p id="partner-age-range-label" class="text-base font-semibold text-indigo-700 dark:text-indigo-300 tabular-nums" aria-live="polite">{{ $ageMinInit }} – {{ $ageMaxInit }} {{ __('wizard.years') }}</p>
            <div class="partner-age-slider relative h-10 px-0.5" data-age-absolute-min="18" data-age-absolute-max="80">
                <div class="absolute left-0 right-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-gray-200 dark:bg-gray-600 pointer-events-none"></div>
                <div id="partner-age-range-fill" class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-indigo-500 pointer-events-none" style="left: 0%; width: 0%"></div>
                <input type="range" id="partner-age-range-min" class="partner-pref-dual-range absolute inset-x-0 top-0 z-[2] h-10 w-full cursor-pointer appearance-none bg-transparent" min="18" max="80" step="1" value="{{ $ageMinInit }}" aria-label="{{ __('wizard.age_min') }}">
                <input type="range" id="partner-age-range-max" class="partner-pref-dual-range absolute inset-x-0 top-0 z-[3] h-10 w-full cursor-pointer appearance-none bg-transparent" min="18" max="80" step="1" value="{{ $ageMaxInit }}" aria-label="{{ __('wizard.age_max') }}">
            </div>
            <input type="hidden" name="preferred_age_min" id="partner-age-min-hidden" value="{{ $ageMinInit }}">
            <input type="hidden" name="preferred_age_max" id="partner-age-max-hidden" value="{{ $ageMaxInit }}">
            <script>
                (function () {
                    var ABS_MIN = 18;
                    var ABS_MAX = 80;
                    var minR = document.getElementById('partner-age-range-min');
                    var maxR = document.getElementById('partner-age-range-max');
                    var minH = document.getElementById('partner-age-min-hidden');
                    var maxH = document.getElementById('partner-age-max-hidden');
                    var fill = document.getElementById('partner-age-range-fill');
                    var label = document.getElementById('partner-age-range-label');
                    var yearsWord = @json(__('wizard.years'));
                    if (!minR || !maxR || !minH || !maxH || !fill || !label) return;

                    function clamp(n) {
                        n = parseInt(n, 10);
                        if (isNaN(n)) return ABS_MIN;
                        return Math.max(ABS_MIN, Math.min(ABS_MAX, n));
                    }

                    function syncZ() {
                        var mn = parseInt(minR.value, 10);
                        var mx = parseInt(maxR.value, 10);
                        minR.style.zIndex = mn > ABS_MAX - 5 ? '4' : '2';
                        maxR.style.zIndex = mx < ABS_MIN + 5 ? '4' : '3';
                    }

                    function paint() {
                        var mn = clamp(minR.value);
                        var mx = clamp(maxR.value);
                        if (mn > mx) {
                            var t = mn;
                            mn = mx;
                            mx = t;
                            minR.value = String(mn);
                            maxR.value = String(mx);
                        }
                        minH.value = String(mn);
                        maxH.value = String(mx);
                        var p1 = ((mn - ABS_MIN) / (ABS_MAX - ABS_MIN)) * 100;
                        var p2 = ((mx - ABS_MIN) / (ABS_MAX - ABS_MIN)) * 100;
                        fill.style.left = p1 + '%';
                        fill.style.width = Math.max(0, p2 - p1) + '%';
                        label.textContent = mn + ' – ' + mx + ' ' + yearsWord;
                        syncZ();
                    }

                    minR.addEventListener('input', function () {
                        var mn = clamp(minR.value);
                        var mx = clamp(maxR.value);
                        if (mn > mx) maxR.value = String(mn);
                        paint();
                    });
                    maxR.addEventListener('input', function () {
                        var mn = clamp(minR.value);
                        var mx = clamp(maxR.value);
                        if (mx < mn) minR.value = String(mx);
                        paint();
                    });

                    window.__setPartnerAgeRange = function (mn, mx) {
                        mn = clamp(mn);
                        mx = clamp(mx);
                        if (mn > mx) {
                            var s = mn;
                            mn = mx;
                            mx = s;
                        }
                        minR.value = String(mn);
                        maxR.value = String(mx);
                        paint();
                    };

                    paint();
                })();
            </script>
            </div>
            <div class="rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 p-4 space-y-2 shadow-sm min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('wizard.preferred_income_range') }}</label>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.preferred_income_range_hint') }}</p>
            <p id="partner-income-range-label" class="text-base font-semibold text-indigo-700 dark:text-indigo-300 tabular-nums" aria-live="polite">{{ $incomeFmtLakh($incMinLakhs) }} – {{ $incomeFmtLakh($incMaxLakhs) }}</p>
            <div class="partner-income-slider relative h-10 px-0.5">
                <div class="absolute left-0 right-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-gray-200 dark:bg-gray-600 pointer-events-none"></div>
                <div id="partner-income-range-fill" class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-indigo-500 pointer-events-none" style="left: 0%; width: 0%"></div>
                <input type="range" id="partner-income-range-min" class="partner-pref-dual-range absolute inset-x-0 top-0 z-[2] h-10 w-full cursor-pointer appearance-none bg-transparent" min="0" max="500" step="1" value="{{ $incMinLakhs }}" aria-label="{{ __('wizard.income_min') }}">
                <input type="range" id="partner-income-range-max" class="partner-pref-dual-range absolute inset-x-0 top-0 z-[3] h-10 w-full cursor-pointer appearance-none bg-transparent" min="0" max="500" step="1" value="{{ $incMaxLakhs }}" aria-label="{{ __('wizard.income_max') }}">
            </div>
            <input type="hidden" name="preferred_income_min" id="partner-income-min-hidden" value="{{ $incMinLakhs * 100000 }}">
            <input type="hidden" name="preferred_income_max" id="partner-income-max-hidden" value="{{ $incMaxLakhs * 100000 }}">
            <script>
                (function () {
                    var ABS_MIN = 0;
                    var ABS_MAX = 500;
                    var LAKH = 100000;
                    var minR = document.getElementById('partner-income-range-min');
                    var maxR = document.getElementById('partner-income-range-max');
                    var minH = document.getElementById('partner-income-min-hidden');
                    var maxH = document.getElementById('partner-income-max-hidden');
                    var fill = document.getElementById('partner-income-range-fill');
                    var label = document.getElementById('partner-income-range-label');
                    var lakhFmt = @json(__('wizard.income_lakh_format'));
                    var lakhZero = @json(__('wizard.income_lakh_zero'));
                    if (!minR || !maxR || !minH || !maxH || !fill || !label) return;

                    function fmtLakhs(l) {
                        l = parseInt(l, 10);
                        if (isNaN(l)) l = 0;
                        if (l === 0) return lakhZero;
                        return String(lakhFmt).split(':num').join(String(l));
                    }

                    function clamp(n) {
                        n = parseInt(n, 10);
                        if (isNaN(n)) return ABS_MIN;
                        return Math.max(ABS_MIN, Math.min(ABS_MAX, n));
                    }

                    function syncZ() {
                        var mn = parseInt(minR.value, 10);
                        var mx = parseInt(maxR.value, 10);
                        minR.style.zIndex = mn > ABS_MAX - 25 ? '4' : '2';
                        maxR.style.zIndex = mx < ABS_MIN + 25 ? '4' : '3';
                    }

                    function paint() {
                        var mn = clamp(minR.value);
                        var mx = clamp(maxR.value);
                        if (mn > mx) {
                            var t = mn;
                            mn = mx;
                            mx = t;
                            minR.value = String(mn);
                            maxR.value = String(mx);
                        }
                        minH.value = String(mn * LAKH);
                        maxH.value = String(mx * LAKH);
                        var p1 = ABS_MAX > ABS_MIN ? ((mn - ABS_MIN) / (ABS_MAX - ABS_MIN)) * 100 : 0;
                        var p2 = ABS_MAX > ABS_MIN ? ((mx - ABS_MIN) / (ABS_MAX - ABS_MIN)) * 100 : 0;
                        fill.style.left = p1 + '%';
                        fill.style.width = Math.max(0, p2 - p1) + '%';
                        label.textContent = fmtLakhs(mn) + ' – ' + fmtLakhs(mx);
                        syncZ();
                    }

                    minR.addEventListener('input', function () {
                        var mn = clamp(minR.value);
                        var mx = clamp(maxR.value);
                        if (mn > mx) maxR.value = String(mn);
                        paint();
                    });
                    maxR.addEventListener('input', function () {
                        var mn = clamp(minR.value);
                        var mx = clamp(maxR.value);
                        if (mx < mn) minR.value = String(mx);
                        paint();
                    });

                    window.__setPartnerIncomeRange = function (minRupees, maxRupees) {
                        var mn = Math.round((parseFloat(minRupees) || 0) / LAKH);
                        var mx = maxRupees != null && maxRupees !== ''
                            ? Math.round((parseFloat(maxRupees) || 0) / LAKH)
                            : Math.min(ABS_MAX, mn + 20);
                        mn = clamp(mn);
                        mx = clamp(mx);
                        if (mn > mx) {
                            var s = mn;
                            mn = mx;
                            mx = s;
                        }
                        minR.value = String(mn);
                        maxR.value = String(mx);
                        paint();
                    };

                    paint();
                })();
            </script>
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.preferred_education') }}</label>
            <input type="text" name="preferred_education" value="{{ $oldCriteria['preferred_education'] }}" placeholder="{{ __('wizard.preferred_education_placeholder') }}" class="w-full rounded border px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.preferred_religions') }}</label>
            <select name="preferred_religion_ids[]" multiple class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1 text-sm">
                @foreach(($allReligions ?? collect()) as $religion)
                    <option value="{{ $religion->id }}" @if(in_array($religion->id, $selectedReligionIds, true)) selected @endif>{{ $optionLabel($religion, 'religion') }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.preferred_caste') }}</label>
            <select name="preferred_caste_ids[]" multiple class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1 text-sm">
                @foreach(($allCastes ?? collect()) as $caste)
                    <option value="{{ $caste->id }}" @if(in_array($caste->id, $selectedCasteIds, true)) selected @endif>{{ $optionLabel($caste, 'caste') }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('wizard.hold_ctrl_multiple') }}</p>
            <label class="mt-2 inline-flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300">
                <input type="checkbox" name="preferred_intercaste" value="1" class="rounded border-gray-300 dark:border-gray-600"
                    {{ old('preferred_intercaste') ? 'checked' : '' }}>
                <span>{{ __('wizard.open_to_intercaste') }}</span>
            </label>
        </div>
        <div></div>
    </div>
</div>
