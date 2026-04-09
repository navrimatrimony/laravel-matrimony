<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 3]) }}" class="space-y-6">
    @csrf
    <x-profile.religion-caste-selector :profile="$profile" namePrefix="" :show-subcaste="false" />

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900/20 p-4 space-y-2">
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.height_feet_inch_hint') }}</p>
        <x-profile.height-picker
            :value="old('height_cm', $profile->height_cm)"
            :label="__('onboarding.height_cm_label')"
            wrapper-class="height-picker w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-800/40 p-3"
        />
        @error('height_cm')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900/20 p-4 space-y-2">
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.step5_location_hint') }}</p>
        <x-profile.location-typeahead
            context="residence"
            mode="simple"
            :noBorder="true"
            :value="old('wizard_residence_display', $profile->residenceLocationDisplayLine())"
            :placeholder="__('wizard.type_city_area')"
            :dataCountryId="$profile->country_id"
            :dataStateId="$profile->state_id"
            :dataDistrictId="$profile->district_id"
            :dataTalukaId="$profile->taluka_id"
            :dataCityId="$profile->city_id"
            :label="__('wizard.type_city_area')"
        />
        @error('city_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        @error('district_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <x-onboarding.form-footer
        :back-url="route('matrimony.onboarding.show', ['step' => 2])"
    />
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.LocationTypeahead) window.LocationTypeahead.init();
});
</script>
