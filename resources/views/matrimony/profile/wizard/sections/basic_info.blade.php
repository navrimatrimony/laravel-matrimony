{{-- Shaadi.com-aligned Step 1: Name, DOB, Gender, Religion, Caste, Marital status, Height, Primary contact. Dependent: Marriages / Children. Point 3: parent-child adjacent, no reload/fetch — Alpine show/hide only. --}}
<div class="space-y-4">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Basic info</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Name <span class="text-red-500">*</span></label>
            <input type="text" name="full_name" value="{{ old('full_name', $profile->full_name) }}" required class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date of Birth <span class="text-red-500">*</span></label>
            <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $profile->date_of_birth ? \Carbon\Carbon::parse($profile->date_of_birth)->format('Y-m-d') : '') }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender <span class="text-red-500">*</span></label>
            <select name="gender_id" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" required>
                <option value="">Select Gender</option>
                @foreach($genders ?? [] as $gender)
                    <option value="{{ $gender->id }}" {{ old('gender_id', $profile->gender_id ?? '') == $gender->id ? 'selected' : '' }}>{{ $gender->key === 'male' ? '👨' : '👩' }} {{ $gender->label }}</option>
                @endforeach
            </select>
        </div>
    </div>
    {{-- Point 4.2: Marital status only here; marriage details + children canonical in Marriages section (see full.blade / marriages.blade). --}}
    @php
        $initialMaritalId = old('marital_status_id', $profile->marital_status_id ?? '');
        $maritalLabel = ($maritalStatuses ?? collect())->firstWhere('id', $initialMaritalId)?->label ?? '';
        $firstMarriage = ($profileMarriages ?? collect())->first();
    @endphp
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marital Status <span class="text-red-500">*</span></label>
        <select id="wizard_marital_status_id" name="marital_status_id"
            class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" required>
            <option value="">Select Marital Status</option>
            @foreach($maritalStatuses ?? [] as $status)
                <option value="{{ $status->id }}" {{ $initialMaritalId == $status->id ? 'selected' : '' }}>💍 {{ $status->label }}</option>
            @endforeach
        </select>
        @if($maritalLabel)
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Summary: {{ $maritalLabel }}@if($firstMarriage && $firstMarriage->marriage_year) — Marriage year {{ $firstMarriage->marriage_year }}@endif</p>
        @endif
    </div>
    <x-profile.religion-caste-selector :profile="$profile" />
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Height (cm)</label>
            <input type="number" name="height_cm" value="{{ old('height_cm', $profile->height_cm) }}" min="50" max="250" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        {{-- Point 4.1: Primary contact — canonical editable in Contacts section. On full page show read-only here; on basic-info step show editable. --}}
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Primary contact (mobile) @if(($currentSection ?? '') !== 'full')<span class="text-red-500">*</span>@endif</label>
            @if(($currentSection ?? '') === 'full')
                <p class="text-gray-700 dark:text-gray-300 py-2">{{ $primaryContactPhone ? e($primaryContactPhone) : '—' }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Edit in <strong>Contacts</strong> section below.</p>
            @else
                <input type="text" name="primary_contact_number" value="{{ old('primary_contact_number', $primaryContactPhone ?? '') }}" placeholder="Mobile number" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" required>
            @endif
        </div>
    </div>
</div>
