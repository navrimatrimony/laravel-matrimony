{{-- Phase-5 SSOT: Property — location (residence) + property-engine. Controller expects top-level property_summary, property_assets, and merges location into core when saving. --}}
@php
    $propertySummary = old('property_summary', $profile_property_summary ?? null);
    $propertyAssets = old('property_assets', $profile_property_assets ?? collect());
    if (is_object($propertySummary)) { $propertySummary = (array) $propertySummary; }
    if (is_object($propertyAssets)) { $propertyAssets = $propertyAssets->all(); }
    $prof = $profile ?? null;
@endphp

<div class="space-y-6">
    {{-- Centralized Location (residence) — same engine as elsewhere --}}
    <div class="border-2 border-rose-500 dark:border-rose-400 rounded-lg p-4">
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Residence (village / city)</h3>
        <x-profile.location-typeahead
            context="residence"
            :value="old('wizard_city_display', $prof?->city?->name ?? '')"
            placeholder="Type village / city / pincode"
            label="Search village or city (residence)"
            :data-country-id="old('country_id', $prof?->country_id ?? '')"
            :data-state-id="old('state_id', $prof?->state_id ?? '')"
            :data-district-id="old('district_id', $prof?->district_id ?? '')"
            :data-taluka-id="old('taluka_id', $prof?->taluka_id ?? '')"
            :data-city-id="old('city_id', $prof?->city_id ?? '')"
        />
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mt-2 mb-1">Address line (optional)</label>
        <input type="text" name="address_line" value="{{ old('address_line', $prof?->address_line ?? '') }}" maxlength="255" placeholder="e.g. Building, area, landmark" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
    </div>

    <x-profile.property-engine
        :summary="$propertySummary ?? []"
        :assets="$propertyAssets ?? []"
        :assetTypes="$assetTypes ?? []"
        :ownershipTypes="$ownershipTypes ?? []"
        namePrefix=""
    />
</div>
<script>document.addEventListener('DOMContentLoaded', function() { if (window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init(); });</script>
