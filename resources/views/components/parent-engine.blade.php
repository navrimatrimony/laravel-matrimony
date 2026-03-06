@props([
    'profile' => null,
])
@php
    $profile = $profile ?? new \stdClass();
    $fatherContacts = [
        old('father_contact_1', $profile->father_contact_1 ?? ''),
        old('father_contact_2', $profile->father_contact_2 ?? ''),
        old('father_contact_3', $profile->father_contact_3 ?? ''),
    ];
    $fatherCount = max(1, count(array_filter($fatherContacts, fn($v) => trim((string)$v) !== '')));
    $motherContacts = [
        old('mother_contact_1', $profile->mother_contact_1 ?? ''),
        old('mother_contact_2', $profile->mother_contact_2 ?? ''),
        old('mother_contact_3', $profile->mother_contact_3 ?? ''),
    ];
    $motherCount = max(1, count(array_filter($motherContacts, fn($v) => trim((string)$v) !== '')));
@endphp

<div class="parent-engine border-2 border-rose-500 dark:border-rose-400 rounded-lg p-4 space-y-5">
    {{-- Line 1: Father — name + occupation --}}
    <div class="flex flex-col md:flex-row md:items-end gap-3 md:gap-4">
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Father Name</label>
            <input type="text" name="father_name" value="{{ old('father_name', $profile->father_name ?? null) }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Father Occupation</label>
            <input type="text" name="father_occupation" value="{{ old('father_occupation', $profile->father_occupation ?? null) }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
    </div>

    {{-- Line 2: Father — extra info + up to 3 contact numbers (+ adds in-context) --}}
    <div class="flex flex-col md:flex-row md:items-end gap-3 md:gap-4">
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Father Extra Information</label>
            <input type="text" name="father_extra_info" value="{{ old('father_extra_info', $profile->father_extra_info ?? '') }}" maxlength="255" placeholder="e.g. Retired from MSEB, Kolhapur" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="flex-1 min-w-0" data-contact-context="father" data-max-slots="3">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Father Contact number</label>
            <div class="flex flex-wrap items-end gap-2" id="father-contact-slots-inner">
                @for($i = 0; $i < $fatherCount; $i++)
                    @php
                        $nameNum = 'father_contact_' . ($i + 1);
                        $nameWa = 'father_contact_whatsapp_' . ($i + 1);
                        $showAdd = $i < 2;
                    @endphp
                    <div class="parent-contact-slot {{ $i === 0 ? 'w-full basis-full' : 'shrink-0' }}" data-slot-index="{{ $i }}">
                        <x-profile.contact-field
                            :name="$nameNum"
                            :value="$fatherContacts[$i] ?? ''"
                            label=""
                            placeholder="10-digit"
                            :showCountryCode="true"
                            :showWhatsapp="true"
                            :nameWhatsapp="$nameWa"
                            :valueWhatsapp="(bool) old($nameWa, false)"
                            inputClass="flex-1 min-w-0"
                            :showAddButton="$showAdd"
                        />
                    </div>
                @endfor
            </div>
        </div>
    </div>

    {{-- Line 3: Mother — name + occupation --}}
    <div class="flex flex-col md:flex-row md:items-end gap-3 md:gap-4">
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mother Name</label>
            <input type="text" name="mother_name" value="{{ old('mother_name', $profile->mother_name ?? null) }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mother Occupation</label>
            <input type="text" name="mother_occupation" value="{{ old('mother_occupation', $profile->mother_occupation ?? null) }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
    </div>

    {{-- Line 4: Mother — extra info + up to 3 contact numbers (+ adds in-context) --}}
    <div class="flex flex-col md:flex-row md:items-end gap-3 md:gap-4">
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mother Extra Information</label>
            <input type="text" name="mother_extra_info" value="{{ old('mother_extra_info', $profile->mother_extra_info ?? '') }}" maxlength="255" placeholder="e.g. Housewife, stays with son in Pune" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div class="flex-1 min-w-0" data-contact-context="mother" data-max-slots="3">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mother Contact number</label>
            <div class="flex flex-wrap items-end gap-2" id="mother-contact-slots-inner">
                @for($i = 0; $i < $motherCount; $i++)
                    @php
                        $nameNum = 'mother_contact_' . ($i + 1);
                        $nameWa = 'mother_contact_whatsapp_' . ($i + 1);
                        $showAdd = $i < 2;
                    @endphp
                    <div class="parent-contact-slot {{ $i === 0 ? 'w-full basis-full' : 'shrink-0' }}" data-slot-index="{{ $i }}">
                        <x-profile.contact-field
                            :name="$nameNum"
                            :value="$motherContacts[$i] ?? ''"
                            label=""
                            placeholder="10-digit"
                            :showCountryCode="true"
                            :showWhatsapp="true"
                            :nameWhatsapp="$nameWa"
                            :valueWhatsapp="(bool) old($nameWa, false)"
                            inputClass="flex-1 min-w-0"
                            :showAddButton="$showAdd"
                        />
                    </div>
                @endfor
            </div>
        </div>
    </div>

    {{-- Line 5: Address extended address engine --}}
    <div class="mt-3">
        <x-profile.location-typeahead
            context="residence"
            mode="full"
            detailedLabel="Parents home address"
            detailedPlaceholder="Flat / house / society / road / landmark"
            detailedValue="{{ old('parents_address_line', $profile->address_line ?? '') }}"
            detailedName="parents_address_line"
            :value="old('wizard_parents_city_display', $profile->city?->name ?? '')"
            placeholder="Type village / city / pincode for parents' home"
            label="Parents village / city"
            :data-country-id="old('country_id', $profile->country_id ?? null)"
            :data-state-id="old('state_id', $profile->state_id ?? null)"
            :data-district-id="old('district_id', $profile->district_id ?? null)"
            :data-taluka-id="old('taluka_id', $profile->taluka_id ?? null)"
            :data-city-id="old('city_id', $profile->city_id ?? null)"
        />
    </div>
