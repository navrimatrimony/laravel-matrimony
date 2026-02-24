{{-- Phase-5B: Basic info — full_name, gender, DOB, religion, caste, sub_caste, marital_status, height, primary contact, serious_intent --}}
<div class="space-y-4">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Basic info</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Full Name <span class="text-red-500">*</span></label>
            <input type="text" name="full_name" value="{{ old('full_name', $profile->full_name) }}" required class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
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
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date of Birth</label>
            <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $profile->date_of_birth ? \Carbon\Carbon::parse($profile->date_of_birth)->format('Y-m-d') : '') }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Birth time</label>
            <input type="text" name="birth_time" value="{{ old('birth_time', $profile->birth_time ?? '') }}" maxlength="20" placeholder="HH:MM AM/PM" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
    </div>
    <x-profile.religion-caste-selector :profile="$profile" />
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marital Status <span class="text-red-500">*</span></label>
            <select name="marital_status_id" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" required>
                <option value="">Select Marital Status</option>
                @foreach($maritalStatuses ?? [] as $status)
                    <option value="{{ $status->id }}" {{ old('marital_status_id', $profile->marital_status_id ?? '') == $status->id ? 'selected' : '' }}>💍 {{ $status->label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Height (cm)</label>
            <input type="number" name="height_cm" value="{{ old('height_cm', $profile->height_cm) }}" min="50" max="250" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Primary contact number</label>
            <input type="text" name="primary_contact_number" value="{{ old('primary_contact_number', $primaryContactPhone ?? '') }}" placeholder="Mobile" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">When do you plan to get married?</label>
            <select name="serious_intent_id" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <option value="">Not specified</option>
                @foreach($seriousIntents ?? [] as $intent)
                    <option value="{{ $intent->id }}" {{ old('serious_intent_id', $profile->serious_intent_id) == $intent->id ? 'selected' : '' }}>{{ $intent->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
        <h3 class="text-base font-medium text-gray-800 dark:text-gray-200 mb-3">Birth Place</h3>
        <input type="hidden" name="birth_city_id" id="wizard_birth_city_id" value="{{ old('birth_city_id', $profile->birth_city_id) }}">
        <input type="hidden" name="birth_taluka_id" id="wizard_birth_taluka_id" value="{{ old('birth_taluka_id', $profile->birth_taluka_id) }}">
        <input type="hidden" name="birth_district_id" id="wizard_birth_district_id" value="{{ old('birth_district_id', $profile->birth_district_id) }}">
        <input type="hidden" name="birth_state_id" id="wizard_birth_state_id" value="{{ old('birth_state_id', $profile->birth_state_id) }}">
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search birth place (city / area)</label>
            <input type="text" id="wizard_birth_city_search" value="{{ old('wizard_birth_display', $birthPlaceDisplay ?? '') }}" placeholder="Type village / city / pincode" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <div id="wizard_birth_city_results" class="border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-48 overflow-y-auto" style="display:none;"></div>
        </div>
    </div>

    <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
        <h3 class="text-base font-medium text-gray-800 dark:text-gray-200 mb-3">Physical Details</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Weight (kg)</label>
                <input type="number" name="weight_kg" value="{{ old('weight_kg', $profile->weight_kg) }}" step="0.1" min="0" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Complexion</label>
                <select name="complexion_id" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">Select Complexion</option>
                    @foreach($complexions ?? [] as $c)
                        <option value="{{ $c->id }}" {{ old('complexion_id', $profile->complexion_id) == $c->id ? 'selected' : '' }}>{{ $c->label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Physical Build</label>
                <select name="physical_build_id" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">Select Physical Build</option>
                    @foreach($physicalBuilds ?? [] as $pb)
                        <option value="{{ $pb->id }}" {{ old('physical_build_id', $profile->physical_build_id) == $pb->id ? 'selected' : '' }}>{{ $pb->label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Blood Group</label>
                <select name="blood_group_id" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">Select Blood Group</option>
                    @foreach($bloodGroups ?? [] as $bg)
                        <option value="{{ $bg->id }}" {{ old('blood_group_id', $profile->blood_group_id) == $bg->id ? 'selected' : '' }}>{{ $bg->label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('wizard_birth_city_search');
    var results = document.getElementById('wizard_birth_city_results');
    var hiddenCity = document.getElementById('wizard_birth_city_id');
    var hiddenTaluka = document.getElementById('wizard_birth_taluka_id');
    var hiddenDistrict = document.getElementById('wizard_birth_district_id');
    var hiddenState = document.getElementById('wizard_birth_state_id');
    if (!input || !results) return;
    var debounce = null;
    input.addEventListener('input', function() {
        clearTimeout(debounce);
        var q = input.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        debounce = setTimeout(function() {
            fetch('/api/internal/location/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.data || data.data.length === 0) {
                        results.innerHTML = '<div class="p-2 text-gray-500">No matches</div>';
                        results.style.display = 'block';
                        return;
                    }
                    results.innerHTML = '';
                    data.data.forEach(function(item) {
                        var cityId = item.city_id || item.id || '';
                        var cityName = item.city_name || item.label || item.name || '';
                        var line = cityName + ', ' + (item.taluka_name || '') + ', ' + (item.district_name || '') + ', ' + (item.state_name || '');
                        var div = document.createElement('div');
                        div.className = 'p-2 border-b border-gray-200 dark:border-gray-600 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700';
                        div.textContent = line;
                        div.addEventListener('click', function() {
                            if (hiddenCity) hiddenCity.value = cityId;
                            if (hiddenTaluka) hiddenTaluka.value = item.taluka_id || '';
                            if (hiddenDistrict) hiddenDistrict.value = item.district_id || '';
                            if (hiddenState) hiddenState.value = item.state_id || '';
                            input.value = cityName;
                            results.style.display = 'none';
                        });
                        results.appendChild(div);
                    });
                    results.style.display = 'block';
                });
        }, 200);
    });
});
</script>
