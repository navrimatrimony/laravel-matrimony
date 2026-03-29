{{-- Phase-5 SSOT: Contacts — self (up to 3 numbers, + adds in-context) + additional contacts (other people, no + on number). --}}
@php
    $selfContacts = $self_contacts ?? [];
    $selfContactsSaved = isset($self_contacts) && is_iterable($self_contacts) ? collect($self_contacts)->all() : [];
    $selfCount = count($selfContacts);
    if ($selfCount === 0) { $selfCount = 1; }
@endphp
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('wizard.contacts') }}</h2>

    {{-- Your contact numbers: centralized engine + "+ Add / Remove this entry" pattern (max 3 self numbers). --}}
    <div class="space-y-2" data-self-contact-engine="1">
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('wizard.your_contact_numbers') }}</h3>
        {{-- When 2 numbers: show them side-by-side (approx 50/50) on wider screens. --}}
        <div class="grid gap-2 sm:grid-cols-2" id="self-contact-slots-inner">
            @for($i = 0; $i < $selfCount; $i++)
                @php
                    $sc = $selfContacts[$i] ?? null;
                    $nameNum = $i === 0 ? 'primary_contact_number' : 'primary_contact_number_' . ($i + 1);
                    $nameWa  = $i === 0 ? 'primary_contact_whatsapp' : 'primary_contact_whatsapp_' . ($i + 1);
                    $phone = old($nameNum, $sc ? (is_object($sc) ? ($sc->phone_number ?? '') : ($sc['phone_number'] ?? '')) : '');
                    $prefDefault = $sc ? (is_object($sc) ? ($sc->contact_preference ?? ($sc->is_whatsapp ? 'whatsapp' : 'call')) : ($sc['contact_preference'] ?? (!empty($sc['is_whatsapp']) ? 'whatsapp' : 'call'))) : 'whatsapp';
                    $whatsapp = old($nameWa, $prefDefault);
                @endphp
                <div class="self-contact-slot border border-gray-200 dark:border-gray-600 rounded-lg p-3 space-y-2" data-slot-index="{{ $i }}">
                    <x-profile.contact-field
                        :name="$nameNum"
                        :value="$phone"
                        :label="$i === 0 ? __('wizard.primary_contact_number') : ''"
                        :placeholder="__('wizard.placeholder_10_digit')"
                        :showCountryCode="true"
                        :showWhatsapp="true"
                        :nameWhatsapp="$nameWa"
                        :valueWhatsapp="$whatsapp"
                        inputClass="flex-1 min-w-0"
                        :showAddButton="false"
                    />
                    <div class="flex justify-between items-center">
                        <span role="button" tabindex="0" class="text-xs font-medium text-blue-600 dark:text-blue-400 cursor-pointer self-contact-add">
                            + {{ __('wizard.add') }}
                        </span>
                        <button type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline self-contact-remove">
                            {{ __('wizard.remove_entry') }}
                        </button>
                    </div>
                </div>
            @endfor
        </div>
        {{-- Hidden template for new self-contact slots --}}
        <template id="self-contact-slot-template">
            <div class="self-contact-slot border border-gray-200 dark:border-gray-600 rounded-lg p-3 space-y-2" data-slot-index="__INDEX__">
                <x-profile.contact-field
                    name="__NAME__"
                    value=""
                    label=""
                    :placeholder="__('wizard.placeholder_10_digit')"
                    :showCountryCode="true"
                    :showWhatsapp="true"
                    nameWhatsapp="__NAME_WHATSAPP__"
                    :valueWhatsapp="false"
                    inputClass="flex-1 min-w-0"
                    :showAddButton="false"
                />
                <div class="flex justify-between items-center">
                    <span role="button" tabindex="0" class="text-xs font-medium text-blue-600 dark:text-blue-400 cursor-pointer self-contact-add">
                        + Add
                    </span>
                    <button type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline self-contact-remove">
                        Remove this entry
                    </button>
                </div>
            </div>
        </template>
    </div>

    @if(count($selfContactsSaved) > 0)
        <div class="mt-4 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3 bg-white/50 dark:bg-gray-800/40">
            <h3 class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('contact_verify.saved_self_numbers') }}</h3>
            <ul class="space-y-3 text-sm">
                @foreach($selfContactsSaved as $scRow)
                    @php $sc = is_object($scRow) ? (array) $scRow : $scRow; @endphp
                    @if(! empty($sc['id']))
                        <li class="flex flex-col gap-2 border-b border-gray-100 dark:border-gray-700 pb-3 last:border-0 last:pb-0">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-mono text-gray-900 dark:text-gray-100">{{ $sc['phone_number'] ?? '' }}</span>
                                @if(! empty($sc['is_primary']))
                                    <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">{{ __('contact_verify.badge_primary') }}</span>
                                @else
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('contact_verify.badge_additional_self') }}</span>
                                @endif
                                @if(! empty($sc['verified_status']))
                                    <span class="text-xs font-semibold text-sky-700 dark:text-sky-300">{{ __('contact_verify.badge_verified') }}</span>
                                @else
                                    <span class="text-xs text-amber-700 dark:text-amber-300">{{ __('contact_verify.badge_not_verified') }}</span>
                                @endif
                            </div>
                            @if(empty($sc['verified_status']))
                                <div class="flex flex-wrap items-end gap-2">
                                    <form method="POST" action="{{ route('matrimony.profile.contacts.send-otp', ['contact' => $sc['id']]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="px-2 py-1 text-xs rounded bg-gray-200 dark:bg-gray-600 text-gray-900 dark:text-gray-100 hover:opacity-90">{{ __('contact_verify.send_code') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('matrimony.profile.contacts.verify-otp', ['contact' => $sc['id']]) }}" class="inline flex flex-wrap items-center gap-2">
                                        @csrf
                                        <input type="text" name="otp" inputmode="numeric" maxlength="6" pattern="[0-9]*" autocomplete="one-time-code" class="w-24 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-2 py-1 text-xs" placeholder="{{ __('contact_verify.otp_placeholder') }}">
                                        <button type="submit" class="px-2 py-1 text-xs rounded bg-sky-600 text-white hover:bg-sky-700">{{ __('contact_verify.verify') }}</button>
                                    </form>
                                </div>
                            @endif
                            @if(empty($sc['is_primary']))
                                @if(! empty($sc['verified_status']))
                                    <form method="POST" action="{{ route('matrimony.profile.contacts.promote-primary', ['contact' => $sc['id']]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="px-2 py-1 text-xs rounded bg-emerald-600 text-white hover:bg-emerald-700">{{ __('contact_verify.make_primary') }}</button>
                                    </form>
                                @else
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('contact_verify.verify_before_primary') }}</p>
                                @endif
                            @endif
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Additional contacts: other people (name, number, relation) — no + on number field. --}}
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.additional_contacts') }}</h3>
        <div id="wizard-additional-contacts-container">
            @php
                $contactRows = old('contacts', $profile_contacts ?? []);
                $contactRows = is_array($contactRows) ? $contactRows : collect($contactRows)->all();
                if (count($contactRows) === 0) {
                    $contactRows = [[]]; // default one empty engine box visible
                }
            @endphp
            @foreach($contactRows as $idx => $row)
                @php $r = is_object($row) ? (array) $row : $row; @endphp
                <div class="wizard-contact-row flex flex-wrap gap-4 items-end mb-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded border-2 border-rose-500/30 dark:border-rose-400/30 rounded-lg">
                    <input type="hidden" name="contacts[{{ $idx }}][id]" value="{{ $r['id'] ?? '' }}">
                    <input type="text" name="contacts[{{ $idx }}][contact_name]" value="{{ $r['contact_name'] ?? '' }}" placeholder="{{ __('wizard.placeholder_name') }}" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <x-profile.contact-field
                        name="contacts[{{ $idx }}][phone_number]"
                        :value="$r['phone_number'] ?? ''"
                        label=""
                        :placeholder="__('wizard.placeholder_10_digit_short')"
                        :showCountryCode="true"
                        :showWhatsapp="true"
                        nameWhatsapp="contacts[{{ $idx }}][is_whatsapp]"
                        :valueWhatsapp="in_array($r['contact_preference'] ?? null, ['whatsapp','call','message'], true) ? ($r['contact_preference']) : (!empty($r['is_whatsapp']) ? 'whatsapp' : 'call')"
                        inputClass="flex-1 min-w-0 max-w-[10rem]"
                    />
                    <input type="text" name="contacts[{{ $idx }}][relation_type]" value="{{ $r['relation_type'] ?? $r['contact_relation_id'] ?? '' }}" placeholder="{{ __('wizard.placeholder_relation') }}" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <label class="flex items-center gap-2"><input type="checkbox" name="contacts[{{ $idx }}][is_primary]" value="1" {{ !empty($r['is_primary']) ? 'checked' : '' }}> {{ __('wizard.primary') }}</label>
                    <div class="flex-1 flex justify-end">
                        <button type="button" class="wizard-remove-contact text-xs text-red-600 dark:text-red-400 hover:underline">{{ __('wizard.remove_entry') }}</button>
                    </div>
                </div>
            @endforeach
        </div>
        <template id="wizard-contact-row-template">
            <div class="wizard-contact-row flex flex-wrap gap-4 items-end mb-3 p-3 bg-gray-50 dark:bg-gray-700/50 rounded border-2 border-rose-500/30 dark:border-rose-400/30 rounded-lg">
                <input type="hidden" name="contacts[__INDEX__][id]" value="">
                <input type="text" name="contacts[__INDEX__][contact_name]" value="" placeholder="{{ __('wizard.placeholder_name') }}" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <div class="contact-field-engine border-2 border-rose-500 dark:border-rose-400 rounded-lg p-3 flex-1 min-w-0 max-w-[14rem]">
                    <div class="flex items-center gap-1.5 flex-nowrap contact-master-field">
                        <input type="text" inputmode="tel" maxlength="5" value="+91" placeholder="+91" title="Country code" class="text-xs text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 rounded py-1.5 bg-gray-50 dark:bg-gray-700 h-9 box-border text-center shrink-0 contact-cc-input" style="flex:0 0 2.25rem; width:2.25rem; min-width:2.25rem; max-width:2.25rem; padding-left:0.2rem; padding-right:0.2rem;">
                        <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" name="contacts[__INDEX__][phone_number]" placeholder="{{ __('wizard.placeholder_10_digit_short') }}" data-contact-engine class="h-9 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0 flex-1" style="min-width:0;">
                        <input type="hidden" name="contacts[__INDEX__][is_whatsapp]" value="whatsapp" class="contact-preference-input">
                        <div class="relative shrink-0 contact-preference-single" data-current-pref="whatsapp">
                            <button type="button" class="contact-pref-trigger rounded p-1.5 ring-2 ring-rose-500 bg-rose-50 dark:bg-rose-900/30 inline-flex items-center justify-center" title="Prefer contact via — click to change" aria-haspopup="true" aria-expanded="false">
                                <span class="contact-pref-icon contact-pref-icon-whatsapp" data-pref="whatsapp"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></span>
                                <span class="contact-pref-icon contact-pref-icon-call text-red-500 dark:text-red-400" data-pref="call" style="display:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                                <span class="contact-pref-icon contact-pref-icon-message" data-pref="message" style="display:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg></span>
                            </button>
                            <div class="contact-pref-dropdown hidden absolute right-0 top-full mt-1 z-50 min-w-[8rem] py-1 rounded-lg shadow-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600">
                                <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="whatsapp"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> WhatsApp</button>
                                <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="call"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-red-500 dark:text-red-400"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> Call</button>
                                <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="message"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg> Message</button>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="text" name="contacts[__INDEX__][relation_type]" value="" placeholder="{{ __('wizard.placeholder_relation') }}" class="rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                <label class="flex items-center gap-2"><input type="checkbox" name="contacts[__INDEX__][is_primary]" value="1"> {{ __('wizard.primary') }}</label>
                <div class="flex-1 flex justify-end">
                    <button type="button" class="wizard-remove-contact text-xs text-red-600 dark:text-red-400 hover:underline">{{ __('wizard.remove_entry') }}</button>
                </div>
            </div>
        </template>
        <button type="button" id="wizard-add-contact" class="mt-2 inline-flex items-center gap-1.5 px-3 py-2 bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 border-2 border-rose-500 dark:border-rose-400 rounded-lg font-medium text-xs hover:bg-rose-200 dark:hover:bg-rose-800/40">
            <span class="text-base leading-none" aria-hidden="true">+</span> {{ __('wizard.add') }}
        </button>
    </div>
