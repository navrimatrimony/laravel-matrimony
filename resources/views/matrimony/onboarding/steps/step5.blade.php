<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 5]) }}" class="space-y-6">
    @csrf
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('onboarding.height_cm_label') }} (cm)</label>
        <div class="flex items-center gap-2">
            <input type="number" name="height_cm" value="{{ old('height_cm', $profile->height_cm) }}" min="50" max="250" step="1"
                class="w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-4 py-3 text-base min-h-[48px] focus:ring-2 focus:ring-indigo-500"
                placeholder="e.g. 170">
            <span class="text-sm text-gray-500 dark:text-gray-400 shrink-0">cm</span>
        </div>
        @error('height_cm')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <x-profile.location-typeahead
            context="residence"
            mode="full"
            :detailedLabel="__('Detailed address')"
            :value="old('wizard_residence_display', '')"
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

    <div class="flex flex-col sm:flex-row gap-3 pt-2">
        <a href="{{ route('matrimony.onboarding.show', ['step' => 4]) }}" class="inline-flex justify-center items-center min-h-[52px] px-6 rounded-xl text-base font-semibold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 w-full sm:w-auto text-center">
            {{ __('onboarding.back') }}
        </a>
        <button type="submit" class="inline-flex justify-center items-center min-h-[52px] px-6 rounded-xl text-base font-semibold text-white bg-gradient-to-r from-indigo-600 to-rose-600 hover:from-indigo-700 hover:to-rose-700 w-full sm:flex-1">
            {{ __('onboarding.continue') }}
        </button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.LocationTypeahead) window.LocationTypeahead.init();
});
</script>
