{{-- Alliance & Native Network — repeatable; surname + location hierarchy + notes. Uses reusable location-typeahead component. --}}
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
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Surname</label>
                        <input type="text" name="alliance_networks[{{ $idx }}][surname]" value="{{ $r['surname'] ?? '' }}" placeholder="Surname" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    </div>
                    <div>
                        <x-profile.location-typeahead
                            context="alliance"
                            namePrefix="alliance_networks[{{ $idx }}]"
                            :value="$locDisplay"
                            placeholder="Search city / area"
                            label="Location"
                            :data-city-id="$r['city_id'] ?? ''"
                            :data-taluka-id="$r['taluka_id'] ?? ''"
                            :data-district-id="$r['district_id'] ?? ''"
                            :data-state-id="$r['state_id'] ?? ''"
                        />
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
            if (el.classList.contains('location-typeahead-input')) {
                el.value = '';
            } else if (el.type !== 'hidden') {
                el.value = '';
            }
        });
        clone.querySelectorAll('.location-hidden-city, .location-hidden-taluka, .location-hidden-district, .location-hidden-state').forEach(function(h) {
            h.value = '';
        });
        clone.querySelector('.location-typeahead-wrapper').removeAttribute('data-bound');
        var resultsEl = clone.querySelector('.location-typeahead-results');
        if (resultsEl) { resultsEl.innerHTML = ''; resultsEl.classList.add('hidden'); }
        container.appendChild(clone);
        if (window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init();
    });
})();
</script>
