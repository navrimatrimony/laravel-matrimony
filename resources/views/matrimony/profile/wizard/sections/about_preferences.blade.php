{{-- Phase-5B: Partner preferences — criteria + pivot-based engine. About Me is a separate tab. --}}
@php
    $criteria = $preferenceCriteria ?? null;
    $oldCriteria = [
        'preferred_age_min' => old('preferred_age_min', $criteria->preferred_age_min ?? null),
        'preferred_age_max' => old('preferred_age_max', $criteria->preferred_age_max ?? null),
        'preferred_income_min' => old('preferred_income_min', $criteria->preferred_income_min ?? null),
        'preferred_income_max' => old('preferred_income_max', $criteria->preferred_income_max ?? null),
        'preferred_education' => old('preferred_education', $criteria->preferred_education ?? ''),
        'preferred_city_id' => old('preferred_city_id', $criteria->preferred_city_id ?? null),
    ];
    $selectedReligionIds = collect(old('preferred_religion_ids', $preferredReligionIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedCasteIds = collect(old('preferred_caste_ids', $preferredCasteIds ?? []))->map(fn($id) => (int) $id)->all();
    $selectedDistrictIds = collect(old('preferred_district_ids', $preferredDistrictIds ?? []))->map(fn($id) => (int) $id)->all();
@endphp

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2 flex-1">Partner preferences</h2>
        <div class="ml-4 flex items-center gap-2 text-xs">
            <span class="text-gray-500 dark:text-gray-400">Preset:</span>
            @php
                $currentPreset = old('preference_preset', $preferencePreset ?? 'balanced');
            @endphp
            <select id="preference_preset" name="preference_preset" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-xs px-2 py-1">
                <option value="custom" {{ $currentPreset === 'custom' ? 'selected' : '' }}>Custom</option>
                <option value="traditional" {{ $currentPreset === 'traditional' ? 'selected' : '' }}>Traditional</option>
                <option value="balanced" {{ $currentPreset === 'balanced' ? 'selected' : '' }}>Balanced</option>
                <option value="broad" {{ $currentPreset === 'broad' ? 'selected' : '' }}>Broad</option>
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
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Preferred cities</label>
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
                            placeholder="Preferred city"
                            label=""
                            :data-city-id="$cityId ?? ''"
                        />
                        <div class="flex justify-between items-center">
                            <div class="preferred-city-add-wrap">
                                <span role="button" tabindex="0" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 cursor-pointer font-medium text-xs" data-repeater-add data-repeater-for="{{ $preferredCitiesContainerId }}">
                                    <span aria-hidden="true">+</span> Add city
                                </span>
                            </div>
                            <button type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline" data-repeater-remove>Remove this city</button>
                        </div>
                    </div>
                @empty
                    <div class="preferred-city-row p-2 bg-gray-50 dark:bg-gray-800/60 rounded space-y-2">
                        <input type="hidden" name="preferred_cities[0][city_id]" value="">
                        <x-profile.location-typeahead
                            context="alliance"
                            :namePrefix="'preferred_cities[0]'"
                            :value="''"
                            placeholder="Preferred city"
                            label=""
                            :data-city-id="''"
                        />
                        <div class="flex justify-between items-center">
                            <div class="preferred-city-add-wrap">
                                <span role="button" tabindex="0" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 cursor-pointer font-medium text-xs" data-repeater-add data-repeater-for="{{ $preferredCitiesContainerId }}">
                                    <span aria-hidden="true">+</span> Add city
                                </span>
                            </div>
                            <button type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline" data-repeater-remove>Remove this city</button>
                        </div>
                    </div>
                @endforelse
            </div>
            <style>
            .preferred-city-row:not(:last-child) .preferred-city-add-wrap { display: none; }
            </style>
        </div>
        <div class="space-y-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Preferred districts</label>
            <div id="{{ $preferredDistrictContainerId }}" class="space-y-2" data-repeater-container data-name-prefix="preferred_districts" data-row-class="preferred-district-row" data-min-rows="1">
                @foreach($preferredDistrictRows as $idx => $row)
                    @php $districtId = $row['district_id'] ?? null; @endphp
                    <div class="preferred-district-row p-2 bg-gray-50 dark:bg-gray-800/60 rounded space-y-2">
                        <select name="preferred_district_ids[]" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1 text-sm h-[42px]">
                            <option value="">Select district</option>
                            @foreach(($allDistricts ?? collect()) as $district)
                                <option value="{{ $district->id }}" @if($districtId === $district->id) selected @endif>{{ $district->name }}</option>
                            @endforeach
                        </select>
                        <div class="flex justify-between items-center">
                            <div class="preferred-district-add-wrap">
                                <span role="button" tabindex="0" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 cursor-pointer font-medium text-xs" data-repeater-add data-repeater-for="{{ $preferredDistrictContainerId }}">
                                    <span aria-hidden="true">+</span> Add district
                                </span>
                            </div>
                            <button type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline" data-repeater-remove>Remove this district</button>
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

                    var ageMin = document.querySelector('input[name="preferred_age_min"]');
                    var ageMax = document.querySelector('input[name="preferred_age_max"]');
                    var incomeMin = document.querySelector('input[name="preferred_income_min"]');
                    var incomeMax = document.querySelector('input[name="preferred_income_max"]');
                    var education = document.querySelector('input[name="preferred_education"]');
                    var religionsSelect = document.querySelector('select[name="preferred_religion_ids[]"]');
                    var castesSelect = document.querySelector('select[name="preferred_caste_ids[]"]');
                    var districtsSelect = document.querySelector('select[name="preferred_district_ids[]"]');
                    var cityHidden = document.querySelector('input[name="preferred_cities[0][city_id]"]');
                    var cityInput = document.querySelector('#{{ $preferredCitiesContainerId }} .preferred-city-row .location-typeahead-input');

                    if (ageMin && preset.preferred_age_min !== undefined && preset.preferred_age_min !== null) {
                        ageMin.value = preset.preferred_age_min;
                    }
                    if (ageMax && preset.preferred_age_max !== undefined && preset.preferred_age_max !== null) {
                        ageMax.value = preset.preferred_age_max;
                    }
                    if (incomeMin && preset.preferred_income_min !== undefined && preset.preferred_income_min !== null) {
                        incomeMin.value = preset.preferred_income_min;
                    }
                    if (incomeMax && preset.preferred_income_max !== undefined && preset.preferred_income_max !== null) {
                        incomeMax.value = preset.preferred_income_max;
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
        <div class="flex gap-2">
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Age min</label>
                <input type="number" name="preferred_age_min" value="{{ $oldCriteria['preferred_age_min'] }}" placeholder="Min" class="w-full rounded border px-3 py-2">
            </div>
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Age max</label>
                <input type="number" name="preferred_age_max" value="{{ $oldCriteria['preferred_age_max'] }}" placeholder="Max" class="w-full rounded border px-3 py-2">
            </div>
        </div>
        <div class="flex gap-2">
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Income min</label>
                <input type="number" step="0.01" name="preferred_income_min" value="{{ $oldCriteria['preferred_income_min'] }}" placeholder="Min" class="w-full rounded border px-3 py-2">
            </div>
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Income max</label>
                <input type="number" step="0.01" name="preferred_income_max" value="{{ $oldCriteria['preferred_income_max'] }}" placeholder="Max" class="w-full rounded border px-3 py-2">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Preferred education</label>
            <input type="text" name="preferred_education" value="{{ $oldCriteria['preferred_education'] }}" placeholder="e.g. BE, MBBS, M.Tech" class="w-full rounded border px-3 py-2">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Preferred religions</label>
            <select name="preferred_religion_ids[]" multiple class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1 text-sm">
                @foreach(($allReligions ?? collect()) as $religion)
                    <option value="{{ $religion->id }}" @if(in_array($religion->id, $selectedReligionIds, true)) selected @endif>{{ $religion->label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Preferred caste</label>
            <select name="preferred_caste_ids[]" multiple class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1 text-sm">
                @foreach(($allCastes ?? collect()) as $caste)
                    <option value="{{ $caste->id }}" @if(in_array($caste->id, $selectedCasteIds, true)) selected @endif>{{ $caste->label }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">Hold Ctrl (Cmd on Mac) to select multiple castes.</p>
            <label class="mt-2 inline-flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300">
                <input type="checkbox" name="preferred_intercaste" value="1" class="rounded border-gray-300 dark:border-gray-600"
                    {{ old('preferred_intercaste') ? 'checked' : '' }}>
                <span>Open to intercaste matches</span>
            </label>
        </div>
        <div></div>
    </div>
</div>
