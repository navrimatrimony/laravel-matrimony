@props([
    // When used in wizard/admin, pass MatrimonyProfile instance.
    'profile' => null,
    // When used in intake, pass associative array of current core values.
    'values' => [],
    // Optional name prefix for snapshot-style forms (e.g. 'snapshot[core]').
    'namePrefix' => '',
    'currencies' => [],
    'errors' => [],
])
@php
    // Canonical option sets (keys stored in DB, labels shown in UI).
    $familyStatusOptions = trans('components.family.status_options');
    $familyValuesOptions = trans('components.family.values_options');
    $hasPrefix = $namePrefix !== '';

    // Resolve field names depending on context.
    $nameFamilyTypeId = $hasPrefix ? $namePrefix . '[family_type_id]' : 'family_type_id';
    $nameFamilyStatus = $hasPrefix ? $namePrefix . '[family_status]' : 'family_status';
    $nameFamilyValues = $hasPrefix ? $namePrefix . '[family_values]' : 'family_values';

    // Resolve current values: intake-style values take precedence, then old()/profile when no prefix.
    if ($hasPrefix) {
        $currentFamilyTypeId = $values['family_type_id'] ?? null;
        $currentFamilyStatus = $values['family_status'] ?? null;
        $currentFamilyValues = $values['family_values'] ?? null;
    } else {
        $currentFamilyTypeId = old('family_type_id', $profile->family_type_id ?? null);
        $currentFamilyStatus = old('family_status', $profile->family_status ?? null);
        $currentFamilyValues = old('family_values', $profile->family_values ?? null);
    }

    // Master family types from controller (wizard) or lazy-load (other contexts).
    $familyTypes = $familyTypes ?? ($familyTypes ?? \App\Models\MasterFamilyType::where('is_active', true)->get());
@endphp

<div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 space-y-4">
    {{-- Row 1: Family Type, Status, Values — one line, same width as below --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.family.family_type') }}</label>
            <select name="{{ $nameFamilyTypeId }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">{{ __('components.family.select_family_type') }}</option>
                @foreach($familyTypes as $ft)
                    <option value="{{ $ft->id }}" {{ (string) $currentFamilyTypeId === (string) $ft->id ? 'selected' : '' }}>
                        {{ $ft->label }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.family.family_status') }}</label>
            <select name="{{ $nameFamilyStatus }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">{{ __('components.family.select_family_status') }}</option>
                @foreach($familyStatusOptions as $key => $label)
                    <option value="{{ $key }}" {{ (string) $currentFamilyStatus === (string) $key ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.family.family_values') }}</label>
            <select name="{{ $nameFamilyValues }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">{{ __('components.family.select_family_values') }}</option>
                @foreach($familyValuesOptions as $key => $label)
                    <option value="{{ $key }}" {{ (string) $currentFamilyValues === (string) $key ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
    {{-- Row 2: Family Income — full width of this box, one line (Annual, Exact, amount, currency) + privacy below --}}
    <div class="pt-4 border-t border-gray-200 dark:border-gray-600 w-full">
        <x-income-engine
            :label="__('components.family.family_income')"
            :namePrefix="$hasPrefix ? $namePrefix . '[family_income]' : 'family_income'"
            :profile="$profile"
            :currencies="$currencies ?? []"
            :privacy-enabled="true"
            :read-only="false"
            :errors="$errors ?? []"
        />
    </div>
</div>

