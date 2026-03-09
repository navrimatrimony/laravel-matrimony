{{-- Property tab: only Property assets (repeatable) + Notes. Residence and Property summary (Own House/Flat/Agriculture etc.) removed. --}}
@php
    $propertySummary = old('property_summary', $profile_property_summary ?? null);
    $propertyAssets = old('property_assets', $profile_property_assets ?? collect());
    if (is_object($propertySummary)) { $propertySummary = (array) $propertySummary; }
    if (is_object($propertyAssets)) { $propertyAssets = $propertyAssets->all(); }
@endphp

<div class="space-y-6">
    <x-profile.property-engine
        :summary="$propertySummary ?? []"
        :assets="$propertyAssets ?? []"
        :assetTypes="$assetTypes ?? []"
        :ownershipTypes="$ownershipTypes ?? []"
        namePrefix=""
    />
</div>
<script>document.addEventListener('DOMContentLoaded', function() { if (window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init(); });</script>
