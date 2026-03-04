{{-- Centralized Relation Engine: Siblings + Relatives. Filter: showMarried = true only for siblings. --}}
@props([
    'namePrefix' => 'siblings',
    'relationOptions' => [],
    'showMarried' => false,
    'items' => collect(),
    'showPrimaryContact' => false,
    'addButtonLabel' => 'Add',
    'removeButtonLabel' => 'Remove',
])
@php
    $opts = collect($relationOptions)->map(function ($o) {
        if (is_string($o)) return ['value' => $o, 'label' => $o];
        if (is_array($o)) return ['value' => $o['value'] ?? $o[0] ?? '', 'label' => $o['label'] ?? $o[1] ?? $o['value'] ?? ''];
        return ['value' => '', 'label' => ''];
    })->filter(fn($o) => $o['value'] !== '' || $o['label'] !== '')->values()->all();
    $rows = old($namePrefix, $items);
    if (is_object($rows)) { $rows = $rows->all(); }
    if (count($rows) === 0) { $rows = [[]]; }
@endphp
<style>
.relation-engine-row .relation-address-cell .location-typeahead-input {
    height: 2.5rem; box-sizing: border-box; font-size: 0.875rem; line-height: 1.25rem;
}
.relation-engine-row .relation-address-cell .location-typeahead-input::placeholder { font-size: 0.875rem; }
.relation-engine-row .relation-address-cell label { font-size: 0.75rem; font-weight: 500; }
.relation-two-line-grid { gap: 0.75rem 0.75rem; margin-bottom: 0; }
.relation-two-line-grid > * { margin: 0; }
.relation-marital-select.marital-no, .relation-marital-select.marital-yes {
    -webkit-appearance: none; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 0.25rem center; background-size: 1.25rem; padding-right: 1.75rem;
}
.relation-marital-select.marital-no { background-color: rgb(59 130 246); border-color: rgb(37 99 235); color: white; }
.dark .relation-marital-select.marital-no { background-color: rgb(59 130 246); border-color: rgb(37 99 235); color: white; }
.relation-marital-select.marital-yes { background-color: rgb(22 163 74); border-color: rgb(21 128 61); color: white; }
.dark .relation-marital-select.marital-yes { background-color: rgb(22 163 74); border-color: rgb(21 128 61); color: white; }
</style>
<div id="{{ $namePrefix }}-container" class="space-y-4" data-repeater-container data-relation-engine data-name-prefix="{{ $namePrefix }}" data-row-class="{{ $namePrefix }}-row" data-show-married="{{ $showMarried ? '1' : '0' }}" data-min-rows="1">
    @foreach($rows as $idx => $row)
        @php
            $r = is_object($row) ? (array) $row : (array) $row;
            $spouse = $r['spouse'] ?? null;
            if (is_object($spouse)) { $spouse = (array) $spouse; }
            $spouse = $spouse ?? [];
            $isMarried = ($r['marital_status'] ?? '') === 'married' || !empty($r['is_married']) || !empty($spouse['name']) || !empty($spouse['occupation_title']);
        @endphp
        <div class="{{ $namePrefix }}-row relation-engine-row mb-4 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <input type="hidden" name="{{ $namePrefix }}[{{ $idx }}][id]" value="{{ $r['id'] ?? '' }}">

            @if($showMarried)
            {{-- Line 1 with Married: Relation | [Name+Married] | Mobile --}}
            <div class="grid items-end gap-2" style="grid-template-columns: minmax(4rem, 22fr) minmax(8rem, 38fr) minmax(5rem, 38fr);">
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Relation</label>
                    <select name="{{ $namePrefix }}[{{ $idx }}][relation_type]" class="relation-input-h form-select w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                        <option value="">—</option>
                        @foreach($opts as $opt)
                            <option value="{{ $opt['value'] }}" {{ ($r['relation_type'] ?? '') == $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-0 min-w-0 overflow-hidden">
                    <div class="min-w-0 flex-1" style="min-width: 0;">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Name</label>
                        <input type="text" name="{{ $namePrefix }}[{{ $idx }}][name]" value="{{ $r['name'] ?? '' }}" placeholder="Name" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm box-border">
                    </div>
                    <div class="relation-marital-wrap flex-shrink-0" data-spouse-block="{{ $namePrefix }}-spouse-{{ $idx }}" style="width: 4.25rem;">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Married</label>
                        <select name="{{ $namePrefix }}[{{ $idx }}][marital_status]" class="relation-marital-select relation-input-h form-select w-full h-10 rounded border px-1 py-1.5 text-sm box-border {{ ($r['marital_status'] ?? '') === 'married' || $isMarried ? 'marital-yes' : 'marital-no' }}">
                            <option value="unmarried" {{ ($r['marital_status'] ?? '') !== 'married' && !$isMarried ? 'selected' : '' }}>No</option>
                            <option value="married" {{ ($r['marital_status'] ?? '') === 'married' || $isMarried ? 'selected' : '' }}>Yes</option>
                        </select>
                    </div>
                </div>
                <div class="min-w-0">
                    <x-profile.contact-field name="{{ $namePrefix }}[{{ $idx }}][contact_number]" :value="$r['contact_number'] ?? ''" label="Mobile" placeholder="10-digit" :showCountryCode="false" :showWhatsapp="false" inputClass="relation-input-h w-full min-w-0 box-border" />
                </div>
            </div>
            @else
            {{-- Relatives: 2 lines × 3 fields in one grid so each column same width (Relation↔Occupation, Name↔Address, Mobile↔Additional) --}}
            <div class="grid items-end relation-two-line-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Relation</label>
                    <select name="{{ $namePrefix }}[{{ $idx }}][relation_type]" class="relation-input-h form-select w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                        <option value="">—</option>
                        @foreach($opts as $opt)
                            <option value="{{ $opt['value'] }}" {{ ($r['relation_type'] ?? '') == $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Name</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][name]" value="{{ $r['name'] ?? '' }}" placeholder="Name" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
                <div class="min-w-0">
                    <x-profile.contact-field name="{{ $namePrefix }}[{{ $idx }}][contact_number]" :value="$r['contact_number'] ?? ''" label="Mobile" placeholder="10-digit" :showCountryCode="false" :showWhatsapp="false" inputClass="relation-input-h w-full min-w-0 box-border" />
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Occupation</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][occupation]" value="{{ $r['occupation'] ?? '' }}" placeholder="Occupation" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
                <div class="min-w-0 relation-address-cell">
                    <x-profile.location-typeahead
                        context="alliance"
                        namePrefix="{{ $namePrefix }}[{{ $idx }}]"
                        :value="$r['location_display'] ?? ''"
                        placeholder="Address / city"
                        label="Address"
                        :data-city-id="$r['city_id'] ?? ''"
                        :data-taluka-id="$r['taluka_id'] ?? ''"
                        :data-district-id="$r['district_id'] ?? ''"
                        :data-state-id="$r['state_id'] ?? ''"
                    />
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Additional info</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][notes]" value="{{ $r['notes'] ?? '' }}" placeholder="Notes" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
            </div>
            @endif

            @if($showMarried)
            {{-- Line 2 for siblings only: same column widths as Line 1 (Relation | Name+Married | Mobile) --}}
            <div class="grid gap-2 items-end" style="grid-template-columns: minmax(4rem, 22fr) minmax(8rem, 38fr) minmax(5rem, 38fr);">
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Occupation</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][occupation]" value="{{ $r['occupation'] ?? '' }}" placeholder="Occupation" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
                <div class="min-w-0 relation-address-cell">
                    <x-profile.location-typeahead
                        context="alliance"
                        namePrefix="{{ $namePrefix }}[{{ $idx }}]"
                        :value="$r['location_display'] ?? ''"
                        placeholder="Address / city"
                        label="Address"
                        :data-city-id="$r['city_id'] ?? ''"
                        :data-taluka-id="$r['taluka_id'] ?? ''"
                        :data-district-id="$r['district_id'] ?? ''"
                        :data-state-id="$r['state_id'] ?? ''"
                    />
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Additional info</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][notes]" value="{{ $r['notes'] ?? '' }}" placeholder="Notes" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
            </div>
            @endif

            @if($showMarried)
            <div id="{{ $namePrefix }}-spouse-{{ $idx }}" class="relation-spouse-block mt-3 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700/60 p-3" style="{{ !$isMarried ? 'display:none;' : '' }}">
                <p class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">Spouse details</p>
                <div class="grid gap-2 items-end mb-2" style="grid-template-columns: 1fr 1fr;">
                    <div class="min-w-0">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Name</label>
                        <input type="text" name="{{ $namePrefix }}[{{ $idx }}][spouse][name]" value="{{ $spouse['name'] ?? '' }}" placeholder="Name" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                    </div>
                    <div class="min-w-0">
                        <x-profile.contact-field name="{{ $namePrefix }}[{{ $idx }}][spouse][contact_number]" :value="$spouse['contact_number'] ?? ''" label="Mobile" placeholder="10-digit" :showCountryCode="false" :showWhatsapp="false" inputClass="relation-input-h w-full min-w-0" />
                    </div>
                </div>
                <div class="grid gap-2 items-end" style="grid-template-columns: 30fr 30fr 40fr;">
                    <div class="min-w-0">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Occupation</label>
                        <input type="text" name="{{ $namePrefix }}[{{ $idx }}][spouse][occupation_title]" value="{{ $spouse['occupation_title'] ?? '' }}" placeholder="Occupation" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                    </div>
                    <div class="min-w-0 relation-address-cell">
                        <x-profile.location-typeahead context="alliance" namePrefix="{{ $namePrefix }}[{{ $idx }}][spouse]" :value="$spouse['location_display'] ?? ''" placeholder="Address / city" label="Address" :data-city-id="$spouse['city_id'] ?? ''" :data-taluka-id="$spouse['taluka_id'] ?? ''" :data-district-id="$spouse['district_id'] ?? ''" :data-state-id="$spouse['state_id'] ?? ''" />
                    </div>
                    <div class="min-w-0">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Additional info</label>
                        <input type="text" name="{{ $namePrefix }}[{{ $idx }}][spouse][address_line]" value="{{ $spouse['address_line'] ?? '' }}" placeholder="Notes" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                    </div>
                </div>
            </div>
            @endif

            @if($showPrimaryContact)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="{{ $namePrefix }}[{{ $idx }}][is_primary_contact]" value="1" {{ !empty($r['is_primary_contact']) ? 'checked' : '' }}>
                Primary contact
            </label>
            @endif

            <div>
                <button type="button" class="relation-remove-btn text-sm text-red-600 dark:text-red-400 hover:underline" data-repeater-remove>{{ $removeButtonLabel }}</button>
            </div>
        </div>
    @endforeach
