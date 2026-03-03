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
                @foreach(['full_name','date_of_birth','gender','marital_status','annual_income','family_income','primary_contact_number','serious_intent_id','height_cm','highest_education','father_name','mother_name','brother_count','sister_count','birth_time','birth_place','gotra','kuldaivat','rashi','nadi','gan','mangalik','varna','mother_occupation','father_occupation','mama','relatives'] as $coreKey)
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

        {{-- Contacts --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Contacts</h2>
            <div id="contacts-container">
                @foreach(($sections['contacts']['data'] ?? []) as $idx => $contact)
                    <div class="flex gap-4 mb-3 items-end contact-row">
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Number</label><input type="text" name="snapshot[contacts][{{ $idx }}][number]" value="{{ is_array($contact) ? ($contact['number'] ?? $contact['phone_number'] ?? '') : $contact }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Type</label><input type="text" name="snapshot[contacts][{{ $idx }}][type]" value="{{ is_array($contact) ? ($contact['type'] ?? '') : '' }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded hover:bg-red-50">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-contact" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded hover:bg-gray-300">+ Add Contact</button>
        </section>

        {{-- Children --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Children</h2>
            <div id="children-container">
                @foreach(($sections['children']['data'] ?? []) as $idx => $child)
                    <div class="flex gap-4 mb-3 items-end child-row">
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Name</label><input type="text" name="snapshot[children][{{ $idx }}][name]" value="{{ is_array($child) ? ($child['name'] ?? '') : $child }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">DOB</label><input type="text" name="snapshot[children][{{ $idx }}][dob]" value="{{ is_array($child) ? ($child['dob'] ?? '') : '' }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-child" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded">+ Add Child</button>
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

        {{-- Addresses --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Addresses</h2>
            <div id="addresses-container">
                @foreach(($sections['addresses']['data'] ?? []) as $idx => $addr)
                    <div class="flex gap-4 mb-3 items-end address-row">
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Address</label><input type="text" name="snapshot[addresses][{{ $idx }}][raw]" value="{{ is_array($addr) ? ($addr['raw'] ?? $addr['line1'] ?? '') : $addr }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700" placeholder="Full address"></div>
                        <div class="w-32"><label class="block text-sm font-medium mb-1">Type</label><input type="text" name="snapshot[addresses][{{ $idx }}][type]" value="{{ is_array($addr) ? ($addr['type'] ?? '') : 'current' }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700" placeholder="current/permanent"></div>
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>
                    </div>
                @endforeach
            </div>
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

        {{-- Preferences --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Preferences (अपेक्षा)</h2>
            <div id="preferences-container">
                @php $prefsData = $sections['preferences']['data'] ?? []; $prefsData = is_array($prefsData) ? $prefsData : []; @endphp
                @foreach($prefsData as $idx => $pref)
                    <div class="flex gap-4 mb-3 items-end preference-row">
                        <div class="flex-1"><input type="text" name="snapshot[preferences][{{ $idx }}]" value="{{ is_array($pref) ? ($pref['text'] ?? json_encode($pref)) : $pref }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700" placeholder="Preference"></div>
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-preference" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded">+ Add Preference</button>
        </section>

        {{-- Extended Narrative --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Additional Notes / Narrative</h2>
            <textarea name="snapshot[extended_narrative]" rows="3" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600" placeholder="Any extra text from biodata">{{ e(is_scalar($sections['narrative']['data'] ?? null) ? $sections['narrative']['data'] : '') }}</textarea>
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

    document.getElementById('add-contact')?.addEventListener('click', function() {
        var i = nextIndex('contacts-container', '.contact-row');
        var div = document.createElement('div');
        div.className = 'flex gap-4 mb-3 items-end contact-row';
        div.innerHTML = '<div class="flex-1"><label class="block text-sm font-medium mb-1">Number</label><input type="text" name="snapshot[contacts][' + i + '][number]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><div class="flex-1"><label class="block text-sm font-medium mb-1">Type</label><input type="text" name="snapshot[contacts][' + i + '][type]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>';
        document.getElementById('contacts-container').appendChild(div);
        div.querySelector('.remove-row').addEventListener('click', function() { div.remove(); });
    });

    document.getElementById('add-child')?.addEventListener('click', function() {
        var i = nextIndex('children-container', '.child-row');
        var div = document.createElement('div');
        div.className = 'flex gap-4 mb-3 items-end child-row';
        div.innerHTML = '<div class="flex-1"><label class="block text-sm font-medium mb-1">Name</label><input type="text" name="snapshot[children][' + i + '][name]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><div class="flex-1"><label class="block text-sm font-medium mb-1">DOB</label><input type="text" name="snapshot[children][' + i + '][dob]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>';
        document.getElementById('children-container').appendChild(div);
        div.querySelector('.remove-row').addEventListener('click', function() { div.remove(); });
    });

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
        var i = nextIndex('addresses-container', '.address-row');
        var div = document.createElement('div');
        div.className = 'flex gap-4 mb-3 items-end address-row';
        div.innerHTML = '<div class="flex-1"><label class="block text-sm font-medium mb-1">Address</label><input type="text" name="snapshot[addresses][' + i + '][raw]" class="w-full border rounded px-3 py-2 dark:bg-gray-700" placeholder="Full address"></div><div class="w-32"><label class="block text-sm font-medium mb-1">Type</label><input type="text" name="snapshot[addresses][' + i + '][type]" class="w-full border rounded px-3 py-2 dark:bg-gray-700" placeholder="current"></div><button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>';
        document.getElementById('addresses-container').appendChild(div);
        div.querySelector('.remove-row').addEventListener('click', function() { div.remove(); });
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
