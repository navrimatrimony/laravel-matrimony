@props([
    'context' => 'residence', // residence | work | native | alliance
    'namePrefix' => '',       // e.g. 'alliance_networks[0]' for alliance rows
    'value' => '',
    'placeholder' => 'Type village / city / pincode',
    'label' => null,
    'noBorder' => false,      // when true, wrapper has no border (e.g. basic info birth place)
    'compactRow' => false,    // when true, no vertical padding (for single-line row layout)
    /**
     * Mode support (Phase-5): simple (default) vs full (adds detailed address text field).
     * Backward compatible: when not provided, component behaves exactly as before.
     */
    'mode' => 'simple', // simple | full
    // Full-mode only: free-text address line (flat/house/society/landmark etc.)
    'detailedLabel' => 'Detailed address',
    'detailedPlaceholder' => 'Flat / house / society / road / landmark',
    'detailedValue' => '',
    // Keep existing field naming by default; override per usage when needed.
    'detailedName' => 'address_line',
    // Optional: when set, onSelect will set form input with this name to display label (e.g. preferences[preferred_city])
    'displaySyncName' => null,
])
@php
    $inputId = $attributes->get('id') ?? 'location-typeahead-' . $context . '-' . (\Illuminate\Support\Str::random(4));
    $resultsId = $inputId . '-results';
    $wrapperClass = 'location-typeahead-wrapper';
    $isFullMode = ($mode ?? 'simple') === 'full';
    $resolvedDetailedName = $namePrefix !== '' ? ($namePrefix . '[' . $detailedName . ']') : $detailedName;
@endphp
<style>
.location-typeahead-wrapper { position: relative; }
.location-typeahead-results {
    position: absolute; left: 0; right: 0; top: 100%; width: 100%; z-index: 50; box-sizing: border-box;
    background-color: #fff;
}
.dark .location-typeahead-results {
    background-color: rgb(55 65 81);
}
</style>
@php
    $paddingClass = $compactRow ? 'px-2 py-0' : ($noBorder ? 'pt-0 px-3 pb-3' : 'p-3');
    $borderClass = $noBorder ? '' : 'border-2 border-rose-500 dark:border-rose-400';
@endphp
<div class="{{ $wrapperClass }} space-y-0 rounded-lg {{ $paddingClass }} {{ $borderClass }}" data-location-context="{{ $context }}" data-name-prefix="{{ $namePrefix }}" @if(!empty($displaySyncName)) data-display-sync-name="{{ $displaySyncName }}" @endif>
    @if ($context === 'residence')
        <input type="hidden" name="{{ $namePrefix !== '' ? $namePrefix . '[country_id]' : 'country_id' }}" class="location-hidden-country" value="{{ $attributes->get('data-country-id', '') }}">
        <input type="hidden" name="{{ $namePrefix !== '' ? $namePrefix . '[state_id]' : 'state_id' }}" class="location-hidden-state" value="{{ $attributes->get('data-state-id', '') }}">
        <input type="hidden" name="{{ $namePrefix !== '' ? $namePrefix . '[district_id]' : 'district_id' }}" class="location-hidden-district" value="{{ $attributes->get('data-district-id', '') }}">
        <input type="hidden" name="{{ $namePrefix !== '' ? $namePrefix . '[taluka_id]' : 'taluka_id' }}" class="location-hidden-taluka" value="{{ $attributes->get('data-taluka-id', '') }}">
        <input type="hidden" name="{{ $namePrefix !== '' ? $namePrefix . '[city_id]' : 'city_id' }}" class="location-hidden-city" value="{{ $attributes->get('data-city-id', '') }}">
    @elseif ($context === 'work')
        <input type="hidden" name="work_city_id" class="location-hidden-work-city" value="{{ $attributes->get('data-work-city-id', '') }}">
        <input type="hidden" name="work_state_id" class="location-hidden-work-state" value="{{ $attributes->get('data-work-state-id', '') }}">
    @elseif ($context === 'native')
        <input type="hidden" name="native_city_id" class="location-hidden-native-city" value="{{ $attributes->get('data-native-city-id', '') }}">
        <input type="hidden" name="native_taluka_id" class="location-hidden-native-taluka" value="{{ $attributes->get('data-native-taluka-id', '') }}">
        <input type="hidden" name="native_district_id" class="location-hidden-native-district" value="{{ $attributes->get('data-native-district-id', '') }}">
        <input type="hidden" name="native_state_id" class="location-hidden-native-state" value="{{ $attributes->get('data-native-state-id', '') }}">
    @elseif ($context === 'birth')
        @php $birthName = $namePrefix !== '' ? $namePrefix . '[birth_city_id]' : 'birth_city_id'; $birthT = $namePrefix !== '' ? $namePrefix . '[birth_taluka_id]' : 'birth_taluka_id'; $birthD = $namePrefix !== '' ? $namePrefix . '[birth_district_id]' : 'birth_district_id'; $birthS = $namePrefix !== '' ? $namePrefix . '[birth_state_id]' : 'birth_state_id'; @endphp
        <input type="hidden" name="{{ $birthName }}" class="location-hidden-birth-city" value="{{ $attributes->get('data-birth-city-id', '') }}">
        <input type="hidden" name="{{ $birthT }}" class="location-hidden-birth-taluka" value="{{ $attributes->get('data-birth-taluka-id', '') }}">
        <input type="hidden" name="{{ $birthD }}" class="location-hidden-birth-district" value="{{ $attributes->get('data-birth-district-id', '') }}">
        <input type="hidden" name="{{ $birthS }}" class="location-hidden-birth-state" value="{{ $attributes->get('data-birth-state-id', '') }}">
    @elseif ($context === 'alliance' && $namePrefix !== '')
        <input type="hidden" name="{{ $namePrefix }}[city_id]" class="location-hidden-city" value="{{ $attributes->get('data-city-id', '') }}">
        <input type="hidden" name="{{ $namePrefix }}[taluka_id]" class="location-hidden-taluka" value="{{ $attributes->get('data-taluka-id', '') }}">
        <input type="hidden" name="{{ $namePrefix }}[district_id]" class="location-hidden-district" value="{{ $attributes->get('data-district-id', '') }}">
        <input type="hidden" name="{{ $namePrefix }}[state_id]" class="location-hidden-state" value="{{ $attributes->get('data-state-id', '') }}">
    @endif
    @if ($isFullMode)
        <div class="flex flex-row flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $detailedLabel }}</label>
                <input
                    type="text"
                    name="{{ $resolvedDetailedName }}"
                    value="{{ $detailedValue }}"
                    maxlength="255"
                    placeholder="{{ $detailedPlaceholder }}"
                    class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2"
                >
            </div>
            <div class="flex-1 min-w-[200px]">
                @if ($label)
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $label }}</label>
                @endif
                <input type="text"
                       id="{{ $inputId }}"
                       class="location-typeahead-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px]"
                       value="{{ $value }}"
                       placeholder="{{ $placeholder }}"
                       autocomplete="off">
                <div id="{{ $resultsId }}" class="location-typeahead-results border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-48 overflow-y-auto hidden"></div>
            </div>
        </div>
    @else
        @if ($label)
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $label }}</label>
        @endif
        <input type="text"
               id="{{ $inputId }}"
               class="location-typeahead-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px]"
               value="{{ $value }}"
               placeholder="{{ $placeholder }}"
               autocomplete="off">
        <div id="{{ $resultsId }}" class="location-typeahead-results border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-48 overflow-y-auto hidden"></div>
    @endif
</div>
