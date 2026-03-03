{{-- Point 4.2: Canonical section for marriage details + children. On standalone step (section=marriages) show marital status here; on full it is in basic_info above. --}}
@php
    $profileMarriages = $profileMarriages ?? collect();
    $marriage = $profileMarriages->first();
    $statusesForJs = ($maritalStatuses ?? collect())->map(fn($s) => ['id' => $s->id, 'key' => $s->key])->values();
    $initialMaritalId = old('marital_status_id', $profile->marital_status_id ?? '');
    $isFull = ($currentSection ?? '') === 'full';
@endphp
<div class="space-y-4">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Marriages</h2>
    <div id="wizard-marital-wrapper" data-statuses="{{ e(json_encode($statusesForJs)) }}">
        @if(!$isFull)
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marital Status <span class="text-red-500">*</span></label>
                <select id="wizard_marital_status_id" name="marital_status_id"
                    class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" required>
                    <option value="">Select Marital Status</option>
                    @foreach($maritalStatuses ?? [] as $status)
                        <option value="{{ $status->id }}" {{ $initialMaritalId == $status->id ? 'selected' : '' }}>💍 {{ $status->label }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        {{-- Marriage details by status; show/hide via JS (select is #wizard_marital_status_id in basic_info when full, or above when standalone). --}}
        <div id="wizard-marriage-married" class="mt-6 wizard-marriage-block" style="display:none;">
            <fieldset class="wizard-marriage-fieldset">
                @include('matrimony.profile.wizard.sections.marriage_partials.marriages_married', ['marriage' => $marriage])
            </fieldset>
        </div>
        <div id="wizard-marriage-divorced" class="mt-6 wizard-marriage-block" style="display:none;">
            <fieldset class="wizard-marriage-fieldset">
                @include('matrimony.profile.wizard.sections.marriage_partials.marriages_divorced', ['marriage' => $marriage])
            </fieldset>
        </div>
        <div id="wizard-marriage-separated" class="mt-6 wizard-marriage-block" style="display:none;">
            <fieldset class="wizard-marriage-fieldset">
                @include('matrimony.profile.wizard.sections.marriage_partials.marriages_separated', ['marriage' => $marriage])
            </fieldset>
        </div>
        <div id="wizard-marriage-widowed" class="mt-6 wizard-marriage-block" style="display:none;">
            <fieldset class="wizard-marriage-fieldset">
                @include('matrimony.profile.wizard.sections.marriage_partials.marriages_widowed', ['marriage' => $marriage])
            </fieldset>
        </div>

        <div id="wizard-children-section" class="mt-6" style="display:none;">
            <fieldset id="wizard-children-fieldset" disabled>
                @include('matrimony.profile.wizard.sections.children')
            </fieldset>
        </div>
    </div>
    <script>
    (function() {
        var wrapper = document.getElementById('wizard-marital-wrapper');
        var select = document.getElementById('wizard_marital_status_id');
        if (!select) return;
        var statuses = [];
        if (wrapper) try {
            var raw = wrapper.getAttribute('data-statuses');
            statuses = raw ? JSON.parse(raw) : [];
        } catch (e) { statuses = []; }
        var marriageKeys = ['married', 'divorced', 'separated', 'widowed'];
        var childrenKeys = ['divorced', 'separated', 'widowed'];
        function getKey() {
            var id = select.value;
            if (!id) return null;
            var s = statuses.find(function(x) { return String(x.id) === String(id); });
            return s ? String(s.key || '').toLowerCase() : null;
        }
        function update() {
            var key = getKey();
            marriageKeys.forEach(function(k) {
                var el = document.getElementById('wizard-marriage-' + k);
                if (el) {
                    el.style.display = key === k ? 'block' : 'none';
                    var fs = el.querySelector('fieldset.wizard-marriage-fieldset');
                    if (fs) fs.disabled = (key !== k);
                }
            });
            var childrenEl = document.getElementById('wizard-children-section');
            var childrenFs = document.getElementById('wizard-children-fieldset');
            var showChildren = key && childrenKeys.indexOf(key) !== -1;
            if (childrenEl) childrenEl.style.display = showChildren ? 'block' : 'none';
            if (childrenFs) childrenFs.disabled = !showChildren;
        }
        select.addEventListener('change', update);
        update();
    })();
    </script>
</div>
