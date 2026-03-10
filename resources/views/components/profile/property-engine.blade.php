{{-- Reusable Property Engine: summary + repeatable assets. Used in wizard (namePrefix empty) and intake preview (namePrefix snapshot). --}}
@props([
    'summary' => [],
    'assets' => [],
    'assetTypes' => [],
    'ownershipTypes' => [],
    'namePrefix' => '',
])
@php
    $sum = is_object($summary) ? (array) $summary : (array) $summary;
    $assetList = $assets;
    if (is_object($assetList)) { $assetList = $assetList->all(); }
    if (!is_array($assetList)) { $assetList = []; }
    if (count($assetList) === 0) { $assetList = [[]]; }
    $prefix = $namePrefix !== '' ? $namePrefix : '';
    $sumName = function($key) use ($prefix) { return $prefix !== '' ? $prefix . '[property_summary][' . $key . ']' : 'property_summary[' . $key . ']'; };
    $assetName = function($idx, $key) use ($prefix) { return $prefix !== '' ? $prefix . '[property_assets][' . $idx . '][' . $key . ']' : 'property_assets[' . $idx . '][' . $key . ']'; };
    $assetsContainerId = $prefix !== '' ? str_replace('[', '-', str_replace(']', '', $prefix)) . '-property-assets' : 'property-assets-container';
    $assetsNamePrefix = $prefix !== '' ? $prefix . '[property_assets]' : 'property_assets';
@endphp
<div class="space-y-6 property-engine" data-property-engine data-name-prefix="{{ $prefix }}">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('components.property.property') }}</h2>

    {{-- Property Assets (repeatable) — same Add / Remove this entry pattern as relation-details --}}
    <div class="border-2 border-rose-500 dark:border-rose-400 rounded-lg p-4">
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('components.property.property_assets') }}</h3>
        <style>
        .property-engine .property-asset-row:not(:last-child) .property-asset-add-wrap { display: none; }
        .property-engine .property-asset-location-cell .location-typeahead-wrapper { padding: 0; border: none; }
        .property-engine .property-asset-location-cell .location-typeahead-input { min-height: 2.25rem; font-size: 0.875rem; }
        </style>
        <div id="{{ $assetsContainerId }}" class="property-assets-container space-y-3" data-repeater-container data-name-prefix="{{ $assetsNamePrefix }}" data-row-class="property-asset-row" data-min-rows="1">
            @foreach($assetList as $idx => $row)
                @php $r = is_object($row) ? (array) $row : (array) $row; @endphp
                <div class="property-asset-row p-3 bg-gray-50 dark:bg-gray-700/50 rounded space-y-2">
                    <input type="hidden" name="{{ $assetName($idx, 'id') }}" value="{{ $r['id'] ?? '' }}">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                        <div class="min-w-0">
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.property.asset_type') }}</label>
                            <select name="{{ $assetName($idx, 'asset_type_id') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                                <option value="">{{ __('common.select') }}</option>
                                @foreach($assetTypes ?? [] as $item)
                                    @php $label = $item->label ?? $item->key ?? $item->id; @endphp
                                    <option value="{{ $item->id }}" {{ (string)($r['asset_type_id'] ?? '') === (string)$item->id ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="min-w-0 property-asset-location-cell">
                            <input type="hidden" name="{{ $assetName($idx, 'location') }}" class="location-display-sync" value="{{ $r['location'] ?? '' }}">
                            <x-profile.location-typeahead
                                context="alliance"
                                :namePrefix="($prefix !== '' ? $prefix . '[property_assets][' . $idx . ']' : 'property_assets[' . $idx . ']')"
                                :value="$r['location'] ?? ''"
                                placeholder="{{ __('components.property.type_village_city_pincode') }}"
                                label="{{ __('components.property.location') }}"
                                :data-city-id="$r['city_id'] ?? ''"
                                :data-taluka-id="$r['taluka_id'] ?? ''"
                                :data-district-id="$r['district_id'] ?? ''"
                                :data-state-id="$r['state_id'] ?? ''"
                            />
                        </div>
                        <div class="min-w-0">
                            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.property.ownership_type') }}</label>
                            <select name="{{ $assetName($idx, 'ownership_type_id') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                                <option value="">{{ __('common.select') }}</option>
                                @foreach($ownershipTypes ?? [] as $item)
                                    @php $label = $item->label ?? $item->key ?? $item->id; @endphp
                                    <option value="{{ $item->id }}" {{ (string)($r['ownership_type_id'] ?? '') === (string)$item->id ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-between items-center">
                        <div class="property-asset-add-wrap">
                            <span role="button" tabindex="0" class="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 cursor-pointer font-medium text-sm" data-repeater-add data-repeater-for="{{ $assetsContainerId }}"><span aria-hidden="true">+</span> {{ __('common.add') }}</span>
                        </div>
                        <div>
                            <button type="button" class="text-sm text-red-600 dark:text-red-400 hover:underline" data-repeater-remove>{{ __('common.remove_this_entry') }}</button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Notes only (summary section reduced to just notes) --}}
    <div>
        <input type="hidden" name="{{ $sumName('id') }}" value="{{ $sum['id'] ?? '' }}">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('common.notes') }}</label>
        <textarea name="{{ $sumName('summary_notes') }}" rows="2" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" placeholder="{{ __('components.property.optional_notes_about_property') }}">{{ $sum['summary_notes'] ?? '' }}</textarea>
    </div>
</div>

<x-repeaters.repeater-script />
<script>
(function() {
    document.querySelectorAll('[data-property-engine]').forEach(function(engine) {
        var container = engine.querySelector('[data-repeater-container]');
        if (!container) return;
        container.addEventListener('repeater:row-added', function(e) {
            var row = e.detail && e.detail.row;
            if (!row) return;
            row.querySelectorAll('.location-typeahead-wrapper').forEach(function(w) {
                w.removeAttribute('data-bound');
                var inp = w.querySelector('.location-typeahead-input');
                if (inp) inp.value = '';
                w.querySelectorAll('.location-hidden-city, .location-hidden-taluka, .location-hidden-district, .location-hidden-state').forEach(function(h) { h.value = ''; });
            });
            var sync = row.querySelector('.location-display-sync');
            if (sync) sync.value = '';
            if (window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init();
        });
    });
})();
</script>
