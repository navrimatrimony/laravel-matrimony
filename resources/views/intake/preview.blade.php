@extends('layouts.app')

@section('content')
<div class="container max-w-6xl mx-auto py-8 px-4">
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ route('intake.index') }}" class="hover:underline">← {{ __('intake.my_biodata_uploads') }}</a>
    </p>
    <h1 class="text-2xl font-bold mb-2">{{ __('intake.intake_preview') }}</h1>
    <p class="text-gray-600 dark:text-gray-400 text-sm mb-2">तुमची माहिती तपासा आणि आवश्यक ते सुधारा. खाली स्क्रोल करून सर्व तपासल्यानंतरच अप्रूव्ह करा.</p>
    <p class="text-gray-500 dark:text-gray-500 text-xs mb-4">डावीकडे रॉ बायोडाटा, उजवीकडे पार्स केलेला JSON. खाली फॉर्म भरून अप्रूव्ह करा.</p>

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('intake.reparse', $intake) }}" class="inline" onsubmit="return confirm('पार्स पुन्हा चालवायचा आहे? पृष्ठ रिफ्रेश केल्यावर अद्ययावत माहिती (उंची, धर्म/जात इ.) दिसेल.');">
            @csrf
            <button type="submit" class="px-3 py-1.5 text-sm border border-amber-500 text-amber-700 dark:text-amber-400 dark:border-amber-400 rounded hover:bg-amber-50 dark:hover:bg-amber-900/20">
                पार्स पुन्हा चालवा (नवीन नियम लागू)
            </button>
        </form>
        <span class="text-xs text-gray-500 dark:text-gray-400">— जर Religion/Caste/Height इ. चुकीचे दिसत असतील तर हे बटण दाबा, नंतर पृष्ठ रिफ्रेश करा.</span>
    </div>

    <form id="intake-preview-form" method="POST" action="{{ route('intake.approve', $intake) }}" class="space-y-8">
        @csrf

        @php
            $sectionSourceKeys = $sectionSourceKeys ?? [];
            $coreData = $sections['core']['data'] ?? $data['core'] ?? [];
            $parsedJsonForDisplay = isset($data) && is_array($data) ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        @endphp

        {{-- One row: Left = Raw biodata text, Right = Parsed JSON — both with scroll (slider) for small windows --}}
        <style>
            .intake-preview-scroll-panel {
                max-height: min(50vh, 20rem);
                min-height: 10rem;
                overflow-y: auto;
                overflow-x: auto;
                scrollbar-gutter: stable;
                -webkit-overflow-scrolling: touch;
            }
            .intake-preview-scroll-panel::-webkit-scrollbar { width: 10px; height: 10px; }
            .intake-preview-scroll-panel::-webkit-scrollbar-track { background: rgb(243 244 246); border-radius: 4px; }
            .dark .intake-preview-scroll-panel::-webkit-scrollbar-track { background: rgb(31 41 55); }
            .intake-preview-scroll-panel::-webkit-scrollbar-thumb { background: rgb(156 163 175); border-radius: 4px; }
            .intake-preview-scroll-panel::-webkit-scrollbar-thumb:hover { background: rgb(107 114 128); }
            .dark .intake-preview-scroll-panel::-webkit-scrollbar-thumb { background: rgb(75 85 99); }
            .dark .intake-preview-scroll-panel::-webkit-scrollbar-thumb:hover { background: rgb(107 114 128); }
        </style>
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Left: Raw biodata text --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 lg:p-5 flex flex-col min-w-0">
                <h2 class="text-base font-semibold mb-2 border-b border-gray-200 dark:border-gray-600 pb-2 shrink-0">{{ __('intake.raw_text_heading') }}</h2>
                @if(!empty($missingCriticalFields))
                    <div class="mb-2 text-xs text-red-700 dark:text-red-400 shrink-0">
                        <p class="font-semibold mb-1">⚠️ खालील महत्वाच्या फील्डमध्ये मूल्य भरलेले नाही:</p>
                        <ul class="list-disc list-inside space-y-0.5">
                            @foreach($missingCriticalFields as $fieldKey)
                                @php
                                    $normalizedKey = \Illuminate\Support\Str::startsWith($fieldKey, 'profile.') ? \Illuminate\Support\Str::after($fieldKey, 'profile.') : $fieldKey;
                                    $label = __('profile.' . $normalizedKey);
                                    if ($label === 'profile.' . $normalizedKey) { $label = $fieldKey; }
                                @endphp
                                <li>{{ $label }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <p class="text-xs text-gray-600 dark:text-gray-400 mb-2 shrink-0">{{ __('intake.raw_text_help') }}</p>
                <div class="intake-preview-scroll-panel rounded border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 p-3 text-xs text-gray-800 dark:text-gray-100 whitespace-pre-wrap leading-relaxed font-mono">
                    {{ $intake->raw_ocr_text ?? '' }}
                </div>
            </div>
            {{-- Right: Parsed JSON --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 lg:p-5 flex flex-col min-w-0">
                <h2 class="text-base font-semibold mb-2 border-b border-gray-200 dark:border-gray-600 pb-2 shrink-0">Parsed JSON</h2>
                <p class="text-xs text-gray-600 dark:text-gray-400 mb-2 shrink-0">बायोडाटा मधून काढलेला स्ट्रक्चर्ड डेटा. खालील फॉर्म याच्या आधारे भरलेला आहे.</p>
                <div class="intake-preview-scroll-panel rounded border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 p-3 text-xs text-gray-800 dark:text-gray-100 leading-relaxed font-mono">
                    @if($parsedJsonForDisplay !== '')
                        <pre class="m-0 whitespace-pre-wrap break-words">{!! $parsedJsonForDisplay !!}</pre>
                    @else
                        <span class="text-amber-600 dark:text-amber-400">Parsed JSON उपलब्ध नाही.</span>
                    @endif
                </div>
            </div>
        </section>

        {{-- Fields with OCR "not found" show empty and get .ocr-field-missing (no placeholder text); server still expects placeholder value on submit when empty. --}}
        <style>
            .ocr-field-missing {
                border-color: rgb(245 158 11) !important;
                background-color: rgb(254 243 199) !important;
            }
            .dark .ocr-field-missing {
                border-color: rgb(217 119 6) !important;
                background-color: rgb(69 26 3) !important;
            }
            .ocr-field-missing-wrap .religion-input.ocr-field-missing,
            .ocr-field-missing-wrap .caste-input.ocr-field-missing,
            .ocr-field-missing-wrap .subcaste-input.ocr-field-missing {
                border-color: rgb(245 158 11) !important;
                background-color: rgb(254 243 199) !important;
            }
            .dark .ocr-field-missing-wrap .religion-input.ocr-field-missing,
            .dark .ocr-field-missing-wrap .caste-input.ocr-field-missing,
            .dark .ocr-field-missing-wrap .subcaste-input.ocr-field-missing {
                border-color: rgb(217 119 6) !important;
                background-color: rgb(69 26 3) !important;
            }
        </style>

        {{-- Form: edit parsed data and submit --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 space-y-8">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-600 pb-2">तपासा आणि सुधारा — फॉर्म</h2>
            @include('matrimony.profile.wizard.sections.full_form')
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
                {{ __('intake.approve_apply_button') }}
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
        if (inp.hasAttribute('data-ocr-missing')) {
            inp.addEventListener('input', function() {
                if (String(inp.value || '').trim() !== '') {
                    inp.classList.remove('ocr-field-missing');
                    inp.removeAttribute('data-ocr-missing');
                    inp.removeAttribute('data-placeholder-value');
                    inp.closest('.ocr-field-missing-wrap')?.classList.remove('ocr-field-missing-wrap');
                }
            });
            inp.addEventListener('change', function() {
                if (String(inp.value || '').trim() !== '') {
                    inp.classList.remove('ocr-field-missing');
                    inp.removeAttribute('data-ocr-missing');
                    inp.removeAttribute('data-placeholder-value');
                    inp.closest('.ocr-field-missing-wrap')?.classList.remove('ocr-field-missing-wrap');
                }
            });
        }
    });
    updateButton();

    form.addEventListener('submit', function(e) {
        form.querySelectorAll('input[data-ocr-missing="1"]').forEach(function(el) {
            if (!el.name || el.name.indexOf('snapshot[core]') !== 0) return;
            var v = String(el.value || '').trim();
            if (v === '') {
                var ph = el.getAttribute('data-placeholder-value');
                if (ph) el.value = ph;
            }
        });
        // When birth place is text-only (no city selected), submit it so approve can set birth_place_text.
        var birthWrap = form.querySelector('[data-location-context="birth"]');
        var birthCityHidden = form.querySelector('input[name="snapshot[core][birth_city_id]"]');
        var birthPlaceHidden = document.getElementById('intake_birth_place_text');
        if (birthWrap && birthCityHidden && birthPlaceHidden) {
            var displayInput = birthWrap.querySelector('.location-typeahead-input');
            if ((birthCityHidden.value === '' || birthCityHidden.value === null) && displayInput && String(displayInput.value || '').trim() !== '') {
                birthPlaceHidden.value = String(displayInput.value).trim();
            } else if (birthCityHidden.value !== '' && birthCityHidden.value != null) {
                birthPlaceHidden.value = '';
            }
        }
    });

    // Revert / use-candidate (full_form basic_info may render these)
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
        btn.addEventListener('click', function() { btn.closest('.contact-row, .child-row, .education-row, .career-row, .address-row, .preference-row')?.remove(); });
    });
})();
</script>
@endsection
