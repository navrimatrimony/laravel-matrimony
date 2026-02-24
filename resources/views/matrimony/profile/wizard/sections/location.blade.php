{{-- Phase-5B: Location - core city + Native & Residence + addresses (village free-type) --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Location</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <input type="hidden" name="country_id" id="wizard_country_id" value="{{ old('country_id', $profile->country_id) }}">
        <input type="hidden" name="state_id" id="wizard_state_id" value="{{ old('state_id', $profile->state_id) }}">
        <input type="hidden" name="district_id" id="wizard_district_id" value="{{ old('district_id', $profile->district_id) }}">
        <input type="hidden" name="taluka_id" id="wizard_taluka_id" value="{{ old('taluka_id', $profile->taluka_id) }}">
        <input type="hidden" name="city_id" id="wizard_city_id" value="{{ old('city_id', $profile->city_id) }}">
        <input type="hidden" name="work_city_id" id="wizard_work_city_id" value="{{ old('work_city_id', $profile->work_city_id) }}">
        <input type="hidden" name="work_state_id" id="wizard_work_state_id" value="{{ old('work_state_id', $profile->work_state_id) }}">
        <input type="hidden" name="native_city_id" id="wizard_native_city_id" value="{{ old('native_city_id', $profile->native_city_id) }}">
        <input type="hidden" name="native_taluka_id" id="wizard_native_taluka_id" value="{{ old('native_taluka_id', $profile->native_taluka_id) }}">
        <input type="hidden" name="native_district_id" id="wizard_native_district_id" value="{{ old('native_district_id', $profile->native_district_id) }}">
        <input type="hidden" name="native_state_id" id="wizard_native_state_id" value="{{ old('native_state_id', $profile->native_state_id) }}">
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search village or city (residence)</label>
            <input type="text" id="wizard_city_search" value="{{ old('wizard_city_display', $profile->city?->name ?? '') }}" placeholder="Type village / city / pincode" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <div id="wizard_city_results" class="border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-48 overflow-y-auto" style="display:none;"></div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 mt-2">Address line (optional)</label>
            <input type="text" name="address_line" value="{{ old('address_line', $profile->address_line ?? '') }}" maxlength="255" placeholder="e.g. Building, area, landmark" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Work location (optional)</label>
            <input type="text" id="wizard_work_city_search" value="{{ old('wizard_work_display', $workCityName ?? '') }}" placeholder="Type city / area for work" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <div id="wizard_work_city_results" class="border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-48 overflow-y-auto" style="display:none;"></div>
        </div>
        <div class="md:col-span-2 pt-2 border-t border-gray-200 dark:border-gray-600">
            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Native & Residence</h3>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Native place (optional)</label>
            <input type="text" id="wizard_native_city_search" value="{{ old('wizard_native_display', $nativePlaceDisplay ?? '') }}" placeholder="Type native place city / area" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
            <div id="wizard_native_city_results" class="border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-48 overflow-y-auto" style="display:none;"></div>
        </div>
    </div>
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Addresses (village / area)</h3>
        @php $addrRows = old('addresses', $profileAddresses ?? collect()); @endphp
        @foreach($addrRows as $idx => $row)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                <input type="hidden" name="addresses[{{ $idx }}][id]" value="{{ is_object($row) ? ($row->id ?? '') : ($row['id'] ?? '') }}">
                <input type="text" name="addresses[{{ $idx }}][address_type]" value="{{ is_object($row) ? ($row->address_type ?? 'current') : ($row['address_type'] ?? 'current') }}" placeholder="Type" class="rounded border px-3 py-2">
                <input type="hidden" name="addresses[{{ $idx }}][village_id]" value="{{ is_object($row) ? ($row->village_id ?? '') : ($row['village_id'] ?? '') }}">
                <input type="text" data-address-village-display placeholder="Village / Area (select from search)" value="{{ is_object($row) ? ($row->village?->name ?? '') : '' }}" class="rounded border px-3 py-2" readonly>
                <input type="text" name="addresses[{{ $idx }}][taluka]" value="{{ is_object($row) ? ($row->taluka ?? '') : ($row['taluka'] ?? '') }}" placeholder="Taluka" class="rounded border px-3 py-2">
                <input type="text" name="addresses[{{ $idx }}][district]" value="{{ is_object($row) ? ($row->district ?? '') : ($row['district'] ?? '') }}" placeholder="District" class="rounded border px-3 py-2">
                <input type="text" name="addresses[{{ $idx }}][state]" value="{{ is_object($row) ? ($row->state ?? '') : ($row['state'] ?? '') }}" placeholder="State" class="rounded border px-3 py-2">
                <input type="text" name="addresses[{{ $idx }}][country]" value="{{ is_object($row) ? ($row->country ?? '') : ($row['country'] ?? '') }}" placeholder="Country" class="rounded border px-3 py-2">
                <input type="text" name="addresses[{{ $idx }}][pin_code]" value="{{ is_object($row) ? ($row->pin_code ?? '') : ($row['pin_code'] ?? '') }}" placeholder="Pin" class="rounded border px-3 py-2 w-24">
            </div>
        @endforeach
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('wizard_city_search');
    var results = document.getElementById('wizard_city_results');
    var hiddenCountry = document.getElementById('wizard_country_id');
    var hiddenState = document.getElementById('wizard_state_id');
    var hiddenDistrict = document.getElementById('wizard_district_id');
    var hiddenTaluka = document.getElementById('wizard_taluka_id');
    var hiddenCity = document.getElementById('wizard_city_id');
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
                            if (hiddenCountry) hiddenCountry.value = item.country_id || '';
                            input.value = cityName;
                            results.style.display = 'none';
                        });
                        results.appendChild(div);
                    });
                    results.style.display = 'block';
                });
        }, 200);
    });
    input.addEventListener('focus', function() {
        if (input.value.trim().length >= 2 && results.innerHTML) results.style.display = 'block';
    });

    var workInput = document.getElementById('wizard_work_city_search');
    var workResults = document.getElementById('wizard_work_city_results');
    var hiddenWorkCity = document.getElementById('wizard_work_city_id');
    var hiddenWorkState = document.getElementById('wizard_work_state_id');
    if (workInput && workResults && hiddenWorkCity && hiddenWorkState) {
        var workDebounce = null;
        workInput.addEventListener('input', function() {
            clearTimeout(workDebounce);
            var q = workInput.value.trim();
            if (q.length < 2) { workResults.style.display = 'none'; return; }
            workDebounce = setTimeout(function() {
                fetch('/api/internal/location/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success || !data.data || data.data.length === 0) {
                            workResults.innerHTML = '<div class="p-2 text-gray-500">No matches</div>';
                            workResults.style.display = 'block';
                            return;
                        }
                        workResults.innerHTML = '';
                        data.data.forEach(function(item) {
                            var cityId = item.city_id || item.id || '';
                            var cityName = item.city_name || item.label || item.name || '';
                            var line = cityName + ', ' + (item.taluka_name || '') + ', ' + (item.district_name || '') + ', ' + (item.state_name || '');
                            var div = document.createElement('div');
                            div.className = 'p-2 border-b border-gray-200 dark:border-gray-600 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700';
                            div.textContent = line;
                            div.addEventListener('click', function() {
                                hiddenWorkCity.value = cityId;
                                hiddenWorkState.value = item.state_id || '';
                                workInput.value = cityName;
                                workResults.style.display = 'none';
                            });
                            workResults.appendChild(div);
                        });
                        workResults.style.display = 'block';
                    });
            }, 200);
        });
    }

    var nativeInput = document.getElementById('wizard_native_city_search');
    var nativeResults = document.getElementById('wizard_native_city_results');
    var hiddenNativeCity = document.getElementById('wizard_native_city_id');
    var hiddenNativeTaluka = document.getElementById('wizard_native_taluka_id');
    var hiddenNativeDistrict = document.getElementById('wizard_native_district_id');
    var hiddenNativeState = document.getElementById('wizard_native_state_id');
    if (nativeInput && nativeResults && hiddenNativeCity && hiddenNativeState) {
        var nativeDebounce = null;
        nativeInput.addEventListener('input', function() {
            clearTimeout(nativeDebounce);
            var q = nativeInput.value.trim();
            if (q.length < 2) { nativeResults.style.display = 'none'; return; }
            nativeDebounce = setTimeout(function() {
                fetch('/api/internal/location/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success || !data.data || data.data.length === 0) {
                            nativeResults.innerHTML = '<div class="p-2 text-gray-500">No matches</div>';
                            nativeResults.style.display = 'block';
                            return;
                        }
                        nativeResults.innerHTML = '';
                        data.data.forEach(function(item) {
                            var cityId = item.city_id || item.id || '';
                            var cityName = item.city_name || item.label || item.name || '';
                            var line = cityName + ', ' + (item.taluka_name || '') + ', ' + (item.district_name || '') + ', ' + (item.state_name || '');
                            var div = document.createElement('div');
                            div.className = 'p-2 border-b border-gray-200 dark:border-gray-600 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700';
                            div.textContent = line;
                            div.addEventListener('click', function() {
                                hiddenNativeCity.value = cityId;
                                hiddenNativeTaluka.value = item.taluka_id || '';
                                hiddenNativeDistrict.value = item.district_id || '';
                                hiddenNativeState.value = item.state_id || '';
                                nativeInput.value = cityName;
                                nativeResults.style.display = 'none';
                            });
                            nativeResults.appendChild(div);
                        });
                        nativeResults.style.display = 'block';
                    });
            }, 200);
        });
    }
});
</script>
