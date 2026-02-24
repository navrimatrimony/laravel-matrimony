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

                    @if ($isEnabled('marital_status_id') && $isVisible('marital_status_id'))
                    <label>Marital Status</label><br>
                    <select name="marital_status_id" class="form-select" required>
                        <option value="">Select Marital Status</option>
                        @foreach($maritalStatuses ?? [] as $status)
                            <option value="{{ $status->id }}" {{ old('marital_status_id', $profile->marital_status_id ?? '') == $status->id ? 'selected' : '' }}>💍 {{ $status->label }}</option>
                        @endforeach
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
                    <input type="hidden" name="country_id" id="hidden_country_id">
                    <input type="hidden" name="state_id" id="hidden_state_id">
                    <input type="hidden" name="district_id" id="hidden_district_id">
                    <input type="hidden" name="taluka_id" id="hidden_taluka_id">
                    <input type="hidden" name="city_id" id="hidden_city_id">

                    <div id="city-autocomplete-wrapper" style="margin-bottom:20px;">
                        <label>Search village / city</label><br>
                        <input type="text" id="city_search_input"
                            placeholder="Type village / city / pincode"
                            style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        <div id="city_search_results" style="border:1px solid #ccc; border-top:none; display:none; max-height:200px; overflow-y:auto;"></div>
                        <div id="detected_context_container"
                            style="display:none; margin-top:10px; padding:10px; background:#eef2ff; border-radius:6px;">
                        </div>
                        <button type="button" id="city_suggest_btn"
                            style="margin-top:8px; display:none; background:#f59e0b; color:white; padding:8px 12px; border:none; border-radius:4px;">
                            Suggest this location
                        </button>
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
    const citySearchInput = document.getElementById('city_search_input');
    const citySearchResults = document.getElementById('city_search_results');
    const citySuggestBtn = document.getElementById('city_suggest_btn');
    const detectedContextContainer = document.getElementById('detected_context_container');
    const hiddenCountryId = document.getElementById('hidden_country_id');
    const hiddenStateId = document.getElementById('hidden_state_id');
    const hiddenDistrictId = document.getElementById('hidden_district_id');
    const hiddenTalukaId = document.getElementById('hidden_taluka_id');
    const hiddenCityId = document.getElementById('hidden_city_id');

    const talukasByDistrict = @json($talukasByDistrict ?? []);
    const districtsByState = @json($districtsByState ?? []);
    const stateIdToCountryId = @json($stateIdToCountryId ?? []);

    var districtIdToName = {};
    var districtIdToStateId = {};
    var talukaIdToDistrictId = {};
    (function() {
        for (const stateId in districtsByState) {
            (districtsByState[stateId] || []).forEach(function(d) {
                districtIdToName[d.id] = d.name || '';
                districtIdToStateId[d.id] = stateId;
            });
        }
        for (const districtId in talukasByDistrict) {
            (talukasByDistrict[districtId] || []).forEach(function(t) {
                talukaIdToDistrictId[t.id] = districtId;
            });
        }
    })();

    let lastContextDetected = null;
    let lastSearchQuery = '';
    let lastNoMatch = false;
    let lastCanSuggest = false;

    function setHiddenLocation(countryId, stateId, districtId, talukaId, cityId) {
        if (hiddenCountryId && countryId !== undefined) hiddenCountryId.value = countryId || '';
        if (hiddenStateId && stateId !== undefined) hiddenStateId.value = stateId || '';
        if (hiddenDistrictId && districtId !== undefined) hiddenDistrictId.value = districtId || '';
        if (hiddenTalukaId && talukaId !== undefined) hiddenTalukaId.value = talukaId || '';
        if (hiddenCityId && cityId !== undefined) hiddenCityId.value = cityId || '';
    }

    function escapeRegex(s) {
        return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function renderNoContextTalukaPicker() {
        if (!detectedContextContainer) return;
        detectedContextContainer.style.display = 'block';
        var html = '<label style="display:block; margin-top:8px;">Select taluka (grouped by district)</label><select id="no_context_taluka_picker" style="width:100%; padding:6px; margin-top:4px;">';
        html += '<option value="">— Select taluka —</option>';
        var districtIds = Object.keys(talukasByDistrict);
        districtIds.sort(function(a, b) {
            var na = districtIdToName[a] || '';
            var nb = districtIdToName[b] || '';
            return na.localeCompare(nb);
        });
        districtIds.forEach(function(districtId) {
            var districtName = districtIdToName[districtId] || ('District ' + districtId);
            html += '<optgroup label="' + (districtName.replace(/"/g, '&quot;')) + '">';
            (talukasByDistrict[districtId] || []).forEach(function(t) {
                html += '<option value="' + t.id + '">' + (t.name || '').replace(/</g, '&lt;') + '</option>';
            });
            html += '</optgroup>';
        });
        html += '</select>';
        detectedContextContainer.innerHTML = html;

        var picker = document.getElementById('no_context_taluka_picker');
        if (picker) {
            picker.addEventListener('change', function() {
                var talukaId = this.value;
                setHiddenLocation(undefined, undefined, undefined, talukaId, undefined);
                if (talukaId) {
                    var districtId = talukaIdToDistrictId[talukaId];
                    var stateId = districtId ? districtIdToStateId[districtId] : '';
                    var countryId = stateId ? (stateIdToCountryId[stateId] || '') : '';
                    setHiddenLocation(countryId, stateId, districtId, undefined, undefined);
                }
                updateSuggestButtonVisibility();
            });
        }
    }

    function renderContextAndPickers(context) {
        if (!detectedContextContainer) return;
        detectedContextContainer.style.display = 'block';
        const ctx = context;
        let html = '';
        if (ctx.district_id !== undefined && ctx.district_name) {
            html += 'Detected District: ' + (ctx.district_name || '') + '<br>State: ' + (ctx.state_name || '') + '<br>';
            const talukas = talukasByDistrict[String(ctx.district_id)] || [];
            if (talukas.length > 0) {
                html += '<label style="display:block; margin-top:8px;">Select taluka</label><select id="context_taluka_picker" style="width:100%; padding:6px; margin-top:4px;">';
                html += '<option value="">— Select taluka —</option>';
                talukas.forEach(function(t) {
                    html += '<option value="' + t.id + '">' + (t.name || '') + '</option>';
                });
                html += '</select>';
            }
        } else if (ctx.state_id !== undefined && ctx.state_name) {
            html += 'Detected State: ' + (ctx.state_name || '') + '<br>';
            const districts = districtsByState[String(ctx.state_id)] || [];
            if (districts.length > 0) {
                html += '<label style="display:block; margin-top:8px;">Select district</label><select id="context_district_picker" style="width:100%; padding:6px; margin-top:4px;">';
                html += '<option value="">— Select district —</option>';
                districts.forEach(function(d) {
                    html += '<option value="' + d.id + '">' + (d.name || '') + '</option>';
                });
                html += '</select>';
                html += '<div id="context_taluka_wrap" style="margin-top:8px; display:none;"></div>';
            }
        }
        detectedContextContainer.innerHTML = html;

        const talukaPicker = document.getElementById('context_taluka_picker');
        if (talukaPicker) {
            talukaPicker.addEventListener('change', function() {
                setHiddenLocation(undefined, undefined, undefined, this.value, undefined);
                updateSuggestButtonVisibility();
            });
        }

        const districtPicker = document.getElementById('context_district_picker');
        if (districtPicker) {
            districtPicker.addEventListener('change', function() {
                const did = this.value;
                setHiddenLocation(undefined, undefined, did, '', undefined);
                const wrap = document.getElementById('context_taluka_wrap');
                if (wrap) {
                    wrap.style.display = did ? 'block' : 'none';
                    wrap.innerHTML = '';
                    if (did) {
                        const talukas = talukasByDistrict[String(did)] || [];
                        let thtml = '<label style="display:block;">Select taluka</label><select id="context_taluka_picker_state" style="width:100%; padding:6px; margin-top:4px;"><option value="">— Select taluka —</option>';
                        talukas.forEach(function(t) {
                            thtml += '<option value="' + t.id + '">' + (t.name || '') + '</option>';
                        });
                        thtml += '</select>';
                        wrap.innerHTML = thtml;
                        const tp = document.getElementById('context_taluka_picker_state');
                        if (tp) tp.addEventListener('change', function() {
                            setHiddenLocation(undefined, undefined, undefined, this.value, undefined);
                            updateSuggestButtonVisibility();
                        });
                    }
                }
                updateSuggestButtonVisibility();
            });
        }
    }

    function updateSuggestButtonVisibility() {
        if (!citySuggestBtn || !lastNoMatch || !lastCanSuggest || lastSearchQuery.length < 3) {
            citySuggestBtn.style.display = 'none';
            return;
        }
        const hasDistrict = lastContextDetected && lastContextDetected.district_id;
        const hasStateOnly = lastContextDetected && lastContextDetected.state_id && !lastContextDetected.district_id;
        const talukaVal = hiddenTalukaId ? hiddenTalukaId.value : '';
        const districtVal = hiddenDistrictId ? hiddenDistrictId.value : '';
        if (hasDistrict) {
            citySuggestBtn.style.display = talukaVal ? 'inline-block' : 'none';
        } else if (hasStateOnly) {
            citySuggestBtn.style.display = (districtVal && talukaVal) ? 'inline-block' : 'none';
        } else {
            citySuggestBtn.style.display = talukaVal ? 'inline-block' : 'none';
        }
    }

    if (citySearchInput) {

        citySearchInput.addEventListener('input', function() {
            const query = this.value.trim();
            citySearchResults.innerHTML = '';
            citySuggestBtn.style.display = 'none';

            if (!query || query.length < 2) {
                citySearchResults.style.display = 'none';
                if (detectedContextContainer) detectedContextContainer.style.display = 'none';
                lastContextDetected = null;
                setHiddenLocation('', '', '', '', '');
                return;
            }

            fetch('/api/internal/location/search?q=' + encodeURIComponent(query), {
                headers: { 'Accept': 'application/json' }
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;

                lastSearchQuery = query;
                lastNoMatch = !!data.no_match;
                lastCanSuggest = !!data.can_suggest;
                lastContextDetected = data.context_detected || null;

                if (data.context_detected) {
                    setHiddenLocation(
                        data.context_detected.country_id || '',
                        data.context_detected.state_id || '',
                        data.context_detected.district_id || '',
                        '',
                        ''
                    );
                    renderContextAndPickers(data.context_detected);
                } else if (data.no_match && data.can_suggest) {
                    renderNoContextTalukaPicker();
                } else {
                    if (detectedContextContainer) detectedContextContainer.style.display = 'none';
                }

                if (data.data && data.data.length > 0) {
                    citySearchResults.style.display = 'block';
                    data.data.forEach(item => {
                        const div = document.createElement('div');
                        div.style.padding = '6px 10px';
                        div.style.cursor = 'pointer';
                        div.style.borderBottom = '1px solid #eee';
                        div.textContent = item.city_name + ', ' + (item.taluka_name || '') + ', ' + (item.district_name || '') + ', ' + (item.state_name || '');

                        div.addEventListener('click', function() {
                            citySearchInput.value = item.city_name || '';
                            citySearchResults.style.display = 'none';
                            citySuggestBtn.style.display = 'none';
                            setHiddenLocation(
                                item.country_id || '',
                                item.state_id || '',
                                item.district_id || '',
                                item.taluka_id || '',
                                item.city_id || ''
                            );
                        });

                        citySearchResults.appendChild(div);
                    });
                } else {
                    citySearchResults.style.display = 'none';
                    updateSuggestButtonVisibility();
                }
            });
        });

        citySuggestBtn.addEventListener('click', function() {
            const talukaVal = hiddenTalukaId ? hiddenTalukaId.value : '';
            if (!talukaVal) return;
            let name = citySearchInput.value.trim();
            if (!name) return;

            if (lastContextDetected) {
                if (lastContextDetected.district_name) {
                    name = name.replace(new RegExp('\\s*' + escapeRegex(lastContextDetected.district_name) + '\\s*$', 'i'), '');
                }
                if (lastContextDetected.state_name) {
                    name = name.replace(new RegExp('\\s*' + escapeRegex(lastContextDetected.state_name) + '\\s*$', 'i'), '');
                }
                name = name.trim();
            }
            if (!name) return;

            const meta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = meta ? meta.getAttribute('content') : '';

            fetch('/api/internal/location/suggest', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    suggested_name: name,
                    country_id: hiddenCountryId ? hiddenCountryId.value : '',
                    state_id: hiddenStateId ? hiddenStateId.value : '',
                    district_id: hiddenDistrictId ? hiddenDistrictId.value : '',
                    taluka_id: hiddenTalukaId ? hiddenTalukaId.value : '',
                    suggestion_type: 'village'
                })
            })
            .then(res => {
                if (res.status === 401) {
                    alert('Please login to suggest a location.');
                    return null;
                }
                return res.json();
            })
            .then(data => {
                if (data === null) return;
                if (data && data.success) {
                    alert('Suggestion submitted for admin approval.');
                    citySuggestBtn.style.display = 'none';
                } else {
                    alert(data && data.message ? data.message : 'Suggestion failed.');
                }
            });
        });
    }
});
</script>
