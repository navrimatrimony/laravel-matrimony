{{-- Shared location typeahead cell for relation engine (siblings use inline block; relatives include this partial). --}}
@props(['namePrefix', 'idx', 'r'])
<div class="min-w-0 relation-address-cell">
    <x-profile.location-typeahead
        context="alliance"
        namePrefix="{{ $namePrefix }}[{{ $idx }}]"
        :value="$r['location_display'] ?? $r['address_line'] ?? ''"
        :detailedValue="$r['address_line'] ?? ($r['location_display'] ?? '')"
        placeholder="{{ __('components.relation.address_city') }}"
        label="{{ __('components.relation.address') }}"
        :data-city-id="$r['city_id'] ?? ''"
        :data-taluka-id="$r['taluka_id'] ?? ''"
        :data-district-id="$r['district_id'] ?? ''"
        :data-state-id="$r['state_id'] ?? ''"
        :flush="true"
    />
</div>
