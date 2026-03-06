@props([
    // When used in wizard/admin, pass MatrimonyProfile instance.
    'profile' => null,
    // When used in intake, pass associative array of current core values.
    'values' => [],
    // Optional name prefix for snapshot-style forms (e.g. 'snapshot[core]').
    'namePrefix' => '',
])
@php
    // Canonical option sets (keys stored in DB, labels shown in UI).
    $familyStatusOptions = [
        'simple' => 'Simple',
        'middle_class' => 'Middle Class',
        'upper_middle_class' => 'Upper Middle Class',
        'affluent' => 'Affluent',
    ];
    $familyValuesOptions = [
        'traditional' => 'Traditional',
        'moderate' => 'Moderate',
        'modern' => 'Modern',
    ];
    $familyIncomeOptions = [
        'not_disclosed' => 'Not Disclosed',
        'below_2_lakh' => 'Below 2 Lakh',
        '2_5_lakh' => '2–5 Lakh',
        '5_10_lakh' => '5–10 Lakh',
        '10_20_lakh' => '10–20 Lakh',
        '20_50_lakh' => '20–50 Lakh',
        '50_plus_lakh' => '50+ Lakh',
    ];

    $hasPrefix = $namePrefix !== '';

    // Resolve field names depending on context.
    $nameFamilyTypeId = $hasPrefix ? $namePrefix . '[family_type_id]' : 'family_type_id';
    $nameFamilyStatus = $hasPrefix ? $namePrefix . '[family_status]' : 'family_status';
    $nameFamilyValues = $hasPrefix ? $namePrefix . '[family_values]' : 'family_values';
    $nameFamilyAnnualIncome = $hasPrefix ? $namePrefix . '[family_annual_income]' : 'family_annual_income';

    // Resolve current values: intake-style values take precedence, then old()/profile when no prefix.
    if ($hasPrefix) {
        $currentFamilyTypeId = $values['family_type_id'] ?? null;
        $currentFamilyStatus = $values['family_status'] ?? null;
        $currentFamilyValues = $values['family_values'] ?? null;
        $currentFamilyAnnualIncome = $values['family_annual_income'] ?? null;
    } else {
        $currentFamilyTypeId = old('family_type_id', $profile->family_type_id ?? null);
        $currentFamilyStatus = old('family_status', $profile->family_status ?? null);
        $currentFamilyValues = old('family_values', $profile->family_values ?? null);
        $currentFamilyAnnualIncome = old('family_annual_income', $profile->family_annual_income ?? null);
    }

    // Master family types from controller (wizard) or lazy-load (other contexts).
    $familyTypes = $familyTypes ?? ($familyTypes ?? \App\Models\MasterFamilyType::where('is_active', true)->get());
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 border-2 border-rose-500 dark:border-rose-400 rounded-lg p-4">
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Family Type</label>
        <select name="{{ $nameFamilyTypeId }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <option value="">Select Family Type</option>
            @foreach($familyTypes as $ft)
                <option value="{{ $ft->id }}" {{ (string) $currentFamilyTypeId === (string) $ft->id ? 'selected' : '' }}>
                    {{ $ft->label }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Family Status</label>
        <select name="{{ $nameFamilyStatus }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <option value="">Select Family Status</option>
            @foreach($familyStatusOptions as $key => $label)
                <option value="{{ $key }}" {{ (string) $currentFamilyStatus === (string) $key ? 'selected' : '' }}>
                    {{ $label }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Family Values</label>
        <select name="{{ $nameFamilyValues }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <option value="">Select Family Values</option>
            @foreach($familyValuesOptions as $key => $label)
                <option value="{{ $key }}" {{ (string) $currentFamilyValues === (string) $key ? 'selected' : '' }}>
                    {{ $label }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Family Annual Income</label>
        <select name="{{ $nameFamilyAnnualIncome }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <option value="">Select Family Annual Income</option>
            @foreach($familyIncomeOptions as $key => $label)
                <option value="{{ $key }}" {{ (string) $currentFamilyAnnualIncome === (string) $key ? 'selected' : '' }}>
                    {{ $label }}
                </option>
            @endforeach
        </select>
    </div>
</div>

