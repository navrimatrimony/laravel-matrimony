{{-- Property tab: one governed multiline text field. --}}
@php
    $namePrefix = $namePrefix ?? '';
    $oldKey = $namePrefix !== '' ? str_replace(']', '', str_replace('[', '.', $namePrefix)) . '.core.property_details' : 'property_details';
    $propertyDetails = old($oldKey, $profile_property_details ?? ($profile->property_details ?? ''));
@endphp

<div class="space-y-6">
    <x-profile.property-engine
        :details="$propertyDetails"
        :namePrefix="$namePrefix"
    />
</div>
