{{-- Relatives & Family Network — repeatable; no required fields --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Relatives & Family Network</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400">Add extended family members. All fields are optional.</p>

    <div id="relatives-container">
        @php
            $relativesRows = old('relatives', $profileRelatives ?? collect());
            if (is_object($relativesRows)) { $relativesRows = $relativesRows->all(); }
            if (count($relativesRows) === 0) { $relativesRows = [[]]; }
        @endphp
        @foreach($relativesRows as $idx => $row)
            @php $r = is_object($row) ? (array) $row : (array) $row; @endphp
            <div class="relative-row mb-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
                <input type="hidden" name="relatives[{{ $idx }}][id]" value="{{ $r['id'] ?? '' }}">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Relation</label>
                        <select name="relatives[{{ $idx }}][relation_type]" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                            <option value="">— Select —</option>
                            @foreach($relationTypes ?? [] as $rt)
                                <option value="{{ $rt }}" {{ ($r['relation_type'] ?? '') === $rt ? 'selected' : '' }}>{{ $rt }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                        <input type="text" name="relatives[{{ $idx }}][name]" value="{{ $r['name'] ?? '' }}" placeholder="Name" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Occupation</label>
                        <input type="text" name="relatives[{{ $idx }}][occupation]" value="{{ $r['occupation'] ?? '' }}" placeholder="Occupation" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">City</label>
                        <select name="relatives[{{ $idx }}][city_id]" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                            <option value="">— Select —</option>
                            @foreach($cities ?? [] as $city)
                                <option value="{{ $city->id }}" {{ ($r['city_id'] ?? '') == $city->id ? 'selected' : '' }}>{{ $city->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">State</label>
                        <select name="relatives[{{ $idx }}][state_id]" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                            <option value="">— Select —</option>
                            @foreach($states ?? [] as $state)
                                <option value="{{ $state->id }}" {{ ($r['state_id'] ?? '') == $state->id ? 'selected' : '' }}>{{ $state->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contact</label>
                        <input type="text" name="relatives[{{ $idx }}][contact_number]" value="{{ $r['contact_number'] ?? '' }}" placeholder="Contact number" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notes</label>
                    <textarea name="relatives[{{ $idx }}][notes]" rows="2" placeholder="Notes" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ $r['notes'] ?? '' }}</textarea>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="relatives[{{ $idx }}][is_primary_contact]" value="1" {{ !empty($r['is_primary_contact']) ? 'checked' : '' }}>
                    Primary contact for this relative
                </label>
            </div>
        @endforeach
    </div>

    <button type="button" id="add-relative-btn" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-200 rounded font-medium text-sm">
        Add Relative
    </button>
</div>

<script>
(function() {
    document.getElementById('add-relative-btn')?.addEventListener('click', function() {
        var container = document.getElementById('relatives-container');
        var rows = container.querySelectorAll('.relative-row');
        var last = rows[rows.length - 1];
        if (!last) return;
        var clone = last.cloneNode(true);
        var newIdx = rows.length;
        clone.querySelectorAll('input, select, textarea').forEach(function(el) {
            el.name = el.name.replace(/relatives\[\d+\]/, 'relatives[' + newIdx + ']');
            if (el.type === 'checkbox') el.checked = false;
            else if (el.type !== 'hidden') el.value = '';
        });
        var idInput = clone.querySelector('input[type=hidden][name*="[id]"]');
        if (idInput) idInput.value = '';
        container.appendChild(clone);
    });
})();
</script>
