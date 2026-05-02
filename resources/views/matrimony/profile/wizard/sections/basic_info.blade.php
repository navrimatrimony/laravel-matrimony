{{--
    Basic Information Engine (final). Used in wizard and intake preview.
    Fields: full_name, gender_id, date_of_birth, birth_time, birth_place, religion_id, caste_id, sub_caste_id, marital engine.
    When $namePrefix is set (e.g. 'snapshot[core]' for intake), all core field names are prefixed; marital_engine gets namePrefix 'snapshot'.
--}}
@php
    $corePrefix = $namePrefix ?? '';
    $nameFullName = $corePrefix ? $corePrefix . '[full_name]' : 'full_name';
    $nameGenderId = $corePrefix ? $corePrefix . '[gender_id]' : 'gender_id';
    $nameDob = $corePrefix ? $corePrefix . '[date_of_birth]' : 'date_of_birth';
    $nameBirthTime = $corePrefix ? $corePrefix . '[birth_time]' : 'birth_time';
    $nameBirthTimeHour = $corePrefix ? $corePrefix . '[birth_time_hour]' : 'birth_time_hour';
    $nameBirthTimeMinute = $corePrefix ? $corePrefix . '[birth_time_minute]' : 'birth_time_minute';
    $oldPrefix = $corePrefix ? str_replace('[', '.', str_replace(']', '', $corePrefix)) . '.' : '';
    $maritalNamePrefix = ($corePrefix === 'snapshot[core]') ? 'snapshot' : '';
    $isIntakePreview = ($corePrefix === 'snapshot[core]');
    $phNotFound = $placeholderNotFound ?? null;
    $phSelect = $placeholderSelectRequired ?? null;
    $isPlaceholder = function($v) use ($phNotFound, $phSelect) {
        return ($phNotFound !== null && $v === $phNotFound) || ($phSelect !== null && $v === $phSelect);
    };
