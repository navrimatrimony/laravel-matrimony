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
    {{-- Marital status is only in Marriages section (MaritalEngine). --}}
    <x-profile.religion-caste-selector :profile="$profile" />
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        {{-- Height: same width as one religion/caste column (1/3). --}}
        <div>
            <x-profile.height-picker :value="$profile->height_cm ?? null" />
        </div>
        {{-- Point 4.1: Primary contact — canonical editable in Contacts section. On full page show read-only here; on basic-info step show editable. --}}
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Primary contact (mobile) @if(($currentSection ?? '') !== 'full')<span class="text-red-500">*</span>@endif</label>
            @if(($currentSection ?? '') === 'full')
                <p class="text-gray-700 dark:text-gray-300 py-2">{{ $primaryContactPhone ? e($primaryContactPhone) : '—' }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Edit in <strong>Contacts</strong> section below.</p>
            @else
                <x-profile.contact-field
                    name="primary_contact_number"
                    :value="$primaryContactPhone ?? ''"
                    label=""
                    placeholder="10-digit number"
                    :showCountryCode="true"
                    :showWhatsapp="false"
                    :required="true"
                    inputClass="flex-1 min-w-0"
                />
            @endif
        </div>
    </div>
</div>
