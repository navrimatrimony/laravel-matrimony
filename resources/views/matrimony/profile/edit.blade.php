@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <h1 class="text-2xl font-bold mb-6">
                    Edit Matrimony Profile
                </h1>

                @if ($errors->any())
                    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 text-sm">{{ $errors->first() }}</div>
                @endif

                <form method="POST"
      action="{{ route('matrimony.profile.update') }}"
      enctype="multipart/form-data">

                    @csrf

                    {{-- Day-18: Only show enabled and visible fields --}}
                    @php
                        $visibleFields = $visibleFields ?? [];
                        $enabledFields = $enabledFields ?? [];
                        $isVisible = fn($fieldKey) => in_array($fieldKey, $visibleFields, true);
                        $isEnabled = fn($fieldKey) => in_array($fieldKey, $enabledFields, true);
                    @endphp

                    <label>Full Name</label><br>
                    <input type="text" name="full_name" value="{{ $matrimonyProfile->full_name }}"><br><br>

                    @if ($isEnabled('date_of_birth') && $isVisible('date_of_birth'))
                    <label>Date of Birth</label><br>
                    <input type="date" name="date_of_birth" value="{{ $matrimonyProfile->date_of_birth }}"><br><br>
                    @endif

                    @if ($isEnabled('marital_status') && $isVisible('marital_status'))
                    <label>Marital Status</label><br>
                    <select name="marital_status" required>
                        <option value="">— Select —</option>
                        <option value="single" {{ old('marital_status', $matrimonyProfile->marital_status) === 'single' ? 'selected' : '' }}>Single</option>
                        <option value="divorced" {{ old('marital_status', $matrimonyProfile->marital_status) === 'divorced' ? 'selected' : '' }}>Divorced</option>
                        <option value="widowed" {{ old('marital_status', $matrimonyProfile->marital_status) === 'widowed' ? 'selected' : '' }}>Widowed</option>
                    </select><br><br>
                    @endif

                    @if ($isEnabled('education') && $isVisible('education'))
                    <label>Education</label><br>
                    <input type="text" name="education" value="{{ $matrimonyProfile->education }}"><br><br>
                    @endif

                    @if ($isEnabled('caste') && $isVisible('caste'))
                    <label>Caste</label><br>
                    <input type="text" name="caste" value="{{ $matrimonyProfile->caste }}"><br><br>
                    @endif

                    <label>Height (cm)</label><br>
                    <input type="number" name="height_cm" value="{{ old('height_cm', $matrimonyProfile->height_cm ?? '') }}" placeholder="170"><br>
                    @error('height_cm') <div class="text-danger">{{ $message }}</div> @enderror
                    <br>

                    <label>Contact Number</label><br>
                    <input type="text" name="contact_number" value="{{ old('contact_number', $matrimonyProfile->contact_number ?? '') }}" placeholder="+91 9876543210" maxlength="20"><br>
                    @error('contact_number') <div class="text-danger">{{ $message }}</div> @enderror
                    <br>

                    @if ($isEnabled('location') && $isVisible('location'))
                    <div style="margin-bottom: 20px;">
                        <label>Country *</label><br>
                        <select name="country_id" id="country_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select Country</option>
                            @foreach($countries ?? [] as $country)
                                <option value="{{ $country->id }}" {{ old('country_id', $matrimonyProfile->country_id) == $country->id ? 'selected' : '' }}>{{ $country->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label>State *</label><br>
                        <select name="state_id" id="state_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select State</option>
                            @foreach($states ?? [] as $state)
                                <option value="{{ $state->id }}" data-country_id="{{ $state->country_id }}" {{ old('state_id', $matrimonyProfile->state_id) == $state->id ? 'selected' : '' }}>{{ $state->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label>District</label><br>
                        <select name="district_id" id="district_id" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select District</option>
                            @foreach($districts ?? [] as $district)
                                <option value="{{ $district->id }}" data-state_id="{{ $district->state_id }}" {{ old('district_id', $matrimonyProfile->district_id) == $district->id ? 'selected' : '' }}>{{ $district->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label>Taluka</label><br>
                        <select name="taluka_id" id="taluka_id" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select Taluka</option>
                            @foreach($talukas ?? [] as $taluka)
                                <option value="{{ $taluka->id }}" data-district_id="{{ $taluka->district_id }}" {{ old('taluka_id', $matrimonyProfile->taluka_id) == $taluka->id ? 'selected' : '' }}>{{ $taluka->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label>City *</label><br>
                        <select name="city_id" id="city_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select City</option>
                            @foreach($cities ?? [] as $city)
                                <option value="{{ $city->id }}" data-taluka_id="{{ $city->taluka_id }}" {{ old('city_id', $matrimonyProfile->city_id) == $city->id ? 'selected' : '' }}>{{ $city->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    @if ($isEnabled('profile_photo') && $isVisible('profile_photo'))
                    <label>Profile Photo</label><br>
                    
                    {{-- Existing Profile Photo Preview --}}
                    @if ($matrimonyProfile->profile_photo)
                    <div style="margin-bottom:10px;">
                        <img
                            src="{{ asset('uploads/matrimony_photos/'.$matrimonyProfile->profile_photo) }}"
                            alt="Profile Photo"
                            style="width:120px; height:120px; object-fit:cover; border-radius:50%; border:1px solid #ccc;"
                        >
                    </div>
                    @endif

                    <input type="file" name="profile_photo"><br><br>
                    @endif

                    {{-- Phase-4: Extended fields (user-editable, no lock for owner) --}}
                    @php
                        $extendedFields = $extendedFields ?? collect();
                        $extendedValues = $extendedValues ?? [];
                    @endphp
                    @if ($extendedFields->count() > 0)
                    <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-600">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Additional details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            @foreach ($extendedFields as $field)
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $field->display_label ?? $field->field_key }}</label>
                                @if ($field->data_type === 'text')
                                    <input type="text" name="extended_fields[{{ $field->field_key }}]" value="{{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @elseif ($field->data_type === 'number')
                                    <input type="number" name="extended_fields[{{ $field->field_key }}]" value="{{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @elseif ($field->data_type === 'date')
                                    <input type="date" name="extended_fields[{{ $field->field_key }}]" value="{{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @elseif ($field->data_type === 'boolean')
                                    <select name="extended_fields[{{ $field->field_key }}]" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                        <option value="">—</option>
                                        <option value="1" {{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') == '1' ? 'selected' : '' }}>Yes</option>
                                        <option value="0" {{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') == '0' ? 'selected' : '' }}>No</option>
                                    </select>
                                @else
                                    <input type="text" name="extended_fields[{{ $field->field_key }}]" value="{{ old("extended_fields.{$field->field_key}", $extendedValues[$field->field_key] ?? '') }}" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                                @endif
                                @error("extended_fields.{$field->field_key}") <div class="text-danger text-sm mt-1">{{ $message }}</div> @enderror
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-600">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Marriage Timeline</h3>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">When do you plan to get married?</label>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">This is optional and only shown on your profile.</p>
                        <select name="serious_intent_id" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <option value="">Not specified</option>
                            @foreach($seriousIntents as $intent)
                                <option value="{{ $intent->id }}" {{ old('serious_intent_id', $matrimonyProfile->serious_intent_id) == $intent->id ? 'selected' : '' }}>
                                    {{ $intent->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

<button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-sm text-white tracking-wide hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition disabled:opacity-50 disabled:cursor-not-allowed mt-4">
                        Update Profile
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

@endsection

<script>
document.addEventListener('DOMContentLoaded', function() {
    const countrySelect = document.getElementById('country_id');
    const stateSelect = document.getElementById('state_id');
    const districtSelect = document.getElementById('district_id');
    const talukaSelect = document.getElementById('taluka_id');
    const citySelect = document.getElementById('city_id');

    function filterStates() {
        const countryId = countrySelect.value;
        Array.from(stateSelect.options).forEach(option => {
            if (option.value === '') {
                option.style.display = 'block';
            } else {
                option.style.display = option.dataset.country_id === countryId ? 'block' : 'none';
            }
        });
    }

    function filterDistricts() {
        const stateId = stateSelect.value;
        Array.from(districtSelect.options).forEach(option => {
            if (option.value === '') {
                option.style.display = 'block';
            } else {
                option.style.display = option.dataset.state_id === stateId ? 'block' : 'none';
            }
        });
    }

    function filterTalukas() {
        const districtId = districtSelect.value;
        Array.from(talukaSelect.options).forEach(option => {
            if (option.value === '') {
                option.style.display = 'block';
            } else {
                option.style.display = option.dataset.district_id === districtId ? 'block' : 'none';
            }
        });
    }

    function filterCities() {
        const talukaId = talukaSelect.value;
        Array.from(citySelect.options).forEach(option => {
            if (option.value === '') {
                option.style.display = 'block';
            } else {
                option.style.display = option.dataset.taluka_id === talukaId ? 'block' : 'none';
            }
        });
    }

    // Country change
    if (countrySelect) {
        countrySelect.addEventListener('change', function() {
            stateSelect.value = '';
            districtSelect.value = '';
            talukaSelect.value = '';
            citySelect.value = '';
            filterStates();
        });
    }

    // State change
    if (stateSelect) {
        stateSelect.addEventListener('change', function() {
            districtSelect.value = '';
            talukaSelect.value = '';
            citySelect.value = '';
            filterDistricts();
        });
    }

    // District change
    if (districtSelect) {
        districtSelect.addEventListener('change', function() {
            talukaSelect.value = '';
            citySelect.value = '';
            filterTalukas();
        });
    }

    // Taluka change
    if (talukaSelect) {
        talukaSelect.addEventListener('change', function() {
            citySelect.value = '';
            filterCities();
        });
    }

    // On page load, filter based on existing selections
    if (countrySelect && countrySelect.value) {
        filterStates();
    }
    if (stateSelect && stateSelect.value) {
        filterDistricts();
    }
    if (districtSelect && districtSelect.value) {
        filterTalukas();
    }
    if (talukaSelect && talukaSelect.value) {
        filterCities();
    }
});
</script>
