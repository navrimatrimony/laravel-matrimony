{{-- Centralized Location Engine — single source for all location inputs (residence, work, birth, native, alliance, preferences). Use this component only; no duplicate location UIs. --}}
@props([
    'context' => 'residence', // residence | work | native | alliance
    'namePrefix' => '',       // e.g. 'alliance_networks[0]' for alliance rows
    'value' => '',
    'placeholder' => 'Type village or city',
    'label' => null,
    /** When true and user is logged in, show “current location” assist (never writes profile by itself). */
    'gpsAssist' => true,
    /** Optional override for POST resolve URL (defaults to web route). */
    'resolveUrl' => null,
    'noBorder' => false,      // when true, wrapper has no border (e.g. basic info birth place)
    'compactRow' => false,    // when true, no vertical padding (for single-line row layout)
    /** When true: no outer padding, no border, no rounded corners (relation grid cells). */
    'flush' => false,
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
    /** Canonical residence / pick leaf {@code addresses.id} (SSOT on profile). */
    'dataLocationId' => '',
    'dataCountryId' => '',
    'dataStateId' => '',
    'dataDistrictId' => '',
    'dataTalukaId' => '',
    /** @deprecated Use dataLocationId for residence; kept for alliance rows. */
    'dataCityId' => '',
])
@php
    $inputId = $attributes->get('id') ?? 'location-typeahead-' . $context . '-' . (\Illuminate\Support\Str::random(4));
    $resultsId = $inputId . '-results';
    $gpsPanelId = $inputId . '-gps-panel';
    $wrapperClass = 'location-typeahead-wrapper';
    $isFullMode = ($mode ?? 'simple') === 'full';
    $resolvedDetailedName = $namePrefix !== '' ? ($namePrefix . '[' . $detailedName . ']') : $detailedName;
    $resolveUrlResolved = $resolveUrl ?? (auth()->check() ? route('matrimony.internal.location.resolve-current') : '');
    $showGps = ($gpsAssist ?? true) && auth()->check() && $resolveUrlResolved !== '';
    $defaultIndiaCountryId = \App\Models\Country::query()->where('iso_alpha2', 'IN')->value('id');
    $defaultMaharashtraStateId = $defaultIndiaCountryId
        ? \App\Models\State::query()
            ->where('parent_id', $defaultIndiaCountryId)
            ->whereRaw('LOWER(TRIM(name)) = ?', ['maharashtra'])
            ->value('id')
        : null;
    $resolvedCountryId = filled($dataCountryId) ? $dataCountryId : ($defaultIndiaCountryId ?? '');
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

.location-suggest-modal-backdrop {
    position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.55); z-index: 60;
}
.location-suggest-modal {
    position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 70;
}
.location-suggest-modal-inner {
    max-width: 26rem; width: 100%; background-color: #fff; border-radius: 0.75rem; box-shadow: 0 20px 45px rgba(15,23,42,0.35);
}
.dark .location-suggest-modal-inner {
    background-color: rgb(30 41 59);
}
</style>
@php
    if (!empty($flush)) {
        $paddingClass = 'p-0';
        $borderClass = '';
        $roundedClass = 'rounded-none';
    } else {
        $paddingClass = $compactRow ? 'px-2 py-0' : ($noBorder ? 'pt-0 px-3 pb-3' : 'p-3');
        $borderClass = $noBorder ? '' : 'border border-gray-200 dark:border-gray-600';
        $roundedClass = 'rounded-lg';
    }
