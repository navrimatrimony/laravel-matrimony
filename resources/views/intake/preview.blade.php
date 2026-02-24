@extends('layouts.app')

@section('content')
<div class="container max-w-4xl mx-auto py-8 px-4">
    <h1 class="text-2xl font-bold mb-2">Intake Preview</h1>
    <p class="text-gray-600 dark:text-gray-400 text-sm mb-6">तुमची माहिती तपासा आणि आवश्यक ते सुधारा. खाली स्क्रोल करून सर्व तपासल्यानंतरच अप्रूव्ह करा.</p>

    <form id="intake-preview-form" method="POST" action="{{ route('intake.approve', $intake) }}" class="space-y-8">
        @csrf

        @php
            $sectionSourceKeys = $sectionSourceKeys ?? [];
            $coreData = $sections['core']['data'] ?? $data['core'] ?? [];
        @endphp

        {{-- Core Details (editable) --}}
        <section class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4 border-b pb-2">Core Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach(['full_name','date_of_birth','gender','religion','caste','sub_caste','marital_status','annual_income','family_income','primary_contact_number','serious_intent_id'] as $coreKey)
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
                        <input type="text" name="snapshot[core][{{ $coreKey }}]" value="{{ is_array($val) ? json_encode($val) : old('snapshot.core.'.$coreKey, $val) }}" class="{{ $cls }}" data-required-correction="{{ $isRequiredCorrection ? '1' : '0' }}" placeholder="—">
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
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Title</label><input type="text" name="snapshot[career_history][{{ $idx }}][title]" value="{{ is_array($career) ? ($career['title'] ?? '') : $career }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <div class="flex-1"><label class="block text-sm font-medium mb-1">Company</label><input type="text" name="snapshot[career_history][{{ $idx }}][company]" value="{{ is_array($career) ? ($career['company'] ?? '') : '' }}" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div>
                        <button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>
                    </div>
                @endforeach
            </div>
            <button type="button" id="add-career" class="mt-2 px-4 py-2 bg-gray-200 dark:bg-gray-600 rounded">+ Add Career</button>
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
    const requiredSelectors = requiredCorrectionFields.map(function(f) { return 'input[name="snapshot[core][' + f + ']"]'; }).join(',');

    function isScrolledToBottom() {
        if (!anchor) return false;
        var rect = anchor.getBoundingClientRect();
        return rect.top <= (window.innerHeight + 80);
    }

    function allRequiredCorrectionsFilled() {
        if (!requiredSelectors) return true;
        var inputs = form.querySelectorAll(requiredSelectors);
        for (var i = 0; i < inputs.length; i++) {
            if (!inputs[i].value || String(inputs[i].value).trim() === '') return false;
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
        div.innerHTML = '<div class="flex-1"><label class="block text-sm font-medium mb-1">Title</label><input type="text" name="snapshot[career_history][' + i + '][title]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><div class="flex-1"><label class="block text-sm font-medium mb-1">Company</label><input type="text" name="snapshot[career_history][' + i + '][company]" class="w-full border rounded px-3 py-2 dark:bg-gray-700"></div><button type="button" class="remove-row px-3 py-2 border border-red-400 text-red-600 rounded">Remove</button>';
        document.getElementById('career-container').appendChild(div);
        div.querySelector('.remove-row').addEventListener('click', function() { div.remove(); });
    });

    form.querySelectorAll('.remove-row').forEach(function(btn) {
        btn.addEventListener('click', function() { btn.closest('.contact-row, .child-row, .education-row, .career-row')?.remove(); });
    });
})();
</script>
@endsection
