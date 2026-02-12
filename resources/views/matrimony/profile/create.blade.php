@extends('layouts.app')

@section('content')

<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">

                <h1 class="text-2xl font-bold mb-6">
                    Matrimony Profile Create
                </h1>

                <form method="POST" action="{{ route('matrimony.profile.store') }}">
    @csrf

                    {{-- Day-18: Only show enabled and visible fields --}}
                    @php
                        $visibleFields = $visibleFields ?? [];
                        $enabledFields = $enabledFields ?? [];
                        $isVisible = fn($fieldKey) => in_array($fieldKey, $visibleFields, true);
                        $isEnabled = fn($fieldKey) => in_array($fieldKey, $enabledFields, true);
                    @endphp

                    <label>Full Name</label><br>
                    <input type="text" name="full_name"><br><br>

                    @if ($isEnabled('date_of_birth') && $isVisible('date_of_birth'))
                    <label>Date of Birth</label><br>
                    <input type="date" name="date_of_birth"><br><br>
                    @endif

                    @if ($isEnabled('marital_status') && $isVisible('marital_status'))
                    <label>Marital Status</label><br>
                    <select name="marital_status" required>
                        <option value="">— Select —</option>
                        <option value="single" {{ old('marital_status') === 'single' ? 'selected' : '' }}>Single</option>
                        <option value="divorced" {{ old('marital_status') === 'divorced' ? 'selected' : '' }}>Divorced</option>
                        <option value="widowed" {{ old('marital_status') === 'widowed' ? 'selected' : '' }}>Widowed</option>
                    </select><br><br>
                    @endif

                    @if ($isEnabled('education') && $isVisible('education'))
                    <label>Education</label><br>
                    <input type="text" name="education"><br><br>
                    @endif

                    @if ($isEnabled('caste') && $isVisible('caste'))
                    <label>Caste</label><br>
                    <input type="text" name="caste"><br><br>
                    @endif

                    @if ($isEnabled('location') && $isVisible('location'))
                    <div style="margin-bottom: 20px;">
                        <label>Country *</label><br>
                        <select name="country_id" id="country_id" required style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select Country</option>
                            @foreach($countries ?? [] as $country)
                                <option value="{{ $country->id }}">{{ $country->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label>State *</label><br>
                        <select name="state_id" id="state_id" required disabled style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select State</option>
                            @foreach($states ?? [] as $state)
                                <option value="{{ $state->id }}" data-country_id="{{ $state->country_id }}">{{ $state->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label>District</label><br>
                        <select name="district_id" id="district_id" disabled style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select District</option>
                            @foreach($districts ?? [] as $district)
                                <option value="{{ $district->id }}" data-state_id="{{ $district->state_id }}">{{ $district->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label>Taluka</label><br>
                        <select name="taluka_id" id="taluka_id" disabled style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select Taluka</option>
                            @foreach($talukas ?? [] as $taluka)
                                <option value="{{ $taluka->id }}" data-district_id="{{ $taluka->district_id }}">{{ $taluka->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label>City *</label><br>
                        <select name="city_id" id="city_id" required disabled style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                            <option value="">Select City</option>
                            @foreach($cities ?? [] as $city)
                                <option value="{{ $city->id }}" data-taluka_id="{{ $city->taluka_id }}">{{ $city->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-600">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Marriage Timeline</h3>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">When do you plan to get married?</label>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">This is optional and only shown on your profile.</p>
                        <select name="serious_intent_id" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                            <option value="">Not specified</option>
                            @foreach($seriousIntents as $intent)
                                <option value="{{ $intent->id }}" {{ old('serious_intent_id') == $intent->id ? 'selected' : '' }}>
                                    {{ $intent->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" style="background-color: #4f46e5; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; margin-top: 20px;">
    Save Profile
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

    // Country change → filter states
    if (countrySelect) {
        countrySelect.addEventListener('change', function() {
            const countryId = this.value;
            stateSelect.disabled = !countryId;
            districtSelect.disabled = true;
            talukaSelect.disabled = true;
            citySelect.disabled = true;
            
            // Reset dependent dropdowns
            stateSelect.value = '';
            districtSelect.value = '';
            talukaSelect.value = '';
            citySelect.value = '';
            
            // Filter states
            Array.from(stateSelect.options).forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else {
                    option.style.display = option.dataset.country_id === countryId ? 'block' : 'none';
                }
            });
        });
    }

    // State change → filter districts
    if (stateSelect) {
        stateSelect.addEventListener('change', function() {
            const stateId = this.value;
            districtSelect.disabled = !stateId;
            talukaSelect.disabled = true;
            citySelect.disabled = true;
            
            // Reset dependent dropdowns
            districtSelect.value = '';
            talukaSelect.value = '';
            citySelect.value = '';
            
            // Filter districts
            Array.from(districtSelect.options).forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else {
                    option.style.display = option.dataset.state_id === stateId ? 'block' : 'none';
                }
            });
        });
    }

    // District change → filter talukas
    if (districtSelect) {
        districtSelect.addEventListener('change', function() {
            const districtId = this.value;
            talukaSelect.disabled = !districtId;
            citySelect.disabled = true;
            
            // Reset dependent dropdowns
            talukaSelect.value = '';
            citySelect.value = '';
            
            // Filter talukas
            Array.from(talukaSelect.options).forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else {
                    option.style.display = option.dataset.district_id === districtId ? 'block' : 'none';
                }
            });
        });
    }

    // Taluka change → filter cities
    if (talukaSelect) {
        talukaSelect.addEventListener('change', function() {
            const talukaId = this.value;
            citySelect.disabled = !talukaId;
            
            // Reset city
            citySelect.value = '';
            
            // Filter cities
            Array.from(citySelect.options).forEach(option => {
                if (option.value === '') {
                    option.style.display = 'block';
                } else {
                    option.style.display = option.dataset.taluka_id === talukaId ? 'block' : 'none';
                }
            });
        });
    }
});
</script>
