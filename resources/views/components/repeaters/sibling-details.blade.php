{{-- Day 31 Part 2: Sibling Details — uses centralized relation-details engine (showMarried=true). --}}
@props(['siblings' => collect()])
@php
    $siblingRelationOptions = [['value' => 'brother', 'label' => 'Brother'], ['value' => 'sister', 'label' => 'Sister']];
@endphp
<x-repeaters.relation-details
    namePrefix="siblings"
    :relationOptions="$siblingRelationOptions"
    :showMarried="true"
    :items="$siblings"
    addButtonLabel="Add Sibling"
    removeButtonLabel="Remove this sibling"
/>
