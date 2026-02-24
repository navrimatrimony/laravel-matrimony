{{-- Phase-5 SSOT: Contacts — primary + additional contacts --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Contacts</h2>
    @php
        $primary = collect($profile_contacts ?? [])->firstWhere('is_primary', true);
        $primaryPhone = old('primary_contact_number', $primary ? (is_object($primary) ? ($primary->phone_number ?? '') : ($primary['phone_number'] ?? '')) : '');
    @endphp
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Primary contact number</label>
        <input type="text" name="primary_contact_number" value="{{ $primaryPhone }}" placeholder="Phone number" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
    </div>
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Additional contacts</h3>
        @php $contactRows = old('contacts', collect($profile_contacts ?? [])->where('is_primary', false)->values()->all()); @endphp
        @foreach($contactRows as $idx => $row)
            @php $r = is_object($row) ? (array) $row : $row; @endphp
            <div class="flex flex-wrap gap-4 items-end mb-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded">
                <input type="hidden" name="contacts[{{ $idx }}][id]" value="{{ $r['id'] ?? '' }}">
                <input type="text" name="contacts[{{ $idx }}][contact_name]" value="{{ $r['contact_name'] ?? '' }}" placeholder="Name" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <input type="text" name="contacts[{{ $idx }}][phone_number]" value="{{ $r['phone_number'] ?? '' }}" placeholder="Phone" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <input type="text" name="contacts[{{ $idx }}][relation_type]" value="{{ $r['relation_type'] ?? $r['contact_relation_id'] ?? '' }}" placeholder="Relation" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <label class="flex items-center gap-2"><input type="checkbox" name="contacts[{{ $idx }}][is_primary]" value="1" {{ !empty($r['is_primary']) ? 'checked' : '' }}> Primary</label>
            </div>
        @endforeach
    </div>
</div>
