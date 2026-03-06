@extends('layouts.app')

@section('content')
<div class="container max-w-4xl mx-auto py-8 px-4">
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ route('intake.index') }}" class="hover:underline">← My biodata uploads</a>
    </p>
    <h1 class="text-2xl font-bold mb-2">Intake Preview</h1>
    <p class="text-gray-600 dark:text-gray-400 text-sm mb-6">तुमची माहिती तपासा आणि आवश्यक ते सुधारा. खाली स्क्रोल करून सर्व तपासल्यानंतरच अप्रूव्ह करा.</p>
    <p class="text-gray-500 dark:text-gray-500 text-xs mb-4">इथे बायोडाटा मधून काढलेली सर्व फील्ड्स दिसतात. अप्रूव्ह नंतर प्रोफाइल विझार्डमध्ये आणखी विभाग (जसे की स्थान, फोटो, विवाह इतिहास) भरता येतील.</p>

    <form id="intake-preview-form" method="POST" action="{{ route('intake.approve', $intake) }}" class="space-y-8">
        @csrf

        @php
            $sectionSourceKeys = $sectionSourceKeys ?? [];
            $coreData = $sections['core']['data'] ?? $data['core'] ?? [];
        @endphp

        {{-- Core Details (editable) — Religion/Caste/Subcaste use shared component (same as wizard). --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Core Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Religion / Caste / Sub caste — same component as wizard (consistent behavior). --}}
                <div class="md:col-span-2">
                    <x-profile.religion-caste-selector :profile="$intakeProfile ?? new \stdClass()" namePrefix="snapshot[core]" />
                </div>
                {{-- Physical Engine: Height, Complexion, Blood Group, Physical Build, Weight, Spectacles/Lens, Physical Condition (reuses wizard component). --}}
                <div class="md:col-span-2">
                    <x-physical-engine namePrefix="snapshot[core]" :values="$coreData" />
                </div>
                @foreach(['full_name','date_of_birth','gender','annual_income','family_income','primary_contact_number','serious_intent_id','highest_education','specialization','occupation_title','company_name','income_currency_id','father_name','mother_name','father_occupation','mother_occupation','family_type_id','birth_time','birth_place','gotra','kuldaivat','rashi','nadi','gan','mangalik','varna','mama','relatives','other_relatives_text'] as $coreKey)
                    @php
                        $val = $coreData[$coreKey] ?? '';
                        $conf = $confidenceMap[$coreKey] ?? null;
                        $confVal = $conf !== null && $conf !== '' ? (float)$conf : null;
                        $isCritical = in_array($coreKey, $criticalFields ?? [], true);
                        $isRequiredCorrection = in_array($coreKey, $requiredCorrectionFields ?? [], true);
                        $isWarning = in_array($coreKey, $warningFields ?? [], true);
                        $cls = 'w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600 ';
                        if ($isRequiredCorrection) $cls .= ' border-2 border-red-500 bg-red-50 dark:bg-red-900/20 ';
                        elseif ($isWarning) $cls .= ' border-2 border-amber-400 bg-amber-50 dark:bg-amber-900/20 ';
                        elseif ($isCritical) $cls .= ' border border-red-300 ';
                    @endphp
                    <div class="@if($isRequiredCorrection) required-correction-field @endif" data-field-key="core.{{ $coreKey }}">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            {{ str_replace('_',' ', ucfirst($coreKey)) }}
                            @if($isCritical)<span class="text-red-600">*</span>@endif
                            @if($isRequiredCorrection)<span class="text-red-600 text-xs">(सुधारणा आवश्यक)</span>@endif
                        </label>
						
                        @php
                            $suggestion = $suggestionMap[$coreKey] ?? null;
                            $placeholderNotFound = $placeholderNotFound ?? '⟪NOT FOUND IN OCR⟫';
                            $placeholderSelectRequired = $placeholderSelectRequired ?? '⟪SELECT REQUIRED⟫';
                            $selectedValue = $suggestion['selected_value'] ?? $val;
                            if (is_array($selectedValue)) { $selectedValue = json_encode($selectedValue); }
                            $rawVal = is_array($val) ? json_encode($val) : $val;
                            if ($rawVal === '—' || $rawVal === '–' || $rawVal === '-') { $rawVal = ''; }
                            $displayValue = old('snapshot.core.'.$coreKey, $selectedValue);
                            $originalSnapshot = $suggestion['original_value_snapshot'] ?? (is_array($val) ? json_encode($val) : $rawVal);
                            if (is_array($originalSnapshot)) { $originalSnapshot = json_encode($originalSnapshot); }
                            $requiredMissing = $suggestion['required_missing'] ?? false;
                            $isPlaceholderNotFound = (string)$displayValue === (string)$placeholderNotFound;
                            $isPlaceholderSelectRequired = (string)$displayValue === (string)$placeholderSelectRequired;
                            $isPlaceholder = $isPlaceholderNotFound || $isPlaceholderSelectRequired;
                            $autoFilled = $suggestion && ($suggestion['prefill_reason'] ?? '') === 'best_candidate';
                            $candidates = $suggestion['candidates'] ?? [];
                            $hasCandidates = count($candidates) > 0;
                            $needsReview = $suggestion['needs_review'] ?? false;
                            $originalOcrValue = $suggestion['original_ocr_value'] ?? null;
                        @endphp
                        <input type="text" name="snapshot[core][{{ $coreKey }}]" value="{{ $displayValue }}"
                            data-original="{{ e($originalOcrValue !== null && $originalOcrValue !== '' ? $originalOcrValue : $originalSnapshot) }}"
                            data-placeholder-value="{{ e($placeholderNotFound) }}"
                            data-placeholder-select-required="{{ e($placeholderSelectRequired) }}"
                            data-field-key="{{ $coreKey }}"
                            class="{{ $cls }} @if($isPlaceholder) border-red-600 bg-red-100 dark:bg-red-900/30 @endif"
                            data-required-correction="{{ $isRequiredCorrection ? '1' : '0' }}"
                            data-is-placeholder="{{ $isPlaceholder ? '1' : '0' }}"
                            placeholder="—">
                        @if($isPlaceholderSelectRequired)
                            <span class="text-xs text-red-600 dark:text-red-400 font-medium block mt-1">Please select a value (not found in biodata). Approval blocked until selected.</span>
                        @elseif($isPlaceholderNotFound)
                            <span class="text-xs text-red-600 dark:text-red-400 font-medium block mt-1">Please edit this value — not found in OCR. Approval blocked until changed.</span>
                        @endif
                        @if($hasCandidates)
                            <div class="mt-1 text-xs text-gray-600 dark:text-gray-400">Candidates:</div>
                            @foreach($candidates as $idx => $cand)
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $cand['value'] ?? '' }}</span>
                                    <span class="text-gray-500">({{ $cand['source'] ?? 'unknown' }}, {{ number_format(($cand['confidence'] ?? 0), 2) }})</span>
                                    <button type="button" class="use-candidate-btn text-xs text-indigo-700 underline" data-field="{{ $coreKey }}" data-value="{{ e($cand['value'] ?? '') }}">Use this</button>
                                </div>
                            @endforeach
                        @endif
                        @if($autoFilled && !$isPlaceholder)
                            <span class="text-xs text-blue-600 block mt-1">System Suggested (Review Recommended)</span>
                        @endif
                        @if(($coreKey === 'religion') && ($suggestion['inferred_from_caste'] ?? false) && !$isPlaceholder)
                            <span class="text-xs text-amber-600 dark:text-amber-400 font-medium block mt-1">Inferred from caste (Review)</span>
                        @endif
                        @if(($coreKey === 'caste') && !empty($originalOcrValue) && (string)$displayValue !== (string)$originalOcrValue)
                            <span class="text-xs text-gray-500 dark:text-gray-400 block mt-0.5">Original OCR: {{ $originalOcrValue }}</span>
                        @endif
                        @if(($suggestion['can_revert'] ?? true) && !$isPlaceholder)
                            <button type="button" class="revert-btn text-xs text-gray-600 underline mt-0.5" data-field="{{ $coreKey }}">Revert</button>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Contacts — centralized contact-field (country code + 10-digit + optional WhatsApp). --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Contacts</h2>
            <div id="contacts-container">
                @foreach(($sections['contacts']['data'] ?? []) as $idx => $contact)
                    @php
                        $contactPhone = is_array($contact) ? ($contact['phone_number'] ?? $contact['number'] ?? '') : (string) $contact;
                        $contactType = is_array($contact) ? ($contact['relation_type'] ?? $contact['type'] ?? ($idx === 0 ? 'self' : '')) : '';
                        $contactName = $idx === 0 ? 'Primary' : (is_array($contact) ? ($contact['contact_name'] ?? 'Alternate') : 'Alternate');
                        $contactPref = is_array($contact) && in_array($contact['contact_preference'] ?? null, ['whatsapp','call','message'], true)
                            ? ($contact['contact_preference']) : (!empty($contact['is_whatsapp']) ? 'whatsapp' : 'call');
                    @endphp
                    <div class="flex gap-4 mb-3 items-end contact-row">
                        <div class="flex-1 min-w-0">
                            <x-profile.contact-field
                                name="snapshot[contacts][{{ $idx }}][phone_number]"
                                :value="$contactPhone"
                                label=""
                                placeholder="10-digit"
                                :showCountryCode="true"
                                :showWhatsapp="true"
                                nameWhatsapp="snapshot[contacts][{{ $idx }}][is_whatsapp]"
                                :valueWhatsapp="$contactPref"
                                inputClass="flex-1 min-w-0"
                            />
                        </div>
                        <div class="flex-1 min-w-0 max-w-[8rem]">
                            <label class="block text-sm font-medium mb-1">Type</label>
                            <input type="text" name="snapshot[contacts][{{ $idx }}][relation_type]" value="{{ $contactType }}" placeholder="self / alternate" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600">
                        </div>
                        @if($idx === 0)
                            <input type="hidden" name="snapshot[contacts][0][is_primary]" value="1">
                            <input type="hidden" name="snapshot[contacts][0][contact_name]" value="Primary">
                        @else
                            <input type="hidden" name="snapshot[contacts][{{ $idx }}][contact_name]" value="Alternate">
                        @endif
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded hover:bg-red-50 shrink-0">Remove</button>
                    </div>
                @endforeach
            </div>
            <template id="contact-row-template">
                <div class="flex gap-4 mb-3 items-end contact-row">
                    <div class="flex-1 min-w-0">
                        <div class="contact-field-engine border-2 border-rose-500 dark:border-rose-400 rounded-lg p-3">
                            <div class="flex items-center gap-1.5 flex-nowrap contact-master-field">
                                <input type="text" inputmode="tel" maxlength="5" value="+91" placeholder="+91" title="Country code" class="text-xs text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 rounded py-1.5 bg-gray-50 dark:bg-gray-700 h-9 box-border text-center shrink-0 contact-cc-input" style="flex:0 0 2.25rem; width:2.25rem; min-width:2.25rem; max-width:2.25rem; padding-left:0.2rem; padding-right:0.2rem;">
                                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" name="snapshot[contacts][__INDEX__][phone_number]" placeholder="10-digit" data-contact-engine class="h-9 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0 flex-1" style="min-width:0;">
                                <input type="hidden" name="snapshot[contacts][__INDEX__][is_whatsapp]" value="call" class="contact-preference-input">
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
                            </div>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0 max-w-[8rem]">
                        <label class="block text-sm font-medium mb-1">Type</label>
                        <input type="text" name="snapshot[contacts][__INDEX__][relation_type]" placeholder="alternate" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600">
                    </div>
                    <input type="hidden" name="snapshot[contacts][__INDEX__][contact_name]" value="Alternate">
                    <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded hover:bg-red-50 shrink-0">Remove</button>
                </div>
            </template>
            <button type="button" id="add-contact" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded hover:bg-gray-300">+ Add Contact</button>
        </section>

        {{-- Marital status + Children: same MaritalEngine as wizard (namePrefix=snapshot for intake). --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Marital status</h2>
            @include('matrimony.profile.wizard.sections.marital_engine', [
                'profile' => $intakeProfile,
                'maritalStatuses' => $maritalStatuses ?? collect(),
                'profileMarriages' => $profileMarriages ?? collect(),
                'profileChildren' => $profileChildren ?? collect(),
                'childLivingWithOptions' => $childLivingWithOptions ?? collect(),
                'namePrefix' => 'snapshot',
            ])
        </section>

        {{-- Education --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Education</h2>
            <div id="education-container">
                @foreach(($sections['education']['data'] ?? []) as $idx => $edu)
                    <div class="flex gap-4 mb-3 items-end education-row">
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Degree</label><input type="text" name="snapshot[education_history][{{ $idx }}][degree]" value="{{ is_array($edu) ? ($edu['degree'] ?? '') : $edu }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Institution</label><input type="text" name="snapshot[education_history][{{ $idx }}][institution]" value="{{ is_array($edu) ? ($edu['institution'] ?? '') : '' }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-education" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded">+ Add Education</button>
        </section>

        {{-- Career --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Career</h2>
            <div id="career-container">
                @foreach(($sections['career']['data'] ?? []) as $idx => $career)
                    <div class="flex gap-4 mb-3 items-end career-row">
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Title / Role</label><input type="text" name="snapshot[career_history][{{ $idx }}][title]" value="{{ is_array($career) ? ($career['title'] ?? $career['role'] ?? '') : $career }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Company / Employer</label><input type="text" name="snapshot[career_history][{{ $idx }}][company]" value="{{ is_array($career) ? ($career['company'] ?? $career['employer'] ?? '') : '' }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-career" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded">+ Add Career</button>
        </section>

        {{-- Relatives — centralized relation-details engine (same as wizard Relatives section). --}}
        @php
            $intakeRelativesData = $sections['relatives']['data'] ?? [];
            if (!is_array($intakeRelativesData)) { $intakeRelativesData = []; }

            // Auto-populate relatives from contacts where relation looks like "चुलता / Uncle" etc.
            $contactsForRelatives = $sections['contacts']['data'] ?? [];
            if (is_array($contactsForRelatives)) {
                foreach ($contactsForRelatives as $c) {
                    $row = is_array($c) ? $c : [];
                    $relText = trim((string)($row['relation'] ?? $row['relation_type'] ?? ''));
                    $nameText = trim((string)($row['name'] ?? $row['contact_name'] ?? ''));
                    $phoneText = trim((string)($row['contact_number'] ?? $row['phone_number'] ?? $row['number'] ?? ''));

                    if ($relText === '' || $nameText === '') {
                        continue;
                    }

                    $isUncle = mb_stripos($relText, 'चुलता') !== false
                        || mb_stripos($relText, 'चुलते') !== false
                        || stripos($relText, 'uncle') !== false;

                    if (! $isUncle) {
                        continue;
                    }

                    $intakeRelativesData[] = [
                        'relation_type' => 'Uncle',
                        'name' => $nameText,
                        'contact_number' => $phoneText,
                        'occupation' => $row['occupation'] ?? ($row['note'] ?? ''),
                        'notes' => '',
                    ];
                }
            }

            $intakeRelationOptions = [['value'=>'Uncle','label'=>'Uncle'],['value'=>'Aunt','label'=>'Aunt'],['value'=>'Cousin','label'=>'Cousin'],['value'=>'Brother','label'=>'Brother'],['value'=>'Sister','label'=>'Sister'],['value'=>'Father','label'=>'Father'],['value'=>'Mother','label'=>'Mother'],['value'=>'Grandfather','label'=>'Grandfather'],['value'=>'Grandmother','label'=>'Grandmother'],['value'=>'Other','label'=>'Other']];
        @endphp
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">{{ $sections['relatives']['label'] ?? 'Relatives & Family Network' }}</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Add extended family members. All fields optional.</p>
            <x-repeaters.relation-details
                namePrefix="snapshot[relatives]"
                :relationOptions="$intakeRelationOptions"
                :showMarried="false"
                :items="collect($intakeRelativesData)"
                :showPrimaryContact="true"
                addButtonLabel="Add Relative"
                removeButtonLabel="Remove this relative"
            />
        </section>

        {{-- Siblings — same engine as wizard (snapshot[siblings]). --}}
        @php
            $intakeSiblingsData = $sections['siblings']['data'] ?? [];
            if (!is_array($intakeSiblingsData)) { $intakeSiblingsData = []; }
            $intakeSiblingsItems = collect($intakeSiblingsData);
            if ($intakeSiblingsItems->isEmpty() && !empty(($intake->approval_snapshot_json ?? [])['siblings'] ?? [])) {
                $intakeSiblingsItems = collect(($intake->approval_snapshot_json ?? [])['siblings']);
            }
            $siblingRelationOptions = [['value'=>'brother','label'=>'Brother'],['value'=>'sister','label'=>'Sister']];
        @endphp
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">{{ $sections['siblings']['label'] ?? 'Siblings' }}</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Add sibling details (brothers &amp; sisters). All fields optional.</p>
            <x-repeaters.relation-details
                namePrefix="snapshot[siblings]"
                :relationOptions="$siblingRelationOptions"
                :showMarried="true"
                :items="$intakeSiblingsItems"
                :showPrimaryContact="false"
                addButtonLabel="Add Sibling"
                removeButtonLabel="Remove this sibling"
            />
        </section>

        {{-- Addresses — centralized address-row component. --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Addresses</h2>
            <div id="addresses-container">
                @foreach(($sections['addresses']['data'] ?? []) as $idx => $addr)
                    @php
                        $addrRaw = is_array($addr) ? ($addr['raw'] ?? $addr['line1'] ?? '') : (string) $addr;
                        $addrType = is_array($addr) ? ($addr['type'] ?? 'current') : 'current';
                    @endphp
                    <x-profile.address-row
                        :namePrefix="'snapshot[addresses]['.$idx.']'"
                        :valueRaw="$addrRaw"
                        :valueType="$addrType"
                        rawPlaceholder="Full address"
                        typePlaceholder="current/permanent"
                    >
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded hover:bg-red-50 shrink-0">Remove</button>
                    </x-profile.address-row>
                @endforeach
            </div>
            <template id="address-row-template">
                <div class="flex gap-4 mb-3 items-end address-row">
                    <div class="flex-1 min-w-0">
                        <label class="block text-sm font-medium mb-1">Address</label>
                        <input type="text" name="snapshot[addresses][__INDEX__][raw]" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Full address">
                    </div>
                    <div class="w-32 shrink-0">
                        <label class="block text-sm font-medium mb-1">Type</label>
                        <input type="text" name="snapshot[addresses][__INDEX__][type]" value="current" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="current">
                    </div>
                    <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded shrink-0">Remove</button>
                </div>
            </template>
            <button type="button" id="add-address" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded">+ Add Address</button>
        </section>

        {{-- Property Summary --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Property Summary</h2>
            <textarea name="snapshot[property_summary]" rows="3" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="स्थायिक मालमत्ता / property details">{{ e(is_scalar($sections['property_summary']['data'] ?? null) ? $sections['property_summary']['data'] : '') }}</textarea>
        </section>

        {{-- Property Assets (list) --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Property Assets</h2>
            <div id="property-assets-container">
                @foreach(($sections['property_assets']['data'] ?? []) as $idx => $asset)
                    <div class="flex gap-4 mb-3 items-end property-asset-row">
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Description</label><input type="text" name="snapshot[property_assets][{{ $idx }}][description]" value="{{ is_array($asset) ? ($asset['description'] ?? json_encode($asset)) : $asset }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-property-asset" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded">+ Add Property Asset</button>
        </section>

        {{-- Horoscope --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Horoscope (राशी / नक्षत्र)</h2>
            <input type="text" name="snapshot[horoscope]" value="{{ e(is_scalar($sections['horoscope']['data'] ?? null) ? $sections['horoscope']['data'] : '') }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="e.g. पूर्वा फाल्गुनी">
        </section>

        {{-- Legal Cases --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Legal Cases</h2>
            <div id="legal-cases-container">
                @foreach(($sections['legal_cases']['data'] ?? []) as $idx => $legal)
                    <div class="flex gap-4 mb-3 items-end legal-case-row">
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Details</label><input type="text" name="snapshot[legal_cases][{{ $idx }}][details]" value="{{ is_array($legal) ? ($legal['details'] ?? json_encode($legal)) : $legal }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-legal-case" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded">+ Add Legal Case</button>
        </section>

        {{-- Partner preferences (structured, same as wizard About & preferences) — snapshot[preferences][0][...] --}}
        @php
            $prefsData = $sections['preferences']['data'] ?? [];
            $prefRow = is_array($prefsData) && isset($prefsData[0]) && is_array($prefsData[0]) ? $prefsData[0] : (is_array($prefsData) ? $prefsData : []);
            $approvalPrefs = ($intake->approval_snapshot_json ?? [])['preferences'] ?? null;
            if (is_array($approvalPrefs) && isset($approvalPrefs[0]) && is_array($approvalPrefs[0])) {
                $prefRow = array_merge($prefRow, $approvalPrefs[0]);
            } elseif (is_array($approvalPrefs) && !isset($approvalPrefs[0])) {
                $prefRow = array_merge($prefRow, $approvalPrefs);
            }
        @endphp
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Partner preferences (अपेक्षा)</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Preferred city</label><input type="text" name="snapshot[preferences][0][preferred_city]" value="{{ e($prefRow['preferred_city'] ?? '') }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Preferred city"></div>
                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Preferred caste</label><input type="text" name="snapshot[preferences][0][preferred_caste]" value="{{ e($prefRow['preferred_caste'] ?? '') }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Preferred caste"></div>
                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Age min</label><input type="number" name="snapshot[preferences][0][preferred_age_min]" value="{{ e($prefRow['preferred_age_min'] ?? '') }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Min age"></div>
                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Age max</label><input type="number" name="snapshot[preferences][0][preferred_age_max]" value="{{ e($prefRow['preferred_age_max'] ?? '') }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Max age"></div>
                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Income min</label><input type="number" name="snapshot[preferences][0][preferred_income_min]" value="{{ e($prefRow['preferred_income_min'] ?? '') }}" step="0.01" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Min income"></div>
                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Income max</label><input type="number" name="snapshot[preferences][0][preferred_income_max]" value="{{ e($prefRow['preferred_income_max'] ?? '') }}" step="0.01" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Max income"></div>
                <div class="md:col-span-2"><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Preferred education</label><input type="text" name="snapshot[preferences][0][preferred_education]" value="{{ e($prefRow['preferred_education'] ?? '') }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Preferred education"></div>
            </div>
        </section>

        {{-- Extended narrative (About me & expectations — same as wizard) — snapshot[extended_narrative][...] --}}
        @php
            $narrData = $sections['narrative']['data'] ?? null;
            $extRow = ($intake->approval_snapshot_json ?? [])['extended_narrative'] ?? null;
            if (is_array($extRow)) {
                $narrativeAboutMe = $extRow['narrative_about_me'] ?? '';
                $narrativeExpectations = $extRow['narrative_expectations'] ?? '';
                $additionalNotes = $extRow['additional_notes'] ?? '';
            } else {
                $narrativeAboutMe = is_scalar($narrData) ? (string)$narrData : '';
                $narrativeExpectations = '';
                $additionalNotes = '';
            }
        @endphp
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">About me &amp; expectations</h2>
            <div class="space-y-4">
                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">About me</label><textarea name="snapshot[extended_narrative][narrative_about_me]" rows="4" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="About me">{{ e($narrativeAboutMe) }}</textarea></div>
                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Expectations</label><textarea name="snapshot[extended_narrative][narrative_expectations]" rows="4" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Expectations">{{ e($narrativeExpectations) }}</textarea></div>
                <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Additional notes</label><textarea name="snapshot[extended_narrative][additional_notes]" rows="2" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Any extra text from biodata">{{ e($additionalNotes) }}</textarea></div>
            </div>
        </section>

        {{-- Scroll anchor at bottom --}}
        <div id="scroll-bottom-anchor" class="h-2"></div>

        {{-- Confirmation and Submit --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 sticky bottom-0 border-t">
            <label class="flex items-center gap-3 cursor-pointer mb-4">
                <input type="checkbox" id="confirm_verified" name="confirm_verified" value="1" class="rounded border-gray-300">
                <span class="font-medium text-gray-800 dark:text-gray-200">मी सर्व माहिती तपासली आहे</span>
            </label>
            <button type="submit" id="approve_btn" disabled class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-md font-medium">
                Approve & Apply to Profile
            </button>
            <p id="gating-message" class="mt-2 text-sm text-amber-600 dark:text-amber-400 hidden">खाली स्क्रोल करा, बॉक्स चेक करा आणि सर्व "सुधारणा आवश्यक" फील्ड भरा.</p>
        </section>
    </form>
</div>

<script>
(function() {
    const form = document.getElementById('intake-preview-form');
    const approveBtn = document.getElementById('approve_btn');
    const confirmCheck = document.getElementById('confirm_verified');
    const gatingMessage = document.getElementById('gating-message');
    const anchor = document.getElementById('scroll-bottom-anchor');

    const requiredCorrectionFields = @json($requiredCorrectionFields ?? []);
    var fieldToName = function(f) {
        if (f === 'religion') return 'religion_id';
        if (f === 'caste') return 'caste_id';
        if (f === 'sub_caste') return 'sub_caste_id';
        return f;
    };
    const requiredSelectors = requiredCorrectionFields.length ? requiredCorrectionFields.map(function(f) { return 'input[name="snapshot[core][' + fieldToName(f) + ']"]'; }).join(',') : '';
    const placeholderNotFound = @json($placeholderNotFound ?? '⟪NOT FOUND IN OCR⟫');
    const placeholderSelectRequired = @json($placeholderSelectRequired ?? '⟪SELECT REQUIRED⟫');

    function isScrolledToBottom() {
        if (!anchor) return false;
        var rect = anchor.getBoundingClientRect();
        return rect.top <= (window.innerHeight + 80);
    }

    function allRequiredCorrectionsFilled() {
        if (!requiredSelectors) return true;
        var inputs = form.querySelectorAll(requiredSelectors);
        for (var i = 0; i < inputs.length; i++) {
            var v = String(inputs[i].value || '').trim();
            if (v === '' || v === placeholderNotFound || v === placeholderSelectRequired) return false;
        }
        return true;
    }

    function updateButton() {
        var atBottom = isScrolledToBottom();
        var checked = confirmCheck && confirmCheck.checked;
        var filled = allRequiredCorrectionsFilled();
        var canSubmit = atBottom && checked && filled;
        approveBtn.disabled = !canSubmit;
        if (!canSubmit && gatingMessage) gatingMessage.classList.remove('hidden');
        else if (gatingMessage) gatingMessage.classList.add('hidden');
    }

    if (confirmCheck) confirmCheck.addEventListener('change', updateButton);
    window.addEventListener('scroll', function() { updateButton(); }, { passive: true });
    form.querySelectorAll('input').forEach(function(inp) {
        inp.addEventListener('input', updateButton);
        inp.addEventListener('change', updateButton);
    });
    updateButton();

    function nextIndex(containerId, rowClass) {
        var container = document.getElementById(containerId);
        return container ? container.querySelectorAll(rowClass).length : 0;
    }

    function addIntakeContactRow() {
        var template = document.getElementById('contact-row-template');
        var container = document.getElementById('contacts-container');
        if (!template || !container) return;
        var i = nextIndex('contacts-container', '.contact-row');
        var clone = template.content.cloneNode(true);
        var row = clone.querySelector('.contact-row');
        if (!row) return;
        row.querySelectorAll('[name]').forEach(function(el) {
            if (el.name && el.name.indexOf('__INDEX__') !== -1) el.name = el.name.replace(/__INDEX__/g, i);
        });
        row.querySelectorAll('input[type="text"]').forEach(function(inp) {
            if (inp.name && inp.name.indexOf('phone_number') !== -1) inp.value = '';
        });
        if (i === 0) {
            var hidPrimary = document.createElement('input');
            hidPrimary.type = 'hidden';
            hidPrimary.name = 'snapshot[contacts][0][is_primary]';
            hidPrimary.value = '1';
            row.insertBefore(hidPrimary, row.firstChild);
            var hidName = document.createElement('input');
            hidName.type = 'hidden';
            hidName.name = 'snapshot[contacts][0][contact_name]';
            hidName.value = 'Primary';
            row.insertBefore(hidName, row.firstChild);
        }
        row.querySelector('.remove-row')?.addEventListener('click', function() { row.remove(); });
        container.appendChild(row);
    }
    document.getElementById('add-contact')?.addEventListener('click', addIntakeContactRow);

    document.getElementById('add-education')?.addEventListener('click', function() {
        var i = nextIndex('education-container', '.education-row');
        var div = document.createElement('div');
        div.className = 'flex gap-4 mb-3 items-end education-row';
        div.innerHTML = '<div class="flex-1"><label class="block text-sm font-medium mb-1">Degree</label><input type="text" name="snapshot[education_history][' + i + '][degree]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><div class="flex-1"><label class="block text-sm font-medium mb-1">Institution</label><input type="text" name="snapshot[education_history][' + i + '][institution]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>';
        document.getElementById('education-container').appendChild(div);
        div.querySelector('.remove-row').addEventListener('click', function() { div.remove(); });
    });

    document.getElementById('add-career')?.addEventListener('click', function() {
        var i = nextIndex('career-container', '.career-row');
        var div = document.createElement('div');
        div.className = 'flex gap-4 mb-3 items-end career-row';
        div.innerHTML = '<div class="flex-1"><label class="block text-sm font-medium mb-1">Title / Role</label><input type="text" name="snapshot[career_history][' + i + '][title]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><div class="flex-1"><label class="block text-sm font-medium mb-1">Company / Employer</label><input type="text" name="snapshot[career_history][' + i + '][company]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>';
        document.getElementById('career-container').appendChild(div);
        div.querySelector('.remove-row').addEventListener('click', function() { div.remove(); });
    });

    document.getElementById('add-address')?.addEventListener('click', function() {
        var template = document.getElementById('address-row-template');
        var container = document.getElementById('addresses-container');
        if (!template || !container) return;
        var i = nextIndex('addresses-container', '.address-row');
        var clone = template.content.cloneNode(true);
        var row = clone.querySelector('.address-row');
        if (!row) return;
        row.querySelectorAll('[name]').forEach(function(el) {
            if (el.name && el.name.indexOf('__INDEX__') !== -1) el.name = el.name.replace(/__INDEX__/g, i);
        });
        row.querySelector('.remove-row')?.addEventListener('click', function() { row.remove(); });
        container.appendChild(row);
    });

    document.getElementById('add-property-asset')?.addEventListener('click', function() {
        var i = nextIndex('property-assets-container', '.property-asset-row');
        var div = document.createElement('div');
        div.className = 'flex gap-4 mb-3 items-end property-asset-row';
        div.innerHTML = '<div class="flex-1"><label class="block text-sm font-medium mb-1">Description</label><input type="text" name="snapshot[property_assets][' + i + '][description]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>';
        document.getElementById('property-assets-container').appendChild(div);
        div.querySelector('.remove-row').addEventListener('click', function() { div.remove(); });
    });

    document.getElementById('add-legal-case')?.addEventListener('click', function() {
        var i = nextIndex('legal-cases-container', '.legal-case-row');
        var div = document.createElement('div');
        div.className = 'flex gap-4 mb-3 items-end legal-case-row';
        div.innerHTML = '<div class="flex-1"><label class="block text-sm font-medium mb-1">Details</label><input type="text" name="snapshot[legal_cases][' + i + '][details]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>';
        document.getElementById('legal-cases-container').appendChild(div);
        div.querySelector('.remove-row').addEventListener('click', function() { div.remove(); });
    });

    document.getElementById('add-preference')?.addEventListener('click', function() {
        var i = nextIndex('preferences-container', '.preference-row');
        var div = document.createElement('div');
        div.className = 'flex gap-4 mb-3 items-end preference-row';
        div.innerHTML = '<div class="flex-1"><input type="text" name="snapshot[preferences][' + i + ']" class="w-full border rounded px-3 py-2 dark:bg-gray-700" placeholder="Preference"></div><button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>';
        document.getElementById('preferences-container').appendChild(div);
        div.querySelector('.remove-row').addEventListener('click', function() { div.remove(); });
    });

// === PHASE-5C DAY-28 Revert: restore original_value_snapshot; hide Revert again when it was "after-apply" ===
document.querySelectorAll('.revert-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var field = btn.getAttribute('data-field');
        var input = document.querySelector('input[name="snapshot[core][' + field + ']"]');
        if (input && input.dataset.original !== undefined) {
            input.value = input.dataset.original;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (btn.classList.contains('revert-btn-after-apply')) {
            btn.classList.add('hidden');
        }
    });
});
document.querySelectorAll('.use-candidate-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var field = btn.getAttribute('data-field');
        var val = btn.getAttribute('data-value');
        var input = document.querySelector('input[name="snapshot[core][' + field + ']"]');
        if (input && val !== null) {
            input.value = val;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
});
    form.querySelectorAll('.remove-row').forEach(function(btn) {
        btn.addEventListener('click', function() { btn.closest('.contact-row, .child-row, .education-row, .career-row, .address-row, .property-asset-row, .legal-case-row, .preference-row')?.remove(); });
    });
})();
</script>
@endsection
