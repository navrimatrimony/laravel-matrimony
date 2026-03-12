@extends('layouts.app')

@section('content')
<div class="container max-w-4xl mx-auto py-8 px-4">
    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ route('intake.index') }}" class="hover:underline">← {{ __('intake.my_biodata_uploads') }}</a>
    </p>
    <h1 class="text-2xl font-bold mb-2">{{ __('intake.intake_preview') }}</h1>
    <p class="text-gray-600 dark:text-gray-400 text-sm mb-6">तुमची माहिती तपासा आणि आवश्यक ते सुधारा. खाली स्क्रोल करून सर्व तपासल्यानंतरच अप्रूव्ह करा.</p>
    <p class="text-gray-500 dark:text-gray-500 text-xs mb-4">इथे बायोडाटा मधून काढलेली सर्व फील्ड्स दिसतात. अप्रूव्ह नंतर प्रोफाइल विझार्डमध्ये आणखी विभाग (जसे की स्थान, फोटो, विवाह इतिहास) भरता येतील.</p>

    <form id="intake-preview-form" method="POST" action="{{ route('intake.approve', $intake) }}" class="space-y-8">
        @csrf

        @php
            $sectionSourceKeys = $sectionSourceKeys ?? [];
            $coreData = $sections['core']['data'] ?? $data['core'] ?? [];
        @endphp

        {{-- Raw biodata text — safety net to ensure 100% preview coverage --}}
        @if(!empty($intake->raw_ocr_text))
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-2 border-b pb-2">{{ __('intake.raw_text_heading') }}</h2>
            @if(!empty($missingCriticalFields))
                <div class="mb-3 text-xs text-red-700 dark:text-red-400">
                    <p class="font-semibold mb-1">⚠️ खालील महत्वाच्या फील्डमध्ये मूल्य भरलेले नाही:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($missingCriticalFields as $fieldKey)
                            @php
                                // Normalize keys like "profile.gender" → "gender"
                                $normalizedKey = \Illuminate\Support\Str::startsWith($fieldKey, 'profile.')
                                    ? \Illuminate\Support\Str::after($fieldKey, 'profile.')
                                    : $fieldKey;
                                $label = __('profile.' . $normalizedKey);
                                if ($label === 'profile.' . $normalizedKey) {
                                    // Fallback: show raw key if translation missing
                                    $label = $fieldKey;
                                }
                            @endphp
                            <li>{{ $label }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <p class="text-xs text-gray-600 dark:text-gray-400 mb-3">{{ __('intake.raw_text_help') }}</p>
            <div class="max-h-64 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 p-3 text-xs text-gray-800 dark:text-gray-100 whitespace-pre-wrap leading-relaxed">
                {{ $intake->raw_ocr_text }}
            </div>
        </section>
        @endif

        {{-- Centralized full form: same sections and order as wizard full (single source full_form.blade.php). --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 space-y-8">
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
    });
    updateButton();

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
