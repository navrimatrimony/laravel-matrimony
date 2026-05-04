{{-- Residence / work / native: same CORE keys as ProfileWizardController::buildLocationSnapshot (flat names; optional core[...] via residenceScalarFromRequest). --}}
@php
    $residenceDisplay = $residencePlaceDisplay ?? old('wizard_residence_display', \App\Models\MatrimonyProfile::residenceLocationDisplayLineFor($profile));
    $workDisplay = $workPlaceDisplay ?? old('wizard_work_place_display', $workCityName ?? '');
    $nativeDisplay = $nativePlaceTypeaheadDisplay ?? old('wizard_native_place_display', $nativePlaceDisplay ?? '');
    $resHints = ['location_id' => '', 'country_id' => '', 'state_id' => '', 'district_id' => '', 'taluka_id' => ''];
    if ($profile instanceof \App\Models\MatrimonyProfile) {
        $resHints = $profile->residenceLocationHierarchyHints();
    }
@endphp
<div class="space-y-8">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('wizard.location_residence_heading') }}</h2>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">{{ __('wizard.location_residence_help') }}</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div class="min-w-0 md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.current_address_line') }}</label>
                <input type="text" name="address_line" value="{{ old('address_line', $profile->address_line ?? '') }}"
                    maxlength="255"
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px] focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="{{ __('components.parents.parents_address_line') }}">
                @error('address_line')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
            </div>
            <div class="min-w-0 md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.current_city_village_district') }}</label>
                <x-profile.location-typeahead
                    context="residence"
                    :value="$residenceDisplay"
                    :placeholder="__('components.parents.parents_location_placeholder')"
                    label=""
                    :noBorder="true"
                    :compactRow="true"
                    :dataLocationId="$resHints['location_id']"
                    :dataCountryId="$resHints['country_id']"
                    :dataStateId="$resHints['state_id']"
                    :dataDistrictId="$resHints['district_id']"
                    :dataTalukaId="$resHints['taluka_id']"
                />
            </div>
        </div>
    </div>

    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('wizard.work_location') }}</h2>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">{{ __('wizard.work_location_help') }}</p>
        <div class="mt-4">
            <x-profile.location-typeahead
                context="work"
                :value="$workDisplay"
                :placeholder="__('wizard.type_city_area')"
                label=""
                :noBorder="true"
                :data-work-city-id="old('work_city_id', $profile->work_city_id)"
                :data-work-state-id="old('work_state_id', $profile->work_state_id)"
            />
        </div>
        @error('work_city_id')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        @error('work_state_id')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
    </div>

    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('wizard.native_place') }}</h2>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">{{ __('wizard.native_place_help') }}</p>
        <div class="mt-4">
            <x-profile.location-typeahead
                context="native"
                :value="$nativeDisplay"
                :placeholder="__('wizard.type_city_area')"
                label=""
                :noBorder="true"
                :data-native-city-id="old('native_city_id', $profile->native_city_id)"
                :data-native-taluka-id="old('native_taluka_id', $profile->native_taluka_id)"
                :data-native-district-id="old('native_district_id', $profile->native_district_id)"
                :data-native-state-id="old('native_state_id', $profile->native_state_id)"
            />
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() { if (window.LocationTypeahead) window.LocationTypeahead.init(); });
</script>