@endphp
<div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-white dark:bg-gray-800 space-y-7" x-data="basicInfoForm()">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2 mb-6">{{ __('wizard.basic_information') }}</h2>

    {{-- 1. Full name + Gender (one horizontal line) — flex-row so both always on same line --}}
    @php
        $fnVal = old($oldPrefix.'full_name', $profile->full_name ?? '');
        $fnDisplay = ($isIntakePreview && $isPlaceholder($fnVal)) ? '' : $fnVal;
        $fnMissing = $isIntakePreview && $fnVal !== '' && $fnDisplay === '';
    @endphp
    <div class="flex flex-row flex-nowrap gap-2 items-end">
        <div class="flex-1 min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.full_name') }} <span class="text-red-500">*</span></label>
            <input type="text" name="{{ $nameFullName }}" value="{{ $fnDisplay }}" required
                @if($fnMissing) data-ocr-missing="1" data-placeholder-value="{{ e($phNotFound) }}" @endif
                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px] focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {{ $fnMissing ? 'ocr-field-missing' : '' }}">
            @error($oldPrefix.'full_name')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>
        <div class="shrink-0 w-36">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.gender') }} <span class="text-red-500">*</span></label>
            <input type="hidden" name="{{ $nameGenderId }}" :value="genderId">
            {{-- Safelist so Tailwind keeps these when Alpine toggles :class --}}
            <span class="hidden bg-blue-600 bg-pink-500" aria-hidden="true"></span>
            <div class="flex rounded-full border border-gray-300 dark:border-gray-600 overflow-hidden bg-gray-100 dark:bg-gray-700/60 min-h-[42px]">
                @php $gendersList = $genders ?? collect(); @endphp
                @foreach($gendersList as $g)
                    @php
                        $isFirst = $loop->first;
                        $isLast = $loop->last;
                        $selectedBg = $g->key === 'male' ? 'bg-blue-600' : 'bg-pink-500';
                    @endphp
                    @if(!$isFirst)<span class="w-px shrink-0 bg-gray-300 dark:bg-gray-500 self-stretch" aria-hidden="true"></span>@endif
                    <button type="button"
                        class="flex-1 py-2.5 px-3 cursor-pointer transition-all duration-200 select-none text-sm font-medium border-none outline-none focus:ring-0 {{ $isFirst ? 'rounded-l-full' : '' }} {{ $isLast ? 'rounded-r-full' : '' }} text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200"
                        :class="genderId == {{ $g->id }} ? '{{ $selectedBg }} !text-white' : ''"
                        @click="genderId = {{ $g->id }}">
                        {{ $g->key === 'male' ? __('wizard.male') : __('wizard.female') }}
                    </button>
                @endforeach
            </div>
            @error($oldPrefix.'gender_id')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- 2. Date of birth (narrower), Birth time, Birth place — DOB ~30% less width so other two don't crowd --}}
    <div class="grid grid-cols-1 md:grid-cols-[0.7fr_1fr_1fr] gap-2 items-start">
        <div class="min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.date_of_birth') }}</label>
            @php
                $yMin = now()->subYears(60)->format('Y');
                $yMax = now()->subYears(18)->format('Y');
                $dobRaw = $profile->date_of_birth ?? null;
                $dobValue = '';
                if ($dobRaw instanceof \Carbon\CarbonInterface) {
                    $dobValue = $dobRaw->format('Y-m-d');
                } elseif (is_string($dobRaw) && trim($dobRaw) !== '') {
                    if (!$isIntakePreview || !$isPlaceholder(trim((string)$dobRaw))) {
                        try {
                            $dobValue = \Carbon\Carbon::parse($dobRaw)->format('Y-m-d');
                        } catch (\Throwable $e) {
                            $dobValue = '';
                        }
                    }
                }
                $dobValue = old($oldPrefix.'date_of_birth', $dobValue);
                $dobMissing = $isIntakePreview && ($profile->date_of_birth ?? '') !== '' && $isPlaceholder((string)($profile->date_of_birth ?? ''));
                if ($dobMissing) { $dobValue = ''; }
            @endphp
            <input type="date" name="{{ $nameDob }}" value="{{ $dobValue }}"
                min="{{ $yMin }}-01-01" max="{{ $yMax }}-12-31"
                @if($dobMissing) data-ocr-missing="1" data-placeholder-value="{{ e($phNotFound) }}" @endif
                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px] focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {{ $dobMissing ? 'ocr-field-missing' : '' }}">
            @error($oldPrefix.'date_of_birth')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>
        <div class="min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.birth_time_optional') }}</label>
            @php
                $bt = old($oldPrefix.'birth_time', $profile->birth_time ?? '');
                $bt = $bt !== '' ? trim($bt) : '';
                $btHour = '';
                $btMin = '';
                $btAmPm = 'AM';
                if ($bt !== '') {
                    if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)?$/i', $bt, $m)) {
                        $h = (int) $m[1];
                        $btMin = str_pad((int) $m[2], 2, '0', STR_PAD_LEFT);
                        if (isset($m[3])) {
                            if (strtoupper($m[3]) === 'PM' && $h < 12) { $h += 12; }
                            if (strtoupper($m[3]) === 'AM' && $h === 12) { $h = 0; }
                        } else {
                            if ($h >= 12) { $btAmPm = 'PM'; $h = $h === 12 ? 12 : $h - 12; }
                            else { $btAmPm = 'AM'; $h = $h === 0 ? 12 : $h; }
                        }
                        $btHour = (string) $h;
                    } elseif (preg_match('/^(\d{1,2}):(\d{2})$/', $bt, $m)) {
                        $h24 = (int) $m[1];
                        $btMin = str_pad((int) $m[2], 2, '0', STR_PAD_LEFT);
                        $btAmPm = $h24 >= 12 ? 'PM' : 'AM';
                        $btHour = $h24 === 0 ? '12' : ($h24 > 12 ? (string)($h24 - 12) : (string)$h24);
                    }
                }
            @endphp
            <div class="flex flex-nowrap items-center gap-1 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 pl-1.5 pr-1.5 w-max max-w-full min-h-[42px] min-w-[220px]">
                <select name="{{ $nameBirthTimeHour }}" x-model="birthHour" class="rounded border border-gray-300 dark:border-gray-500 dark:bg-gray-700 dark:text-gray-100 text-sm w-20 h-[42px] text-center focus:ring-1 focus:ring-indigo-500">
                    <option value="">—</option>
                    @for($i = 1; $i <= 12; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                </select>
                <span class="text-gray-500 dark:text-gray-400 font-medium">:</span>
                <select name="{{ $nameBirthTimeMinute }}" x-model="birthMinute" class="rounded border border-gray-300 dark:border-gray-500 dark:bg-gray-700 dark:text-gray-100 text-sm w-20 h-[42px] text-center focus:ring-1 focus:ring-indigo-500">
                    <option value="">—</option>
                    @for($i = 0; $i <= 59; $i++)<option value="{{ str_pad($i, 2, '0', STR_PAD_LEFT) }}">{{ str_pad($i, 2, '0', STR_PAD_LEFT) }}</option>@endfor
                </select>
                <div class="flex rounded overflow-hidden border border-gray-300 dark:border-gray-500 shrink-0 h-[42px]">
                    <button type="button" @click="birthAmPm = 'AM'" :class="birthAmPm === 'AM' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300'" class="px-1.5 h-[42px] flex items-center text-sm font-medium">AM</button>
                    <button type="button" @click="birthAmPm = 'PM'" :class="birthAmPm === 'PM' ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300'" class="px-1.5 h-[42px] flex items-center text-sm font-medium">PM</button>
                </div>
                <input type="hidden" name="{{ $nameBirthTime }}" :value="birthTimeValue">
            </div>
            @error($oldPrefix.'birth_time')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>
        <div class="min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.birth_place_optional') }}</label>
            @if($isIntakePreview ?? false)
                {{-- Intake: submit birth_place text when user didn't select a city (typeahead display has no name). --}}
                <input type="hidden" name="{{ $corePrefix }}[birth_place]" id="intake_birth_place_text" value="{{ (empty($profile->birth_city_id) && !empty(trim($birthPlaceDisplay ?? ''))) ? e($birthPlaceDisplay) : '' }}">
            @endif
            <x-profile.location-typeahead
                context="birth"
                :namePrefix="$corePrefix"
                :value="old($oldPrefix.'wizard_birth_place_display', $birthPlaceDisplay ?? '')"
                :placeholder="__('wizard.type_city_area')"
                label=""
                :noBorder="true"
                :compactRow="true"
                :data-birth-city-id="old($oldPrefix.'birth_city_id', $profile->birth_city_id ?? '')"
                :data-birth-taluka-id="old($oldPrefix.'birth_taluka_id', $profile->birth_taluka_id ?? '')"
                :data-birth-district-id="old($oldPrefix.'birth_district_id', $profile->birth_district_id ?? '')"
                :data-birth-state-id="old($oldPrefix.'birth_state_id', $profile->birth_state_id ?? '')"
            />
        </div>
    </div>

    {{-- 4. Religion, Caste, Sub-caste --}}
    <div>
        <x-profile.religion-caste-selector
            :profile="$profile"
            :namePrefix="$corePrefix ? $corePrefix : ''"
            :placeholderNotFound="$phNotFound"
            :placeholderSelectRequired="$phSelect"
        />
    </div>

    {{-- 4b. Mother tongue + Current location (text + searchable) — single row with 3 fields --}}
    @php
        $nameMotherTongue = $corePrefix ? $corePrefix . '[mother_tongue_id]' : 'mother_tongue_id';
        $valMotherTongue = old($oldPrefix.'mother_tongue_id', $profile->mother_tongue_id ?? '');
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
        // Current location: detailed free-text + searchable city/town via typeahead.
        $nameAddressLine = $corePrefix ? $corePrefix . '[address_line]' : 'address_line';
        $addressLineVal = old($oldPrefix.'address_line', $profile->address_line ?? ($coreData['address_line'] ?? ''));
        $residenceDisplay = old($oldPrefix.'wizard_residence_display', $residencePlaceDisplay ?? \App\Models\MatrimonyProfile::residenceLocationDisplayLineFor($profile));
        $resLocationId = old($oldPrefix.'location_id', $profile->location_id ?? '');
        $resTalukaId = old($oldPrefix.'taluka_id', $profile->taluka_id ?? '');
        $resDistrictId = old($oldPrefix.'district_id', $profile->district_id ?? '');
        $resStateId = old($oldPrefix.'state_id', $profile->state_id ?? '');
        $resCountryId = old($oldPrefix.'country_id', $profile->country_id ?? '');
    @endphp
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-start">
        <div class="min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.mother_tongue') }}</label>
            <select name="{{ $nameMotherTongue }}" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px] focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <option value="">{{ __('common.select_placeholder') }}</option>
                @foreach($motherTongues ?? [] as $mt)
                    <option value="{{ $mt->id }}" {{ (string)$valMotherTongue === (string)$mt->id ? 'selected' : '' }}>{{ $optionLabel($mt, 'mother_tongue') }}</option>
                @endforeach
            </select>
            @error($oldPrefix.'mother_tongue_id')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>
        <div class="min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Current address line</label>
            <input type="text"
                   name="{{ $nameAddressLine }}"
                   value="{{ $addressLineVal }}"
                   class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px] focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="{{ __('components.parents.parents_address_line') }}">
        </div>
        <div class="min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Current city / village / district / state</label>
            <x-profile.location-typeahead
                context="residence"
                :namePrefix="$corePrefix"
                :value="$residenceDisplay"
                :placeholder="__('components.parents.parents_location_placeholder')"
                label=""
                :noBorder="true"
                :compactRow="true"
                :data-birth-city-id="null"
                :data-birth-taluka-id="null"
                :data-birth-district-id="null"
                :data-birth-state-id="null"
                :dataCountryId="$resCountryId"
                :data-city-id="$resLocationId"
                :data-taluka-id="$resTalukaId"
                :data-district-id="$resDistrictId"
                :data-state-id="$resStateId"
            />
        </div>
    </div>

    {{-- 5. Marital status — full engine (status + status details + children) --}}
    @include('matrimony.profile.wizard.sections.marital_engine', [
        'namePrefix' => $maritalNamePrefix,
        'profile' => $profile,
        'maritalStatuses' => $maritalStatuses ?? collect(),
        'profileMarriages' => $profileMarriages ?? collect(),
        'profileChildren' => $profileChildren ?? collect(),
        'childLivingWithOptions' => $childLivingWithOptions ?? collect(),
    ])
</div>

<script>
document.addEventListener('alpine:init', function() {
    Alpine.data('basicInfoForm', function() {
        var savedHour = @json($btHour);
        var savedMin = @json($btMin);
        var savedAmPm = @json($btAmPm);
        var initialGenderId = @json(old($oldPrefix.'gender_id', $profile->gender_id ?? null));
        return {
            genderId: initialGenderId ? parseInt(initialGenderId, 10) : null,
            birthHour: savedHour !== '' ? String(savedHour) : '',
            birthMinute: savedMin !== '' ? String(savedMin) : '',
            birthAmPm: savedAmPm || 'AM',
            get birthTimeValue() {
                if (!this.birthHour || !this.birthMinute) return '';
                var h = parseInt(this.birthHour, 10);
                if (this.birthAmPm === 'PM' && h < 12) h += 12;
                if (this.birthAmPm === 'AM' && h === 12) h = 0;
                var h24 = (h < 10 ? '0' : '') + h;
                var m = String(this.birthMinute).length === 1 ? '0' + this.birthMinute : this.birthMinute;
                return h24 + ':' + m;
            },
            get birthTimeDisplay() {
                if (!this.birthHour || !this.birthMinute) return '';
                var m = String(this.birthMinute).length === 1 ? '0' + this.birthMinute : this.birthMinute;
                return this.birthHour + ':' + m + ' ' + this.birthAmPm;
            }
        };
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function() { if (window.LocationTypeahead) window.LocationTypeahead.init(); });
</script>