</div>

<template id="parent-father-slot-tpl">
    <div class="parent-contact-slot shrink-0" data-slot-index="">
        <div class="contact-field-engine border-2 border-rose-500 dark:border-rose-400 rounded-lg p-3">
            <div class="flex items-center gap-1.5 flex-nowrap contact-master-field">
                <input type="text" inputmode="tel" maxlength="5" value="+91" name="__FATHER_CC__" placeholder="+91" class="text-xs text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 rounded py-1.5 bg-gray-50 dark:bg-gray-700 h-9 box-border text-center shrink-0" style="flex:0 0 2.25rem; width:2.25rem; min-width:2.25rem; max-width:2.25rem;">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" name="__FATHER_NAME__" placeholder="10-digit" data-contact-engine class="h-9 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0 flex-1">
                <input type="hidden" name="__FATHER_WA__" value="call" class="contact-preference-input">
                <div class="relative shrink-0 contact-preference-single" data-current-pref="call">
                    <button type="button" class="contact-pref-trigger rounded p-1.5 ring-2 ring-rose-500 bg-rose-50 dark:bg-rose-900/30 inline-flex items-center justify-center" title="Prefer contact via — click to change" aria-haspopup="true" aria-expanded="false">
                        <span class="contact-pref-icon contact-pref-icon-whatsapp" data-pref="whatsapp" style="display:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></span>
                        <span class="contact-pref-icon contact-pref-icon-call text-red-500 dark:text-red-400" data-pref="call"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                        <span class="contact-pref-icon contact-pref-icon-message" data-pref="message" style="display:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg></span>
                    </button>
                    <div class="contact-pref-dropdown hidden absolute right-0 top-full mt-1 z-50 min-w-[8rem] py-1 rounded-lg shadow-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600">
                        <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="whatsapp"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> WhatsApp</button>
                        <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="call"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-red-500 dark:text-red-400"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> Call</button>
                        <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="message"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg> Message</button>
                    </div>
                </div>
                <button type="button" class="contact-engine-add-btn shrink-0 inline-flex items-center justify-center w-9 h-9 rounded border-2 border-rose-500 dark:border-rose-400 bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-300 font-bold text-lg leading-none hover:bg-rose-100 dark:hover:bg-rose-800/50" title="Add another contact" aria-label="Add contact">+</button>
            </div>
        </div>
    </div>