</div>
<script>
(function() {
    // Self contact engine: + Add / Remove this entry (max 3 slots)
    var selfInner = document.getElementById('self-contact-slots-inner');
    var selfTemplate = document.getElementById('self-contact-slot-template');
    var maxSelfSlots = 3;

    function renumberSelfSlots() {
        if (!selfInner) return;
        var slots = selfInner.querySelectorAll('.self-contact-slot');
        slots.forEach(function(slot, index) {
            slot.setAttribute('data-slot-index', index);
            var isPrimary = index === 0;
            slot.querySelectorAll('input[name], input[data-contact-engine], input.contact-preference-input').forEach(function(inp) {
                var n = inp.getAttribute('name') || '';
                n = n.replace(/primary_contact_number(_\d+)?/, isPrimary ? 'primary_contact_number' : ('primary_contact_number_' + (index + 1)));
                n = n.replace(/primary_contact_whatsapp(_\d+)?/, isPrimary ? 'primary_contact_whatsapp' : ('primary_contact_whatsapp_' + (index + 1)));
                inp.setAttribute('name', n);
            });
            var labelEl = slot.querySelector('label');
            if (labelEl && !isPrimary) {
                labelEl.textContent = '';
            }
        });
    }

    if (selfInner && selfTemplate) {
        selfInner.addEventListener('click', function(e) {
            var addBtn = e.target.closest('.self-contact-add');
            var removeBtn = e.target.closest('.self-contact-remove');
            var slots = selfInner.querySelectorAll('.self-contact-slot');

            if (addBtn) {
                if (slots.length >= maxSelfSlots) return;
                var nextIndex = slots.length;
                var html = selfTemplate.innerHTML
                    .replace(/__INDEX__/g, String(nextIndex))
                    .replace(/__NAME__/g, nextIndex === 0 ? 'primary_contact_number' : 'primary_contact_number_' + (nextIndex + 1))
                    .replace(/__NAME_WHATSAPP__/g, nextIndex === 0 ? 'primary_contact_whatsapp' : 'primary_contact_whatsapp_' + (nextIndex + 1));
                var div = document.createElement('div');
                div.innerHTML = html.trim();
                selfInner.appendChild(div.firstChild);
                renumberSelfSlots();
                return;
            }

            if (removeBtn) {
                if (slots.length <= 1) {
                    // Last slot: just clear values instead of removing block
                    var last = slots[0];
                    if (last) {
                        last.querySelectorAll('input[type="text"], input[data-contact-engine]').forEach(function(inp) {
                            inp.value = '';
                        });
                    }
                    return;
                }
                var row = removeBtn.closest('.self-contact-slot');
                if (row) {
                    row.remove();
                    renumberSelfSlots();
                }
            }
        });
    }

    var container = document.getElementById('wizard-additional-contacts-container');
    var template = document.getElementById('wizard-contact-row-template');
    var addBtn = document.getElementById('wizard-add-contact');
    function addAdditionalContactRow() {
        if (!container || !template) return;
        var nextIndex = container.querySelectorAll('.wizard-contact-row').length;
        var html = template.innerHTML.replace(/__INDEX__/g, nextIndex);
        var div = document.createElement('div');
        div.innerHTML = html.trim();
        container.appendChild(div.firstChild);
    }
    if (addBtn) addBtn.addEventListener('click', addAdditionalContactRow);
    if (container) {
        container.addEventListener('click', function(e) {
            if (e.target.classList.contains('wizard-remove-contact')) {
                e.target.closest('.wizard-contact-row').remove();
            }
        });
    }
})();
</script>
