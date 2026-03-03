{{-- Phase-5B: Location - core city + Native & Residence + addresses (village free-type). Uses reusable location-typeahead component. --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Location</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
            <x-profile.location-typeahead
                context="residence"
                :value="old('wizard_city_display', $profile->city?->name ?? '')"
                placeholder="Type village / city / pincode"
                label="Search village or city (residence)"
                :data-country-id="old('country_id', $profile->country_id)"
                :data-state-id="old('state_id', $profile->state_id)"
                :data-district-id="old('district_id', $profile->district_id)"
                :data-taluka-id="old('taluka_id', $profile->taluka_id)"
                :data-city-id="old('city_id', $profile->city_id)"
            />
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 mt-2">Address line (optional)</label>
            <input type="text" name="address_line" value="{{ old('address_line', $profile->address_line ?? '') }}" maxlength="255" placeholder="e.g. Building, area, landmark" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="md:col-span-2">
            <x-profile.location-typeahead
                context="work"
                :value="old('wizard_work_display', $workCityName ?? '')"
                placeholder="Type city / area for work"
                label="Work location (optional)"
                :data-work-city-id="old('work_city_id', $profile->work_city_id)"
                :data-work-state-id="old('work_state_id', $profile->work_state_id)"
            />
        </div>
        <div class="md:col-span-2 pt-2 border-t border-gray-200 dark:border-gray-600">
            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Native & Residence</h3>
            <x-profile.location-typeahead
                context="native"
                :value="old('wizard_native_display', $nativePlaceDisplay ?? '')"
                placeholder="Type native place city / area"
                label="Native place (optional)"
                :data-native-city-id="old('native_city_id', $profile->native_city_id)"
                :data-native-taluka-id="old('native_taluka_id', $profile->native_taluka_id)"
                :data-native-district-id="old('native_district_id', $profile->native_district_id)"
                :data-native-state-id="old('native_state_id', $profile->native_state_id)"
            />
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
document.addEventListener('DOMContentLoaded', function() { if (window.LocationTypeahead) window.LocationTypeahead.init(); });
</script>
