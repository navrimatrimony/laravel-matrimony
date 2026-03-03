@props([
    'context' => 'residence', // residence | work | native | alliance
    'namePrefix' => '',       // e.g. 'alliance_networks[0]' for alliance rows
    'value' => '',
    'placeholder' => 'Type village / city / pincode',
    'label' => null,
])
@php
    $inputId = $attributes->get('id') ?? 'location-typeahead-' . $context . '-' . (\Illuminate\Support\Str::random(4));
    $resultsId = $inputId . '-results';
    $wrapperClass = 'location-typeahead-wrapper';
@endphp
<div class="{{ $wrapperClass }} space-y-0" data-location-context="{{ $context }}" data-name-prefix="{{ $namePrefix }}">
    @if ($label)
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $label }}</label>
    @endif
    @if ($context === 'residence')
        <input type="hidden" name="country_id" class="location-hidden-country" value="{{ $attributes->get('data-country-id', '') }}">
        <input type="hidden" name="state_id" class="location-hidden-state" value="{{ $attributes->get('data-state-id', '') }}">
        <input type="hidden" name="district_id" class="location-hidden-district" value="{{ $attributes->get('data-district-id', '') }}">
        <input type="hidden" name="taluka_id" class="location-hidden-taluka" value="{{ $attributes->get('data-taluka-id', '') }}">
        <input type="hidden" name="city_id" class="location-hidden-city" value="{{ $attributes->get('data-city-id', '') }}">
    @elseif ($context === 'work')
        <input type="hidden" name="work_city_id" class="location-hidden-work-city" value="{{ $attributes->get('data-work-city-id', '') }}">
        <input type="hidden" name="work_state_id" class="location-hidden-work-state" value="{{ $attributes->get('data-work-state-id', '') }}">
    @elseif ($context === 'native')
        <input type="hidden" name="native_city_id" class="location-hidden-native-city" value="{{ $attributes->get('data-native-city-id', '') }}">
        <input type="hidden" name="native_taluka_id" class="location-hidden-native-taluka" value="{{ $attributes->get('data-native-taluka-id', '') }}">
        <input type="hidden" name="native_district_id" class="location-hidden-native-district" value="{{ $attributes->get('data-native-district-id', '') }}">
        <input type="hidden" name="native_state_id" class="location-hidden-native-state" value="{{ $attributes->get('data-native-state-id', '') }}">
    @elseif ($context === 'alliance' && $namePrefix !== '')
        <input type="hidden" name="{{ $namePrefix }}[city_id]" class="location-hidden-city" value="{{ $attributes->get('data-city-id', '') }}">
        <input type="hidden" name="{{ $namePrefix }}[taluka_id]" class="location-hidden-taluka" value="{{ $attributes->get('data-taluka-id', '') }}">
        <input type="hidden" name="{{ $namePrefix }}[district_id]" class="location-hidden-district" value="{{ $attributes->get('data-district-id', '') }}">
        <input type="hidden" name="{{ $namePrefix }}[state_id]" class="location-hidden-state" value="{{ $attributes->get('data-state-id', '') }}">
    @endif
    <input type="text"
           id="{{ $inputId }}"
           class="location-typeahead-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2"
           value="{{ $value }}"
           placeholder="{{ $placeholder }}"
           autocomplete="off">
    <div id="{{ $resultsId }}" class="location-typeahead-results border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-48 overflow-y-auto hidden"></div>
</div>