</template>
<template id="parent-mother-slot-tpl">
    <div class="parent-contact-slot shrink-0" data-slot-index="">
        <div class="contact-field-engine border-2 border-rose-500 dark:border-rose-400 rounded-lg p-3">
            <div class="flex items-center gap-1.5 flex-nowrap contact-master-field">
                <input type="text" inputmode="tel" maxlength="5" value="+91" name="__MOTHER_CC__" placeholder="+91" class="text-xs text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 rounded py-1.5 bg-gray-50 dark:bg-gray-700 h-9 box-border text-center shrink-0" style="flex:0 0 2.25rem; width:2.25rem; min-width:2.25rem; max-width:2.25rem;">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" name="__MOTHER_NAME__" placeholder="10-digit" data-contact-engine class="h-9 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0 flex-1">
                <input type="hidden" name="__MOTHER_WA__" value="call" class="contact-preference-input">
                <div class="relative shrink-0 contact-preference-single" data-current-pref="call">
                    <button type="button" class="contact-pref-trigger rounded p-1.5 ring-2 ring-rose-500 bg-rose-50 dark:bg-rose-900/30 inline-flex items-center justify-center" title="Prefer contact via — click to change" aria-haspopup="true" aria-expanded="false">
                        <span class="contact-pref-icon contact-pref-icon-whatsapp" data-pref="whatsapp" style="display:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></span>
                        <span class="contact-pref-icon contact-pref-icon-call text-red-500 dark:text-red-400" data-pref="call"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                        <span class="contact-pref-icon contact-pref-icon-message" data-pref="message" style="display:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg></span>
                    </button>
                    <div class="contact-pref-dropdown hidden absolute right-0 top-full mt-1 z-50 min-w-[8rem] py-1 rounded-lg shadow-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600">
                        <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="whatsapp"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> WhatsApp</button>
                        <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="call"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-red-500 dark:text-red-400"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> Call</button>
                        <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="message"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg> Message</button>
                    </div>
                </div>
                <button type="button" class="contact-engine-add-btn shrink-0 inline-flex items-center justify-center w-9 h-9 rounded border-2 border-rose-500 dark:border-rose-400 bg-rose-50 dark:bg-rose-900/30 text-rose-600 dark:text-rose-300 font-bold text-lg leading-none hover:bg-rose-100 dark:hover:bg-rose-800/50" title="Add another contact" aria-label="Add contact">+</button>
            </div>
        </div>
    </div>
</template>

<script>
(function() {
    var maxSlots = 3;
    document.querySelectorAll('[data-contact-context="father"], [data-contact-context="mother"]').forEach(function(ctx) {
        var isFather = ctx.getAttribute('data-contact-context') === 'father';
        var inner = ctx.querySelector(isFather ? '#father-contact-slots-inner' : '#mother-contact-slots-inner');
        var tplId = isFather ? 'parent-father-slot-tpl' : 'parent-mother-slot-tpl';
        var prefix = isFather ? 'father_contact' : 'mother_contact';
        var tpl = document.getElementById(tplId);
        if (!inner || !tpl) return;
        ctx.addEventListener('click', function(e) {
            if (!e.target.closest('.contact-engine-add-btn')) return;
            var slots = inner.querySelectorAll('.parent-contact-slot');
            if (slots.length >= maxSlots) return;
            var nextIdx = slots.length;
            var nameNum = prefix + '_' + (nextIdx + 1);
            var nameWa = prefix + '_whatsapp_' + (nextIdx + 1);
            var nameCC = prefix + '_cc_' + (nextIdx + 1);
            var html = tpl.innerHTML
                .replace(/__FATHER_NAME__|__MOTHER_NAME__/g, nameNum)
                .replace(/__FATHER_WA__|__MOTHER_WA__/g, nameWa)
                .replace(/__FATHER_CC__|__MOTHER_CC__/g, nameCC);
            var div = document.createElement('div');
            div.innerHTML = html.trim();
            var newSlot = div.firstChild;
            inner.appendChild(newSlot);
            if (nextIdx === 2) newSlot.querySelector('.contact-engine-add-btn')?.remove();
        });
    });
})();
</script>
