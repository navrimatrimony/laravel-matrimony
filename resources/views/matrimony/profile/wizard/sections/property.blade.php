{{-- Phase-5 SSOT: Property - property_summary + property_assets --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Property</h2>
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Property summary</h3>
        @php $ps = old('property_summary', $profile_property_summary ?? new \stdClass()); @endphp
        <input type="hidden" name="property_summary[id]" value="{{ is_object($ps) ? ($ps->id ?? '') : ($ps['id'] ?? '') }}">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <label class="flex items-center gap-2"><input type="checkbox" name="property_summary[owns_house]" value="1" {{ (is_object($ps) ? ($ps->owns_house ?? false) : ($ps['owns_house'] ?? false)) ? 'checked' : '' }}> Owns House</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="property_summary[owns_flat]" value="1" {{ (is_object($ps) ? ($ps->owns_flat ?? false) : ($ps['owns_flat'] ?? false)) ? 'checked' : '' }}> Owns Flat</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="property_summary[owns_agriculture]" value="1" {{ (is_object($ps) ? ($ps->owns_agriculture ?? false) : ($ps['owns_agriculture'] ?? false)) ? 'checked' : '' }}> Owns Agriculture</label>
            <div>
                <label class="block text-sm mb-1">Agriculture type</label>
                <select name="property_summary[agriculture_type]" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">—</option>
                    <option value="बागायत" {{ (is_object($ps) ? ($ps->agriculture_type ?? '') : ($ps['agriculture_type'] ?? '')) === 'बागायत' ? 'selected' : '' }}>बागायत</option>
                    <option value="जिरायत" {{ (is_object($ps) ? ($ps->agriculture_type ?? '') : ($ps['agriculture_type'] ?? '')) === 'जिरायत' ? 'selected' : '' }}>जिरायत</option>
                    <option value="विहीर" {{ (is_object($ps) ? ($ps->agriculture_type ?? '') : ($ps['agriculture_type'] ?? '')) === 'विहीर' ? 'selected' : '' }}>विहीर</option>
                    <option value="कालवा" {{ (is_object($ps) ? ($ps->agriculture_type ?? '') : ($ps['agriculture_type'] ?? '')) === 'कालवा' ? 'selected' : '' }}>कालवा</option>
                    <option value="इतर" {{ (is_object($ps) ? ($ps->agriculture_type ?? '') : ($ps['agriculture_type'] ?? '')) === 'इतर' ? 'selected' : '' }}>इतर</option>
                </select>
            </div>
            <div><label class="block text-sm mb-1">Total Land (acres)</label><input type="number" name="property_summary[total_land_acres]" value="{{ is_object($ps) ? ($ps->total_land_acres ?? '') : ($ps['total_land_acres'] ?? '') }}" step="0.01" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2 w-32"></div>
            <div><label class="block text-sm mb-1">Annual Agri Income</label><input type="number" name="property_summary[annual_agri_income]" value="{{ is_object($ps) ? ($ps->annual_agri_income ?? '') : ($ps['annual_agri_income'] ?? '') }}" step="0.01" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2 w-40"></div>
            <div class="md:col-span-2"><label class="block text-sm mb-1">Summary Notes</label><textarea name="property_summary[summary_notes]" rows="2" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ is_object($ps) ? ($ps->summary_notes ?? '') : ($ps['summary_notes'] ?? '') }}</textarea></div>
        </div>
    </div>
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Property assets</h3>
        @php $paRows = old('property_assets', $profile_property_assets ?? collect()); @endphp
        @foreach($paRows as $idx => $row)
            <div class="flex flex-wrap gap-4 items-end mb-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                <input type="hidden" name="property_assets[{{ $idx }}][id]" value="{{ is_object($row) ? $row->id : ($row['id'] ?? '') }}">
                <div class="flex flex-col gap-1"><label class="text-sm text-gray-600 dark:text-gray-400">Asset type</label>
                    <select name="property_assets[{{ $idx }}][asset_type_id]" class="form-select rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2 min-w-[140px]">
                        <option value="">Select</option>
                        @foreach($assetTypes ?? [] as $item)
                            <option value="{{ $item->id }}" {{ (string)(is_object($row) ? ($row->asset_type_id ?? '') : ($row['asset_type_id'] ?? '')) === (string)$item->id ? 'selected' : '' }}>{{ $item->label }}</option>
                        @endforeach
                    </select>
                </div>
                <input type="text" name="property_assets[{{ $idx }}][location]" value="{{ is_object($row) ? $row->location : ($row['location'] ?? '') }}" placeholder="Location" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <input type="number" name="property_assets[{{ $idx }}][estimated_value]" value="{{ is_object($row) ? $row->estimated_value : ($row['estimated_value'] ?? '') }}" step="0.01" placeholder="Value" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2 w-32">
                <div class="flex flex-col gap-1"><label class="text-sm text-gray-600 dark:text-gray-400">Ownership</label>
                    <select name="property_assets[{{ $idx }}][ownership_type_id]" class="form-select rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2 min-w-[140px]">
                        <option value="">Select</option>
                        @foreach($ownershipTypes ?? [] as $item)
                            <option value="{{ $item->id }}" {{ (string)(is_object($row) ? ($row->ownership_type_id ?? '') : ($row['ownership_type_id'] ?? '')) === (string)$item->id ? 'selected' : '' }}>{{ $item->label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endforeach
    </div>
</div>
