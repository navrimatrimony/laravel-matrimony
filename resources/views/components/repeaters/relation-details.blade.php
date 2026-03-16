{{-- Centralized Relation Engine: Siblings + Relatives. Filter: showMarried = true only for siblings. --}}
@props([
    'namePrefix' => 'siblings',
    'relationOptions' => [],
    'showMarried' => false,
    'items' => collect(),
    'showPrimaryContact' => false,
    'addButtonLabel' => null,
    'removeButtonLabel' => null,
    'contentShowBinding' => null,
    'contentShowInitial' => true,
    'addressOnlyRelationValue' => null, // e.g. 'maternal_address_ajol' — when selected, only Relation + Address shown
    'notesPlaceholder' => null, // when set (e.g. extended family), use instead of notes_placeholder to steer "other relatives" to Other Relatives section
])
@php
    $addButtonLabel = $addButtonLabel ?? __('common.add');
    $removeButtonLabel = $removeButtonLabel ?? __('common.remove');
    $notesPlaceholder = $notesPlaceholder ?? __('components.relation.notes_placeholder');
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
/* Add control only in last row; same line as Remove on the right */
[data-relation-engine] .relation-engine-row:not(:last-child) .relation-add-wrap { display: none; }
</style>
<div class="space-y-4 border-2 border-rose-500 dark:border-rose-400 rounded-lg p-4" data-relation-engine data-show-married="{{ $showMarried ? '1' : '0' }}" data-address-only-relation="{{ $addressOnlyRelationValue ?? '' }}"
    @if($contentShowBinding) x-data="{ {{ $contentShowBinding }}: {{ $contentShowInitial ? 'true' : 'false' }} }" @else id="{{ $namePrefix }}-container" data-repeater-container data-name-prefix="{{ $namePrefix }}" data-row-class="{{ $namePrefix }}-row" data-min-rows="1" @endif>
    @if(isset($header))
    <div class="pb-2">{{ $header }}</div>
    @endif
    @if($contentShowBinding)
    <div id="{{ $namePrefix }}-container" data-repeater-container data-name-prefix="{{ $namePrefix }}" data-row-class="{{ $namePrefix }}-row" data-min-rows="1" x-show="{{ $contentShowBinding }}" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="space-y-4">
    @endif
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
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.relation') }}</label>
                    <select name="{{ $namePrefix }}[{{ $idx }}][relation_type]" class="relation-input-h form-select w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                        <option value="">—</option>
                        @foreach($opts as $opt)
                            <option value="{{ $opt['value'] }}" {{ ($r['relation_type'] ?? '') == $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-0 min-w-0 overflow-hidden">
                    <div class="min-w-0 flex-1" style="min-width: 0;">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.name') }}</label>
                        <input type="text" name="{{ $namePrefix }}[{{ $idx }}][name]" value="{{ $r['name'] ?? '' }}" placeholder="{{ __('components.relation.name_placeholder') }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm box-border">
                    </div>
                    <div class="relation-marital-wrap flex-shrink-0" data-spouse-block="{{ $namePrefix }}-spouse-{{ $idx }}" style="width: 4.25rem;">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.married') }}</label>
                        <select name="{{ $namePrefix }}[{{ $idx }}][marital_status]" class="relation-marital-select relation-input-h form-select w-full h-10 rounded border px-1 py-1.5 text-sm box-border {{ ($r['marital_status'] ?? '') === 'married' || $isMarried ? 'marital-yes' : 'marital-no' }}">
                            <option value="unmarried" {{ ($r['marital_status'] ?? '') !== 'married' && !$isMarried ? 'selected' : '' }}>{{ __('common.no') }}</option>
                            <option value="married" {{ ($r['marital_status'] ?? '') === 'married' || $isMarried ? 'selected' : '' }}>{{ __('common.yes') }}</option>
                        </select>
                    </div>
                </div>
                <div class="min-w-0" data-contact-context="sibling" data-row-index="{{ $idx }}" data-name-prefix="{{ $namePrefix }}">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.mobile') }}</label>
                    <div class="flex flex-wrap items-end gap-1 sibling-contact-slots-inner">
                        @php
                            $sbContacts = [$r['contact_number'] ?? '', $r['contact_number_2'] ?? '', $r['contact_number_3'] ?? ''];
                            $sbCount = max(1, count(array_filter($sbContacts, fn($v) => trim((string)$v) !== '')));
                        @endphp
                        @for($si = 0; $si < $sbCount; $si++)
                            @php
                                $suffix = $si === 0 ? 'contact_number' : 'contact_number_' . ($si + 1);
                                $showAdd = $si < 2;
                            @endphp
                            <div class="sibling-contact-slot {{ $si === 0 ? 'w-full basis-full' : 'shrink-0' }}">
                                <x-profile.contact-field
                                    name="{{ $namePrefix }}[{{ $idx }}][{{ $suffix }}]"
                                    :value="$sbContacts[$si] ?? ''"
                                    label=""
                                    placeholder="{{ __('components.relation.ten_digit') }}"
                                    :showCountryCode="true"
                                    :showWhatsapp="true"
                                    :nameWhatsapp="$namePrefix . '[' . $idx . '][contact_preference_' . ($si + 1) . ']'"
                                    :valueWhatsapp="($si === 0) ? 'whatsapp' : 'call'"
                                    inputClass="relation-input-h w-full min-w-0 box-border"
                                    :showAddButton="$showAdd"
                                />
                            </div>
                        @endfor
                    </div>
                </div>
            </div>
            @else
            {{-- Relatives --}}
            @if($addressOnlyRelationValue)
            @php $isAddressOnly = ($r['relation_type'] ?? '') === $addressOnlyRelationValue; @endphp
            {{-- When Ajol: one row, 2 fields (Relation | Address). When not Ajol: 2 rows × 3 fields. --}}
            <div class="relation-address-only-wrap grid items-end" style="grid-template-columns: 1fr 1fr; gap: 0.75rem; display:{{ $isAddressOnly ? 'grid' : 'none' }};">
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.relation') }}</label>
                    <select name="{{ $namePrefix }}[{{ $idx }}][relation_type]" class="relation-type-select relation-input-h form-select w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                        <option value="">—</option>
                        @foreach($opts as $opt)
                            <option value="{{ $opt['value'] }}" {{ ($r['relation_type'] ?? '') == $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-0 relation-address-cell">
                    <x-profile.location-typeahead
                        context="alliance"
                        namePrefix="{{ $namePrefix }}[{{ $idx }}]"
                        :value="$r['location_display'] ?? ''"
                        placeholder="{{ __('components.relation.address_city') }}"
                        label="{{ __('components.relation.address') }}"
                        :data-city-id="$r['city_id'] ?? ''"
                        :data-taluka-id="$r['taluka_id'] ?? ''"
                        :data-district-id="$r['district_id'] ?? ''"
                        :data-state-id="$r['state_id'] ?? ''"
                    />
                </div>
            </div>
            <div class="relation-fields-wrap relation-two-line-grid grid items-end" style="grid-template-columns: 1fr 1fr 1fr; display:{{ $isAddressOnly ? 'none' : 'grid' }}; gap: 0.75rem;">
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.relation') }}</label>
                    <select name="{{ $namePrefix }}[{{ $idx }}][relation_type]" class="relation-type-select relation-input-h form-select w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                        <option value="">—</option>
                        @foreach($opts as $opt)
                            <option value="{{ $opt['value'] }}" {{ ($r['relation_type'] ?? '') == $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.name') }}</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][name]" value="{{ $r['name'] ?? '' }}" placeholder="{{ __('components.relation.name_placeholder') }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
                <div class="min-w-0">
                    <x-profile.contact-field name="{{ $namePrefix }}[{{ $idx }}][contact_number]" :value="$r['contact_number'] ?? ''" :label="__('components.relation.mobile')" :placeholder="__('components.relation.ten_digit')" :showCountryCode="true" :showWhatsapp="true" :nameWhatsapp="$namePrefix . '[' . $idx . '][contact_preference]'" :valueWhatsapp="'call'" inputClass="relation-input-h w-full min-w-0 box-border" />
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.occupation') }}</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][occupation]" value="{{ $r['occupation'] ?? '' }}" placeholder="{{ __('components.relation.occupation_placeholder') }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
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
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.additional_info') }}</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][notes]" value="{{ $r['notes'] ?? '' }}" placeholder="{{ $notesPlaceholder }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
            </div>
            @else
            {{-- Default relatives: 2 lines × 3 fields (Relation↔Occupation, Name↔Address, Mobile↔Additional) --}}
            <div class="grid items-end relation-two-line-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.relation') }}</label>
                    <select name="{{ $namePrefix }}[{{ $idx }}][relation_type]" class="relation-input-h form-select w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                        <option value="">—</option>
                        @foreach($opts as $opt)
                            <option value="{{ $opt['value'] }}" {{ ($r['relation_type'] ?? '') == $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.name') }}</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][name]" value="{{ $r['name'] ?? '' }}" placeholder="{{ __('components.relation.name_placeholder') }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
                <div class="min-w-0">
                    <x-profile.contact-field name="{{ $namePrefix }}[{{ $idx }}][contact_number]" :value="$r['contact_number'] ?? ''" :label="__('components.relation.mobile')" :placeholder="__('components.relation.ten_digit')" :showCountryCode="true" :showWhatsapp="true" :nameWhatsapp="$namePrefix . '[' . $idx . '][contact_preference]'" :valueWhatsapp="'call'" inputClass="relation-input-h w-full min-w-0 box-border" />
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.occupation') }}</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][occupation]" value="{{ $r['occupation'] ?? '' }}" placeholder="{{ __('components.relation.occupation_placeholder') }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
                <div class="min-w-0 relation-address-cell">
                    <x-profile.location-typeahead
                        context="alliance"
                        namePrefix="{{ $namePrefix }}[{{ $idx }}]"
                        :value="$r['location_display'] ?? ''"
                        placeholder="{{ __('components.relation.address_city') }}"
                        label="{{ __('components.relation.address') }}"
                        :data-city-id="$r['city_id'] ?? ''"
                        :data-taluka-id="$r['taluka_id'] ?? ''"
                        :data-district-id="$r['district_id'] ?? ''"
                        :data-state-id="$r['state_id'] ?? ''"
                    />
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.additional_info') }}</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][notes]" value="{{ $r['notes'] ?? '' }}" placeholder="{{ $notesPlaceholder }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
            </div>
            @endif
            @endif

            @if($showMarried)
            {{-- Line 2 for siblings only: same column widths as Line 1 (Relation | Name+Married | Mobile) --}}
            <div class="grid gap-2 items-end" style="grid-template-columns: minmax(4rem, 22fr) minmax(8rem, 38fr) minmax(5rem, 38fr);">
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.occupation') }}</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][occupation]" value="{{ $r['occupation'] ?? '' }}" placeholder="{{ __('components.relation.occupation_placeholder') }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
                <div class="min-w-0 relation-address-cell">
                    <x-profile.location-typeahead
                        context="alliance"
                        namePrefix="{{ $namePrefix }}[{{ $idx }}]"
                        :value="$r['location_display'] ?? ''"
                        placeholder="{{ __('components.relation.address_city') }}"
                        label="{{ __('components.relation.address') }}"
                        :data-city-id="$r['city_id'] ?? ''"
                        :data-taluka-id="$r['taluka_id'] ?? ''"
                        :data-district-id="$r['district_id'] ?? ''"
                        :data-state-id="$r['state_id'] ?? ''"
                    />
                </div>
                <div class="min-w-0">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.additional_info') }}</label>
                    <input type="text" name="{{ $namePrefix }}[{{ $idx }}][notes]" value="{{ $r['notes'] ?? '' }}" placeholder="{{ $notesPlaceholder }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                </div>
            </div>
            @endif

            @if($showMarried)
            <div id="{{ $namePrefix }}-spouse-{{ $idx }}" class="relation-spouse-block mt-3 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700/60 p-3" style="{{ !$isMarried ? 'display:none;' : '' }}">
                <p class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('components.relation.spouse_details') }}</p>
                <div class="grid gap-2 items-end mb-2" style="grid-template-columns: 1fr 1fr;">
                    <div class="min-w-0">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.name') }}</label>
                        <input type="text" name="{{ $namePrefix }}[{{ $idx }}][spouse][name]" value="{{ $spouse['name'] ?? '' }}" placeholder="{{ __('components.relation.name_placeholder') }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                    </div>
                    <div class="min-w-0">
                        <x-profile.contact-field name="{{ $namePrefix }}[{{ $idx }}][spouse][contact_number]" :value="$spouse['contact_number'] ?? ''" :label="__('components.relation.mobile')" :placeholder="__('components.relation.ten_digit')" :showCountryCode="true" :showWhatsapp="true" :nameWhatsapp="$namePrefix . '[' . $idx . '][spouse][contact_preference]'" :valueWhatsapp="'call'" inputClass="relation-input-h w-full min-w-0" />
                    </div>
                </div>
                <div class="grid gap-2 items-end" style="grid-template-columns: 30fr 30fr 40fr;">
                    <div class="min-w-0">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.occupation') }}</label>
                        <input type="text" name="{{ $namePrefix }}[{{ $idx }}][spouse][occupation_title]" value="{{ $spouse['occupation_title'] ?? '' }}" placeholder="{{ __('components.relation.occupation_placeholder') }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                    </div>
                    <div class="min-w-0 relation-address-cell">
                        <x-profile.location-typeahead context="alliance" namePrefix="{{ $namePrefix }}[{{ $idx }}][spouse]" :value="$spouse['location_display'] ?? ''" placeholder="{{ __('components.relation.address_city') }}" label="{{ __('components.relation.address') }}" :data-city-id="$spouse['city_id'] ?? ''" :data-taluka-id="$spouse['taluka_id'] ?? ''" :data-district-id="$spouse['district_id'] ?? ''" :data-state-id="$spouse['state_id'] ?? ''" />
                    </div>
                    <div class="min-w-0">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ __('components.relation.additional_info') }}</label>
                        <input type="text" name="{{ $namePrefix }}[{{ $idx }}][spouse][address_line]" value="{{ $spouse['address_line'] ?? '' }}" placeholder="{{ __('components.relation.notes_placeholder') }}" class="relation-input-h w-full h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0">
                    </div>
                </div>
            </div>
            @endif

            @if($showPrimaryContact)
            @php $hidePrimaryForAddressOnly = isset($addressOnlyRelationValue) && (($r['relation_type'] ?? '') === $addressOnlyRelationValue); @endphp
            <div class="relation-primary-contact-wrap" @if($hidePrimaryForAddressOnly) style="display:none" @endif>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="{{ $namePrefix }}[{{ $idx }}][is_primary_contact]" value="1" {{ !empty($r['is_primary_contact']) ? 'checked' : '' }}>
                    {{ __('components.relation.primary_contact') }}
                </label>
            </div>
            @endif

            <div class="flex justify-between items-center">
                <div class="relation-add-wrap">
                    <span role="button" tabindex="0" class="relation-add-btn inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 cursor-pointer font-medium text-sm" data-repeater-add data-repeater-for="{{ $namePrefix }}-container"><span aria-hidden="true">+</span> {{ $addButtonLabel }}</span>
                </div>
                <div>
                    <button type="button" class="relation-remove-btn text-sm text-red-600 dark:text-red-400 hover:underline" data-repeater-remove>{{ $removeButtonLabel }}</button>
                </div>
            </div>
        </div>
    @endforeach
    @if($contentShowBinding)
    </div>
    @endif
</div>

<template id="sibling-contact-slot-tpl">
    <div class="sibling-contact-slot shrink-0">
    <div class="contact-field-engine border-2 border-rose-500 dark:border-rose-400 rounded-lg p-3">
        <div class="flex items-center gap-1.5 flex-nowrap contact-master-field">
            <input type="text" inputmode="tel" maxlength="5" value="+91" placeholder="+91" class="text-xs text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 rounded py-1.5 bg-gray-50 dark:bg-gray-700 h-9 box-border text-center shrink-0 contact-cc-input" style="flex:0 0 2.25rem; width:2.25rem; min-width:2.25rem; max-width:2.25rem; padding-left:0.2rem; padding-right:0.2rem;">
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" name="__NAME__" placeholder="{{ __('components.relation.ten_digit') }}" data-contact-engine class="relation-input-h h-9 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0 flex-1">
            <input type="hidden" name="__PREF_NAME__" value="call" class="contact-preference-input">
            <div class="relative shrink-0 contact-preference-single" data-current-pref="call">
                <button type="button" class="contact-pref-trigger rounded p-1.5 ring-2 ring-rose-500 bg-rose-50 dark:bg-rose-900/30 inline-flex items-center justify-center" title="{{ __('contact.prefer_contact_via') }}" aria-haspopup="true" aria-expanded="false">
                    <span class="contact-pref-icon contact-pref-icon-whatsapp" data-pref="whatsapp" style="display:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></span>
                    <span class="contact-pref-icon contact-pref-icon-call text-red-500 dark:text-red-400" data-pref="call"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                    <span class="contact-pref-icon contact-pref-icon-message" data-pref="message" style="display:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg></span>
                </button>
                <div class="contact-pref-dropdown hidden absolute right-0 top-full mt-1 z-50 min-w-[8rem] py-1 rounded-lg shadow-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600">
                    <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="whatsapp"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> {{ __('contact.whatsapp') }}</button>
                    <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="call"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-red-500 dark:text-red-400"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> {{ __('contact.call') }}</button>
                    <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="message"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg> {{ __('contact.message') }}</button>
                </div>
            </div>
            <button type="button" class="contact-engine-add-btn shrink-0 inline-flex items-center justify-center w-9 h-9 rounded border-2 border-rose-500 dark:border-rose-400 bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-300 font-bold text-lg leading-none hover:bg-rose-100 dark:hover:bg-rose-800/50" title="{{ __('contact.add_another_contact') }}" aria-label="{{ __('contact.add_contact') }}">+</button>
        </div>
    </div>
    </div>
</template>

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
            if (!showMarried && e.target.matches('select[name*="[relation_type]"]') && e.target.value === '') {
                var row = e.target.closest('.relation-engine-row');
                if (row) {
                    row.querySelectorAll('input[name*="[name]"]').forEach(function(i) { i.value = ''; });
                    row.querySelectorAll('input[name*="[occupation]"]').forEach(function(i) { i.value = ''; });
                    row.querySelectorAll('input[name*="[notes]"]').forEach(function(i) { i.value = ''; });
                    row.querySelectorAll('input[name*="[contact_number]"]').forEach(function(i) { i.value = ''; });
                    row.querySelectorAll('.location-typeahead-input').forEach(function(i) { i.value = ''; });
                    row.querySelectorAll('.location-hidden-city, .location-hidden-taluka, .location-hidden-district, .location-hidden-state').forEach(function(h) { h.value = ''; });
                }
            }
        });
        if (showMarried) { updateMaritalStyles(); toggleSpouseBlocks(); }

        var addressOnlyRelation = container.getAttribute('data-address-only-relation') || '';
        function setDisabledInEl(el, disabled) {
            if (!el) return;
            el.querySelectorAll('input, select, textarea').forEach(function(inp) { inp.disabled = disabled; });
        }
        function toggleAddressOnlyRow(row, changedSelect) {
            if (!addressOnlyRelation) return;
            var addrOnlyWrap = row.querySelector('.relation-address-only-wrap');
            var fieldsWrap = row.querySelector('.relation-fields-wrap');
            var selAddr = addrOnlyWrap ? addrOnlyWrap.querySelector('.relation-type-select') : null;
            var selFields = fieldsWrap ? fieldsWrap.querySelector('.relation-type-select') : null;
            if (!addrOnlyWrap || !fieldsWrap) return;
            var val = changedSelect ? changedSelect.value : (selAddr ? selAddr.value : (selFields ? selFields.value : ''));
            if (selAddr && selFields) { selAddr.value = val; selFields.value = val; }
            var isAddrOnly = val === addressOnlyRelation;
            addrOnlyWrap.style.display = isAddrOnly ? 'grid' : 'none';
            fieldsWrap.style.display = isAddrOnly ? 'none' : 'grid';
            setDisabledInEl(addrOnlyWrap, !isAddrOnly);
            setDisabledInEl(fieldsWrap, isAddrOnly);
            var primaryWrap = row.querySelector('.relation-primary-contact-wrap');
            if (primaryWrap) primaryWrap.style.display = isAddrOnly ? 'none' : '';
        }
        function initAddressOnlyToggles() {
            if (!addressOnlyRelation) return;
            container.querySelectorAll('.relation-engine-row').forEach(function(row) {
                var addrOnlyWrap = row.querySelector('.relation-address-only-wrap');
                var fieldsWrap = row.querySelector('.relation-fields-wrap');
                var selAddr = addrOnlyWrap ? addrOnlyWrap.querySelector('.relation-type-select') : null;
                var selFields = fieldsWrap ? fieldsWrap.querySelector('.relation-type-select') : null;
                function onRelationChange(e) { toggleAddressOnlyRow(row, e.target); }
                [selAddr, selFields].forEach(function(sel) {
                    if (!sel) return;
                    sel.removeEventListener('change', row._addrOnlyChange);
                    row._addrOnlyChange = onRelationChange;
                    sel.addEventListener('change', onRelationChange);
                });
                toggleAddressOnlyRow(row, selAddr || selFields);
            });
        }
        if (addressOnlyRelation) initAddressOnlyToggles();
        function clearRowFieldsIfNoRelation(row) {
            var sel = row.querySelector('select[name*="[relation_type]"]');
            if (!sel || sel.value !== '') return;
            row.querySelectorAll('input[name*="[name]"]').forEach(function(i) { i.value = ''; });
            row.querySelectorAll('input[name*="[occupation]"]').forEach(function(i) { i.value = ''; });
            row.querySelectorAll('input[name*="[notes]"]').forEach(function(i) { i.value = ''; });
            row.querySelectorAll('input[name*="[contact_number]"]').forEach(function(i) { i.value = ''; });
            row.querySelectorAll('.location-typeahead-input').forEach(function(i) { i.value = ''; });
            row.querySelectorAll('.location-hidden-city, .location-hidden-taluka, .location-hidden-district, .location-hidden-state').forEach(function(h) { h.value = ''; });
        }
        if (!showMarried) {
            container.querySelectorAll('.relation-engine-row').forEach(clearRowFieldsIfNoRelation);
        }
        container.addEventListener('repeater:row-added', function(e) {
            var detail = e.detail || {};
            var row = detail.row;
            var newIdx = detail.index;
            if (!row) return;
            if (showMarried !== true) {
                if (addressOnlyRelation) initAddressOnlyToggles();
                if (window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init();
                return;
            }
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
            if (addressOnlyRelation) initAddressOnlyToggles();
            if (window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init();
        });

        container.addEventListener('click', function(e) {
            if (!e.target.closest('.contact-engine-add-btn')) return;
            var ctx = e.target.closest('[data-contact-context="sibling"]');
            if (!ctx) return;
            var inner = ctx.querySelector('.sibling-contact-slots-inner');
            var maxSlots = parseInt(ctx.getAttribute('data-max-slots'), 10) || 999;
            var idx = ctx.getAttribute('data-row-index');
            var namePrefix = ctx.getAttribute('data-name-prefix');
            if (!inner || idx === null || !namePrefix) return;
            var slots = inner.querySelectorAll('.sibling-contact-slot');
            if (slots.length >= maxSlots) return;
            var nextSlotNum = slots.length + 1;
            var suffix = nextSlotNum === 2 ? 'contact_number_2' : (nextSlotNum === 3 ? 'contact_number_3' : 'contact_number_' + nextSlotNum);
            var name = namePrefix + '[' + idx + '][' + suffix + ']';
            var prefName = namePrefix + '[' + idx + '][contact_preference_' + nextSlotNum + ']';
            var tpl = document.getElementById('sibling-contact-slot-tpl');
            if (!tpl) return;
            var html = tpl.innerHTML.replace(/__NAME__/g, name).replace(/__PREF_NAME__/g, prefName);
            var div = document.createElement('div');
            div.innerHTML = html.trim();
            var newSlot = div.firstChild;
            if (newSlot) inner.appendChild(newSlot);
        });

        container.setAttribute('data-relation-inited', '1');
    }
    document.querySelectorAll('[data-relation-engine]').forEach(initRelationEngine);
})();
</script>
