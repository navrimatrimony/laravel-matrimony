{{-- Siblings — repeatable. Does not replace brothers_count / sisters_count. No required fields. --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Siblings</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400">Add sibling details. Brothers/sisters count above is separate. All fields optional.</p>

    <div id="siblings-container">
        @php
            $siblingRows = old('siblings', $profileSiblings ?? collect());
            if (is_object($siblingRows)) { $siblingRows = $siblingRows->all(); }
            if (count($siblingRows) === 0) { $siblingRows = [[]]; }
        @endphp
        @foreach($siblingRows as $idx => $row)
            @php $r = is_object($row) ? (array) $row : (array) $row; @endphp
            <div class="sibling-row mb-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
                <input type="hidden" name="siblings[{{ $idx }}][id]" value="{{ $r['id'] ?? '' }}">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender</label>
                        <select name="siblings[{{ $idx }}][gender]" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                            <option value="">— Select —</option>
                            <option value="male" {{ ($r['gender'] ?? '') === 'male' ? 'selected' : '' }}>Male</option>
                            <option value="female" {{ ($r['gender'] ?? '') === 'female' ? 'selected' : '' }}>Female</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marital status</label>
                        <select name="siblings[{{ $idx }}][marital_status]" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                            <option value="">— Select —</option>
                            <option value="unmarried" {{ ($r['marital_status'] ?? '') === 'unmarried' ? 'selected' : '' }}>Unmarried</option>
                            <option value="married" {{ ($r['marital_status'] ?? '') === 'married' ? 'selected' : '' }}>Married</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Occupation</label>
                        <input type="text" name="siblings[{{ $idx }}][occupation]" value="{{ $r['occupation'] ?? '' }}" placeholder="Occupation" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">City</label>
                        <select name="siblings[{{ $idx }}][city_id]" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                            <option value="">— Select —</option>
                            @foreach($cities ?? [] as $city)
                                <option value="{{ $city->id }}" {{ ($r['city_id'] ?? '') == $city->id ? 'selected' : '' }}>{{ $city->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notes</label>
                    <textarea name="siblings[{{ $idx }}][notes]" rows="2" placeholder="Notes" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ $r['notes'] ?? '' }}</textarea>
                </div>
            </div>
        @endforeach
    </div>

    <button type="button" id="add-sibling-btn" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-200 rounded font-medium text-sm">
        Add Sibling
    </button>
</div>

<script>
(function() {
    document.getElementById('add-sibling-btn')?.addEventListener('click', function() {
        var container = document.getElementById('siblings-container');
        var rows = container.querySelectorAll('.sibling-row');
        var last = rows[rows.length - 1];
        if (!last) return;
        var clone = last.cloneNode(true);
        var newIdx = rows.length;
        clone.querySelectorAll('input, select, textarea').forEach(function(el) {
            if (el.name) {
                el.name = el.name.replace(/siblings\[\d+\]/, 'siblings[' + newIdx + ']');
            }
            if (el.type === 'checkbox') el.checked = false;
            else if (el.type !== 'hidden') el.value = '';
        });
        clone.querySelector('input[type=hidden][name*="[id]"]').value = '';
        container.appendChild(clone);
    });
})();
</script>
