{{-- Phase-5 SSOT: Contacts — primary + additional contacts --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Contacts</h2>
    @php
        $primary = collect($profile_contacts ?? [])->firstWhere('is_primary', true);
        $primaryPhone = old('primary_contact_number', $primary ? (is_object($primary) ? ($primary->phone_number ?? '') : ($primary['phone_number'] ?? '')) : '');
        $primaryWhatsapp = old('primary_contact_whatsapp', $primary ? (is_object($primary) ? ($primary->is_whatsapp ?? false) : ($primary['is_whatsapp'] ?? false)) : false);
    @endphp
    <div>
        <x-profile.contact-field
            name="primary_contact_number"
            :value="$primaryPhone"
            label="Primary contact number"
            placeholder="10-digit number"
            :showCountryCode="true"
            :showWhatsapp="true"
            nameWhatsapp="primary_contact_whatsapp"
            :valueWhatsapp="$primaryWhatsapp"
            inputClass="flex-1 min-w-0"
        />
    </div>
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Additional contacts</h3>
        @php $contactRows = old('contacts', collect($profile_contacts ?? [])->where('is_primary', false)->values()->all()); @endphp
        @foreach($contactRows as $idx => $row)
            @php $r = is_object($row) ? (array) $row : $row; @endphp
            <div class="flex flex-wrap gap-4 items-end mb-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                <input type="hidden" name="contacts[{{ $idx }}][id]" value="{{ $r['id'] ?? '' }}">
                <input type="text" name="contacts[{{ $idx }}][contact_name]" value="{{ $r['contact_name'] ?? '' }}" placeholder="Name" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <x-profile.contact-field
                    name="contacts[{{ $idx }}][phone_number]"
                    :value="$r['phone_number'] ?? ''"
                    label=""
                    placeholder="10-digit"
                    :showCountryCode="true"
                    :showWhatsapp="true"
                    nameWhatsapp="contacts[{{ $idx }}][is_whatsapp]"
                    :valueWhatsapp="!empty($r['is_whatsapp'])"
                    inputClass="flex-1 min-w-0 max-w-[10rem]"
                />
                <input type="text" name="contacts[{{ $idx }}][relation_type]" value="{{ $r['relation_type'] ?? $r['contact_relation_id'] ?? '' }}" placeholder="Relation" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <label class="flex items-center gap-2"><input type="checkbox" name="contacts[{{ $idx }}][is_primary]" value="1" {{ !empty($r['is_primary']) ? 'checked' : '' }}> Primary</label>
            </div>
        @endforeach
    </div>
</div>