</div>
<button type="button" class="relation-add-btn px-4 py-2 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-200 rounded font-medium text-sm" data-repeater-add data-repeater-for="{{ $namePrefix }}-container">{{ $addButtonLabel }}</button>

<x-repeaters.repeater-script />
<script>
(function() {
    function initRelationEngine(container) {
        if (!container || container.getAttribute('data-relation-inited') === '1') return;
        var prefix = container.getAttribute('data-name-prefix');
        var showMarried = container.getAttribute('data-show-married') === '1';

        function updateMaritalStyles() {
            container.querySelectorAll('.relation-marital-select').forEach(function(sel) {
                sel.classList.remove('marital-yes', 'marital-no');
                sel.classList.add(sel.value === 'married' ? 'marital-yes' : 'marital-no');
            });
        }
        function toggleSpouseBlocks() {
            container.querySelectorAll('.relation-marital-select').forEach(function(sel) {
                var wrap = sel.closest('.relation-marital-wrap');
                var blockId = wrap ? wrap.getAttribute('data-spouse-block') : null;
                var block = blockId ? document.getElementById(blockId) : null;
                if (block) block.style.display = (sel.value === 'married') ? 'block' : 'none';
            });
            updateMaritalStyles();
        }
        container.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('relation-marital-select')) toggleSpouseBlocks();
        });
        if (showMarried) { updateMaritalStyles(); toggleSpouseBlocks(); }

        container.addEventListener('repeater:row-added', function(e) {
            var detail = e.detail || {};
            var row = detail.row;
            var newIdx = detail.index;
            if (!row || showMarried !== true) { if (window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init(); return; }
            var ms = row.querySelector('.relation-marital-select');
            if (ms) { ms.value = 'unmarried'; ms.classList.remove('marital-yes'); ms.classList.add('marital-no'); }
            var spouseBlock = row.querySelector('.relation-spouse-block');
            if (spouseBlock) {
                spouseBlock.id = prefix + '-spouse-' + newIdx;
                var wrap = row.querySelector('.relation-marital-wrap');
                if (wrap) wrap.setAttribute('data-spouse-block', prefix + '-spouse-' + newIdx);
                spouseBlock.style.display = 'none';
            }
            row.querySelectorAll('.location-typeahead-wrapper').forEach(function(w) { w.removeAttribute('data-bound'); });
            row.querySelectorAll('.location-typeahead-input').forEach(function(i) { i.value = ''; });
            row.querySelectorAll('.location-hidden-city, .location-hidden-taluka, .location-hidden-district, .location-hidden-state').forEach(function(h) { h.value = ''; });
            toggleSpouseBlocks();
            if (window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init();
        });
        container.setAttribute('data-relation-inited', '1');
    }
    document.querySelectorAll('[data-relation-engine]').forEach(initRelationEngine);
})();
</script>