@endphp
{{-- API paths must use url() so subdirectory installs (e.g. /project/public) resolve /api/* correctly. Search hits addresses.name, slug, name_mr (+ aliases when present). --}}
<div class="{{ $wrapperClass }} space-y-0 {{ $roundedClass }} {{ $paddingClass }} {{ $borderClass }}" data-location-context="{{ $context }}" data-name-prefix="{{ $namePrefix }}" data-search-url="{{ url('/api/location/search') }}" data-suggest-url="{{ url('/api/location/suggestions') }}" data-url-internal-states="{{ url('/api/internal/location/states') }}" data-url-internal-districts="{{ url('/api/internal/location/districts') }}" data-url-internal-talukas="{{ url('/api/internal/location/talukas') }}" data-url-internal-suggest="{{ url('/api/internal/location/suggest') }}" @if(filled($defaultIndiaCountryId)) data-default-country-id="{{ $defaultIndiaCountryId }}" @endif @if(filled($defaultMaharashtraStateId)) data-default-state-id="{{ $defaultMaharashtraStateId }}" @endif @if($showGps) data-resolve-url="{{ $resolveUrlResolved }}" data-gps="1" @endif @if(!empty($displaySyncName)) data-display-sync-name="{{ $displaySyncName }}" @endif>
    @if ($context === 'residence')
        @php
            $resolvedLocationId = filled($dataLocationId) ? $dataLocationId : $dataCityId;
        @endphp
        <input type="hidden" name="{{ $namePrefix !== '' ? $namePrefix . '[location_id]' : 'location_id' }}" class="location-hidden-location-id" value="{{ $resolvedLocationId }}">
        <input type="hidden" name="{{ $namePrefix !== '' ? $namePrefix . '[location_input]' : 'location_input' }}" class="location-hidden-location-input" value="">
        {{-- Client-only hierarchy hints for JS / GPS; do not POST legacy profile column names. --}}
        <input type="hidden" class="location-hidden-country" value="{{ $resolvedCountryId }}">
        <input type="hidden" class="location-hidden-state" value="{{ $dataStateId }}">
        <input type="hidden" class="location-hidden-district" value="{{ $dataDistrictId }}">
        <input type="hidden" class="location-hidden-taluka" value="{{ $dataTalukaId }}">
    @elseif ($context === 'work')
        <input type="hidden" name="work_location_id" class="location-hidden-location-id" value="{{ $attributes->get('data-work-city-id', '') }}">
        <input type="hidden" name="work_location_input" class="location-hidden-location-input" value="">
        <input type="hidden" name="work_city_id" class="location-hidden-work-city" value="{{ $attributes->get('data-work-city-id', '') }}">
        <input type="hidden" name="work_state_id" class="location-hidden-work-state" value="{{ $attributes->get('data-work-state-id', '') }}">
    @elseif ($context === 'native')
        <input type="hidden" name="native_location_id" class="location-hidden-location-id" value="{{ $attributes->get('data-native-city-id', '') }}">
        <input type="hidden" name="native_location_input" class="location-hidden-location-input" value="">
        <input type="hidden" name="native_city_id" class="location-hidden-native-city" value="{{ $attributes->get('data-native-city-id', '') }}">
        <input type="hidden" name="native_taluka_id" class="location-hidden-native-taluka" value="{{ $attributes->get('data-native-taluka-id', '') }}">
        <input type="hidden" name="native_district_id" class="location-hidden-native-district" value="{{ $attributes->get('data-native-district-id', '') }}">
        <input type="hidden" name="native_state_id" class="location-hidden-native-state" value="{{ $attributes->get('data-native-state-id', '') }}">
    @elseif ($context === 'birth')
        @php $birthName = $namePrefix !== '' ? $namePrefix . '[birth_city_id]' : 'birth_city_id'; @endphp
        <input type="hidden" name="{{ $birthName }}" class="location-hidden-location-id location-hidden-birth-city" value="{{ $attributes->get('data-birth-city-id', '') }}">
        <input type="hidden" name="{{ $namePrefix !== '' ? $namePrefix . '[birth_location_input]' : 'birth_location_input' }}" class="location-hidden-location-input" value="">
        {{-- Optional client-only hints for ancestor chain (same classes as residence aux). --}}
        <input type="hidden" class="location-hidden-birth-taluka" value="{{ $attributes->get('data-birth-taluka-id', '') }}">
        <input type="hidden" class="location-hidden-birth-district" value="{{ $attributes->get('data-birth-district-id', '') }}">
        <input type="hidden" class="location-hidden-birth-state" value="{{ $attributes->get('data-birth-state-id', '') }}">
    @elseif ($context === 'alliance' && $namePrefix !== '')
        <input type="hidden" name="{{ $namePrefix }}[location_id]" class="location-hidden-location-id" value="{{ $dataCityId }}">
        <input type="hidden" name="{{ $namePrefix }}[location_input]" class="location-hidden-location-input" value="">
        <input type="hidden" name="{{ $namePrefix }}[taluka_id]" class="location-hidden-taluka" value="{{ $dataTalukaId }}">
        <input type="hidden" name="{{ $namePrefix }}[district_id]" class="location-hidden-district" value="{{ $dataDistrictId }}">
        <input type="hidden" name="{{ $namePrefix }}[state_id]" class="location-hidden-state" value="{{ $dataStateId }}">
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
                <div class="flex gap-1.5 items-center">
                    <div class="flex-1 min-w-0 relative">
                        <input type="text"
                               id="{{ $inputId }}"
                               class="location-typeahead-input box-border h-11 w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                               value="{{ $value }}"
                               placeholder="{{ $placeholder }}"
                               autocomplete="off">
                        <div id="{{ $resultsId }}" class="location-typeahead-results border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-48 overflow-y-auto hidden"></div>
                    </div>
                    @if ($showGps)
                        <button type="button" class="location-gps-btn box-border inline-flex h-11 w-11 shrink-0 items-center justify-center rounded border border-gray-300 bg-gray-50 text-blue-600 hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-700 dark:text-blue-400 dark:hover:bg-gray-600" title="{{ __('wizard.use_current_location') }}" aria-label="{{ __('wizard.use_current_location') }}">
                            {{-- Google Maps–style teardrop pin --}}
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                                <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                            </svg>
                        </button>
                    @endif
                </div>
                @if ($showGps)
                    <div id="{{ $gpsPanelId }}" class="location-gps-panel mt-2 text-sm hidden"></div>
                @endif
            </div>
        </div>
    @else
        @if ($label)
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $label }}</label>
        @endif
        <div class="flex gap-1.5 items-center">
            <div class="flex-1 min-w-0 relative">
                <input type="text"
                       id="{{ $inputId }}"
                       class="location-typeahead-input box-border h-11 w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                       value="{{ $value }}"
                       placeholder="{{ $placeholder }}"
                       autocomplete="off">
                <div id="{{ $resultsId }}" class="location-typeahead-results border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-48 overflow-y-auto hidden"></div>
            </div>
            @if ($showGps)
                <button type="button" class="location-gps-btn box-border inline-flex h-11 w-11 shrink-0 items-center justify-center rounded border border-gray-300 bg-gray-50 text-blue-600 hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-700 dark:text-blue-400 dark:hover:bg-gray-600" title="{{ __('wizard.use_current_location') }}" aria-label="{{ __('wizard.use_current_location') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                    </svg>
                </button>
            @endif
        </div>
        @if ($showGps)
            <div id="{{ $gpsPanelId }}" class="location-gps-panel mt-2 text-sm hidden"></div>
        @endif
    @endif
    @if ($context === 'residence')
        <div class="location-pending-summary mt-1.5 text-xs hidden text-gray-600 dark:text-gray-400 space-y-1" data-location-pending-summary aria-live="polite"></div>
    @endif
</div>

@once
<template id="location-suggest-modal-template">
    <div class="location-suggest-modal-backdrop"></div>
    <div class="location-suggest-modal">
        <div class="location-suggest-modal-inner border border-gray-200 dark:border-gray-600">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Add place (review)</h3>
                <button type="button" class="location-suggest-close text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-xl leading-none px-1">&times;</button>
            </div>
            <div class="px-4 py-3 space-y-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-0.5">Name you typed</label>
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 location-suggest-name-display"></div>
                </div>
                <div class="grid grid-cols-1 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">State</label>
                        <select class="location-suggest-state w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-3 py-2 text-sm">
                            <option value="">Select state</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">District</label>
                        <select class="location-suggest-district w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-3 py-2 text-sm">
                            <option value="">Select district</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Taluka</label>
                        <select class="location-suggest-taluka w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-3 py-2 text-sm">
                            <option value="">Select taluka</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Place type</label>
                        <select class="location-suggest-place-type w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-3 py-2 text-sm">
                            <option value="village">Village</option>
                            <option value="city">Town / city / suburb</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Pincode (optional)</label>
                        <input type="text" maxlength="10" inputmode="numeric" autocomplete="postal-code" placeholder="e.g. 415309" class="location-suggest-pincode w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-3 py-2 text-sm">
                    </div>
                </div>
                <div class="location-suggest-error text-xs text-red-600 dark:text-red-400 hidden"></div>
                <div class="location-suggest-success text-xs text-emerald-600 dark:text-emerald-400 hidden"></div>
            </div>
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex items-center justify-end gap-2">
                <button type="button" class="location-suggest-cancel px-3 py-1.5 rounded text-xs font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">Cancel</button>
                <button type="button" class="location-suggest-submit px-3 py-1.5 rounded text-xs font-semibold text-white bg-rose-600 hover:bg-rose-700">Submit</button>
            </div>
        </div>
    </div>
</template>
@endonce
