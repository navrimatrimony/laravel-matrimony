{{-- Alliance & Native Network — repeatable; surname + location hierarchy + notes. Separate from Relatives. --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Alliance & Native Network</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400">Add family surnames and their native locations. All fields except surname are optional.</p>

    <div id="alliance-container">
        @php
            $allianceRows = old('alliance_networks', $profileAllianceNetworks ?? collect());
            if (is_object($allianceRows)) { $allianceRows = $allianceRows->all(); }
            if (count($allianceRows) === 0) { $allianceRows = [[]]; }
        @endphp
        @foreach($allianceRows as $idx => $row)
            @php $r = is_object($row) ? (array) $row : (array) $row; $locDisplay = $r['location_display'] ?? ''; @endphp
            <div class="alliance-row mb-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
                <input type="hidden" name="alliance_networks[{{ $idx }}][id]" value="{{ $r['id'] ?? '' }}">
                <input type="hidden" name="alliance_networks[{{ $idx }}][city_id]" class="alliance-city-id" value="{{ $r['city_id'] ?? '' }}">
                <input type="hidden" name="alliance_networks[{{ $idx }}][taluka_id]" class="alliance-taluka-id" value="{{ $r['taluka_id'] ?? '' }}">
                <input type="hidden" name="alliance_networks[{{ $idx }}][district_id]" class="alliance-district-id" value="{{ $r['district_id'] ?? '' }}">
                <input type="hidden" name="alliance_networks[{{ $idx }}][state_id]" class="alliance-state-id" value="{{ $r['state_id'] ?? '' }}">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Surname</label>
                        <input type="text" name="alliance_networks[{{ $idx }}][surname]" value="{{ $r['surname'] ?? '' }}" placeholder="Surname" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location</label>
                        <input type="text" class="alliance-location-search w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" placeholder="Search city / area" value="{{ $locDisplay }}" data-row-idx="{{ $idx }}">
                        <div class="alliance-location-results hidden border border-t-0 border-gray-300 dark:border-gray-600 rounded-b max-h-40 overflow-y-auto"></div>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notes</label>
                    <textarea name="alliance_networks[{{ $idx }}][notes]" rows="2" placeholder="Notes" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ $r['notes'] ?? '' }}</textarea>
                </div>
            </div>
        @endforeach
    </div>

    <button type="button" id="add-alliance-btn" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-200 rounded font-medium text-sm">
        Add Alliance
    </button>
</div>

<script>
(function() {
    function bindAllianceSearch(input) {
        if (!input || input.dataset.bound === '1') return;
        var row = input.closest('.alliance-row');
        var resultsDiv = row.querySelector('.alliance-location-results');
        var hiddenCity = row.querySelector('.alliance-city-id');
        var hiddenTaluka = row.querySelector('.alliance-taluka-id');
        var hiddenDistrict = row.querySelector('.alliance-district-id');
        var hiddenState = row.querySelector('.alliance-state-id');
        if (!resultsDiv || !hiddenCity) return;
        input.dataset.bound = '1';
        var debounce = null;
        input.addEventListener('input', function() {
            clearTimeout(debounce);
            var q = input.value.trim();
            if (q.length < 2) { resultsDiv.classList.add('hidden'); resultsDiv.innerHTML = ''; return; }
            debounce = setTimeout(function() {
                fetch('/api/internal/location/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success || !data.data || data.data.length === 0) {
                            resultsDiv.innerHTML = '<div class="p-2 text-gray-500">No matches</div>';
                            resultsDiv.classList.remove('hidden');
                            return;
                        }
                        resultsDiv.innerHTML = '';
                        data.data.forEach(function(item) {
                            var cityId = item.city_id || item.id || '';
                            var cityName = item.city_name || item.label || item.name || '';
                            var line = cityName + ', ' + (item.taluka_name || '') + ', ' + (item.district_name || '') + ', ' + (item.state_name || '');
                            var div = document.createElement('div');
                            div.className = 'p-2 border-b border-gray-200 dark:border-gray-600 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700';
                            div.textContent = line;
                            div.addEventListener('click', function() {
                                hiddenCity.value = cityId;
                                hiddenTaluka.value = item.taluka_id || '';
                                hiddenDistrict.value = item.district_id || '';
                                hiddenState.value = item.state_id || '';
                                input.value = cityName;
                                resultsDiv.classList.add('hidden');
                            });
                            resultsDiv.appendChild(div);
                        });
                        resultsDiv.classList.remove('hidden');
                    });
            }, 200);
        });
    }
    document.querySelectorAll('.alliance-location-search').forEach(bindAllianceSearch);

    document.getElementById('add-alliance-btn')?.addEventListener('click', function() {
        var container = document.getElementById('alliance-container');
        var rows = container.querySelectorAll('.alliance-row');
        var last = rows[rows.length - 1];
        if (!last) return;
        var clone = last.cloneNode(true);
        var newIdx = rows.length;
        clone.querySelectorAll('input, textarea').forEach(function(el) {
            if (el.name) {
                el.name = el.name.replace(/alliance_networks\[\d+\]/, 'alliance_networks[' + newIdx + ']');
            }
            if (el.classList.contains('alliance-location-search')) {
                el.value = '';
                el.removeAttribute('data-bound');
            } else if (el.type !== 'hidden') {
                el.value = '';
            }
        });
        clone.querySelector('input[name*="[id]"]').value = '';
        clone.querySelector('.alliance-city-id').value = '';
        clone.querySelector('.alliance-taluka-id').value = '';
        clone.querySelector('.alliance-district-id').value = '';
        clone.querySelector('.alliance-state-id').value = '';
        clone.querySelector('.alliance-location-results').innerHTML = '';
        clone.querySelector('.alliance-location-results').classList.add('hidden');
        container.appendChild(clone);
        bindAllianceSearch(clone.querySelector('.alliance-location-search'));
    });
})();
</script>
