<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 5]) }}" class="space-y-6">
    @csrf
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
            mode="full"
            :noBorder="true"
            :detailedLabel="__('Detailed address')"
            :value="old('wizard_residence_display', $profile->residenceLocationDisplayLine())"
            :detailedValue="old('address_line', $profile->address_line)"
            :placeholder="__('wizard.type_city_area')"
            :dataCountryId="$profile->country_id"
            :dataStateId="$profile->state_id"
            :dataDistrictId="$profile->district_id"
            :dataTalukaId="$profile->taluka_id"
            :dataCityId="$profile->city_id"
            :label="__('wizard.type_city_area')"
        />
        @error('address_line')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        @error('city_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    {{-- Sticky footer: save step 5 → redirect to photo upload --}}
    <div class="mt-8 space-y-4 sm:sticky sm:bottom-4 sm:z-10 sm:-mx-1 sm:px-4 sm:py-4 sm:rounded-xl sm:border sm:border-gray-200/90 sm:dark:border-gray-600 sm:bg-white/95 sm:dark:bg-gray-800/95 sm:backdrop-blur-md sm:shadow-lg sm:shadow-slate-300/20 dark:sm:shadow-none">
        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('onboarding.step5_continue_intro') }}</p>
        <x-onboarding.form-footer
            :back-url="route('matrimony.onboarding.show', ['step' => 4])"
            :submit-label="__('onboarding.continue')"
            submit-extra-class="onboarding-step5-submit !min-h-[58px] !text-lg !font-bold !px-11"
            class="!mt-0 !pt-4 border-t border-gray-200 dark:border-gray-600"
        />
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.LocationTypeahead) window.LocationTypeahead.init();
});
</script>
