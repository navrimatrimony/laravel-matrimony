{{-- Reusable Property Engine: one governed free-text property field. --}}
@props([
    'details' => '',
    'namePrefix' => '',
])
@php
    $prefix = $namePrefix !== '' ? $namePrefix : '';
    $fieldName = $prefix !== '' ? $prefix . '[core][property_details]' : 'property_details';
@endphp

<div class="space-y-4 property-engine" data-property-engine data-name-prefix="{{ $prefix }}">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">
        {{ __('components.property.property') }}
    </h2>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ __('components.property.property_details') }}
        </label>
        <textarea
            name="{{ $fieldName }}"
            rows="7"
            class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2"
            placeholder="{{ __('components.property.property_details_placeholder') }}"
        >{{ $details ?? '' }}</textarea>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            {{ __('components.property.property_details_help') }}
        </p>
    </div>
</div>
