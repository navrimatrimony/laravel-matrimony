@php
    use App\Support\HeightDisplay;
    $criteria = $preferenceCriteria ?? null;
    $oldAgeMin = old('preferred_age_min', $criteria?->preferred_age_min ?? 24);
    $oldAgeMax = old('preferred_age_max', $criteria?->preferred_age_max ?? 32);
    $oldHeightMin = old('preferred_height_min_cm', $criteria?->preferred_height_min_cm ?? 155);
    $oldHeightMax = old('preferred_height_max_cm', $criteria?->preferred_height_max_cm ?? 175);
    $selectedMaritalStatus = old('preferred_marital_status_id', $preferredMaritalStatusId ?? null);
    $selectedReligionIds = collect(old('preferred_religion_ids', $preferredReligionIds ?? []))->map(fn ($id) => (int) $id)->all();
    $selectedCasteIds = collect(old('preferred_caste_ids', $preferredCasteIds ?? []))->map(fn ($id) => (int) $id)->all();
    $interestedInIntercaste = old('preferred_intercaste', $interestedInIntercaste ?? false);
    $selectedDistrictIds = collect(old('preferred_district_ids', $preferredDistrictIds ?? []))->map(fn ($id) => (int) $id)->all();
    if (empty($selectedDistrictIds) && ! empty($profile?->district_id)) {
        $selectedDistrictIds = [(int) $profile->district_id];
    }
    $selectedMasterEducationIds = collect(old('preferred_master_education_ids', $preferredMasterEducationIds ?? []))->map(fn ($id) => (int) $id)->all();
    $selectedDietIds = collect(old('preferred_diet_ids', $preferredDietIds ?? []))->map(fn ($id) => (int) $id)->all();
    $educationOpenAll = old('education_open_all', empty($selectedMasterEducationIds) ? '1' : '0') === '1';
    $dietOpenAll = old('diet_open_all', empty($selectedDietIds) ? '1' : '0') === '1';
    $expectationTpl = trans('profile.expectations_quick_templates');
    $expectationTpl = is_array($expectationTpl) ? $expectationTpl : [];
    $narrativeExpectations = old('extended_narrative.narrative_expectations', $extendedAttrs->narrative_expectations ?? '');

    $religionById = collect($allReligions ?? [])->keyBy('id');
    $casteById = collect($allCastes ?? [])->keyBy('id');
    $districtById = collect($allDistricts ?? [])->keyBy('id');
    $maritalById = collect($allMaritalStatuses ?? [])->keyBy('id');

    $religionText = collect($selectedReligionIds)->map(fn ($id) => $religionById->get($id)?->label)->filter()->implode(', ');
    $casteText = collect($selectedCasteIds)->map(fn ($id) => $casteById->get($id)?->display_label)->filter()->implode(', ');
    $districtText = collect($selectedDistrictIds)->map(fn ($id) => $districtById->get($id)?->name)->filter()->implode(', ');
    $openToAllText = __('Open to all');
    $maritalText = $maritalById->get((int) $selectedMaritalStatus)?->label ?? $openToAllText;
    $communitySummary = trim(($religionText ?: $openToAllText).($casteText ? ' · '.$casteText : ''));
    $profileResidenceText = method_exists($profile, 'residenceLocationDisplayLine')
        ? trim((string) $profile->residenceLocationDisplayLine())
        : '';
    $locationSummary = $districtText ?: ($profileResidenceText !== '' ? $profileResidenceText : $openToAllText);
    $expectChipCls = 'inline-flex items-center rounded-full border border-indigo-200 dark:border-indigo-700 bg-indigo-50 dark:bg-indigo-950/40 px-3 py-1 text-xs font-medium text-indigo-800 dark:text-indigo-200 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition-colors cursor-pointer';
@endphp

<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 7]) }}" class="space-y-6">
    @csrf

    <div class="rounded-2xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900/20 p-4 sm:p-5 space-y-3">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('onboarding.step7_hint') }}</p>

        <div class="space-y-2">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-3 py-2.5">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Basics</p>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">Age {{ $oldAgeMin }}-{{ $oldAgeMax }}, Height {{ HeightDisplay::formatFeetInchesRange($oldHeightMin, $oldHeightMax) }}</p>
                    </div>
                    <button type="button" data-edit-toggle="edit-basics" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Edit</button>
                </div>
                <div id="edit-basics" class="hidden mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                    <style>
                        .onb-dual-range { pointer-events: none; }
                        .onb-dual-range::-webkit-slider-thumb { pointer-events: auto; -webkit-appearance: none; appearance: none; height: 1rem; width: 1rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.16); cursor: grab; margin-top: -4px; }
                        .onb-dual-range::-moz-range-thumb { pointer-events: auto; height: 1rem; width: 1rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.16); cursor: grab; }
                        .onb-dual-range::-webkit-slider-runnable-track { -webkit-appearance: none; appearance: none; height: 8px; background: transparent; }
                        .onb-dual-range::-moz-range-track { height: 8px; background: transparent; }
                    </style>
                    <div class="space-y-3">
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Age</p>
                            <p id="onb-age-range-label" class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 tabular-nums">{{ $oldAgeMin }} - {{ $oldAgeMax }}</p>
                            <div class="relative h-8 px-0.5" data-age-absolute-min="18" data-age-absolute-max="80">
                                <div class="absolute left-0 right-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-gray-200 dark:bg-gray-600 pointer-events-none"></div>
                                <div id="onb-age-range-fill" class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-indigo-500 pointer-events-none" style="left: 0%; width: 0%"></div>
                                <input type="range" id="onb-age-range-min" class="onb-dual-range absolute inset-x-0 top-0 z-[2] h-8 w-full cursor-pointer appearance-none bg-transparent" min="18" max="80" step="1" value="{{ $oldAgeMin }}">
                                <input type="range" id="onb-age-range-max" class="onb-dual-range absolute inset-x-0 top-0 z-[3] h-8 w-full cursor-pointer appearance-none bg-transparent" min="18" max="80" step="1" value="{{ $oldAgeMax }}">
                            </div>
                            <input type="hidden" id="onb-age-min-hidden" name="preferred_age_min" value="{{ $oldAgeMin }}">
                            <input type="hidden" id="onb-age-max-hidden" name="preferred_age_max" value="{{ $oldAgeMax }}">
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Height</p>
                            <p id="onb-height-range-label" class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 tabular-nums">{{ HeightDisplay::formatFeetInchesRange($oldHeightMin, $oldHeightMax) }}</p>
                            <div class="relative h-8 px-0.5">
                                <div class="absolute left-0 right-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-gray-200 dark:bg-gray-600 pointer-events-none"></div>
                                <div id="onb-height-range-fill" class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-indigo-500 pointer-events-none" style="left: 0%; width: 0%"></div>
                                <input type="range" id="onb-height-range-min" class="onb-dual-range absolute inset-x-0 top-0 z-[2] h-8 w-full cursor-pointer appearance-none bg-transparent" min="120" max="220" step="1" value="{{ $oldHeightMin }}">
                                <input type="range" id="onb-height-range-max" class="onb-dual-range absolute inset-x-0 top-0 z-[3] h-8 w-full cursor-pointer appearance-none bg-transparent" min="120" max="220" step="1" value="{{ $oldHeightMax }}">
                            </div>
                            <input type="hidden" id="onb-height-min-hidden" name="preferred_height_min_cm" value="{{ $oldHeightMin }}">
                            <input type="hidden" id="onb-height-max-hidden" name="preferred_height_max_cm" value="{{ $oldHeightMax }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-3 py-2.5">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Marital</p>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">{{ $maritalText }}</p>
                    </div>
                    <button type="button" data-edit-toggle="edit-marital" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Edit</button>
                </div>
                <div id="edit-marital" class="hidden mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                    <select name="preferred_marital_status_id" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 px-3 py-2 text-sm">
                        <option value="">{{ __('Open to all') }}</option>
                        @foreach(($allMaritalStatuses ?? collect()) as $ms)
                            <option value="{{ $ms->id }}" {{ (string) $selectedMaritalStatus === (string) $ms->id ? 'selected' : '' }}>{{ $ms->label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-3 py-2.5">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Community</p>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">{{ $communitySummary }}</p>
                    </div>
                    <button type="button" data-edit-toggle="edit-community" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Edit</button>
                </div>
                <div id="edit-community" class="hidden mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 space-y-3">
                    <div>
                        <label class="inline-flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200">
                            <input type="checkbox" name="preferred_intercaste" value="1" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600"
                                {{ $interestedInIntercaste ? 'checked' : '' }}>
                            <span>{{ __('wizard.open_to_intercaste') }}</span>
                        </label>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Religion</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach(($allReligions ?? collect()) as $religion)
                                <label class="inline-flex items-center gap-1 rounded-full border border-gray-300 dark:border-gray-600 px-2 py-0.5 text-xs">
                                    <input type="checkbox" class="community-religion" name="preferred_religion_ids[]" value="{{ $religion->id }}" {{ in_array($religion->id, $selectedReligionIds, true) ? 'checked' : '' }}>
                                    <span>{{ $religion->label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Caste</label>
                        <div class="flex flex-wrap gap-2 max-h-28 overflow-y-auto">
                            @foreach(($allCastes ?? collect()) as $caste)
                                <label class="inline-flex items-center gap-1 rounded-full border border-gray-300 dark:border-gray-600 px-2 py-0.5 text-xs caste-chip" data-religion-id="{{ $caste->religion_id }}">
                                    <input type="checkbox" class="community-caste" name="preferred_caste_ids[]" value="{{ $caste->id }}" {{ in_array($caste->id, $selectedCasteIds, true) ? 'checked' : '' }}>
                                    <span>{{ $caste->display_label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-3 py-2.5">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Location</p>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">{{ $locationSummary }}</p>
                    </div>
                    <button type="button" data-edit-toggle="edit-location" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Edit</button>
                </div>
                <div id="edit-location" class="hidden mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex flex-wrap gap-2 max-h-28 overflow-y-auto">
                        @foreach(($allDistricts ?? collect()) as $district)
                            <label class="inline-flex items-center gap-1 rounded-full border border-gray-300 dark:border-gray-600 px-2 py-0.5 text-xs">
                                <input type="checkbox" name="preferred_district_ids[]" value="{{ $district->id }}" {{ in_array($district->id, $selectedDistrictIds, true) ? 'checked' : '' }}>
                                <span>{{ $district->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-3 py-2.5">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Education</p>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">{{ $educationOpenAll ? 'Open to all' : 'Custom selection' }}</p>
                    </div>
                    <button type="button" data-edit-toggle="edit-education" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Edit</button>
                </div>
                <div id="edit-education" class="hidden mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 space-y-2">
                    <label class="inline-flex items-center gap-2 text-xs">
                        <input type="checkbox" id="education_open_all" name="education_open_all" value="1" {{ $educationOpenAll ? 'checked' : '' }}>
                        <span>Open to all</span>
                    </label>
                    <div id="education_choices" class="{{ $educationOpenAll ? 'hidden' : '' }} flex flex-wrap gap-2 max-h-24 overflow-y-auto">
                        @foreach(($masterEducationOptions ?? collect()) as $ed)
                            <label class="inline-flex items-center gap-1 rounded-full border border-gray-300 dark:border-gray-600 px-2 py-0.5 text-xs">
                                <input type="checkbox" name="preferred_master_education_ids[]" value="{{ $ed->id }}" {{ in_array($ed->id, $selectedMasterEducationIds, true) ? 'checked' : '' }}>
                                <span>{{ $ed->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 px-3 py-2.5">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Diet</p>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">{{ $dietOpenAll ? 'Open to all' : 'Custom selection' }}</p>
                    </div>
                    <button type="button" data-edit-toggle="edit-diet" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Edit</button>
                </div>
                <div id="edit-diet" class="hidden mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 space-y-2">
                    <label class="inline-flex items-center gap-2 text-xs">
                        <input type="checkbox" id="diet_open_all" name="diet_open_all" value="1" {{ $dietOpenAll ? 'checked' : '' }}>
                        <span>Open to all</span>
                    </label>
                    <div id="diet_choices" class="{{ $dietOpenAll ? 'hidden' : '' }} flex flex-wrap gap-2 max-h-24 overflow-y-auto">
                        @foreach(($partnerDietOptions ?? collect()) as $diet)
                            <label class="inline-flex items-center gap-1 rounded-full border border-gray-300 dark:border-gray-600 px-2 py-0.5 text-xs">
                                <input type="checkbox" name="preferred_diet_ids[]" value="{{ $diet->id }}" {{ in_array($diet->id, $selectedDietIds, true) ? 'checked' : '' }}>
                                <span>{{ $diet->label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="pt-2 border-t border-gray-200 dark:border-gray-700 space-y-2">
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200" for="onboarding-expectations">{{ __('profile.expectations') }}</label>
            @if (count($expectationTpl) > 0)
                <div class="flex flex-wrap gap-2">
                    @foreach ($expectationTpl as $idx => $tpl)
                        @php $label = is_array($tpl) ? ($tpl['label'] ?? '#'.($idx + 1)) : (string) $tpl; @endphp
                        <button type="button" class="{{ $expectChipCls }}" data-exp-template data-exp-index="{{ $idx }}" data-exp-target="onboarding-expectations">{{ $label }}</button>
                    @endforeach
                </div>
                <script type="application/json" data-exp-json>@json($expectationTpl)</script>
            @endif
            <textarea id="onboarding-expectations" name="extended_narrative[narrative_expectations]" rows="3" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" placeholder="{{ __('profile.expectations_placeholder') }}">{{ $narrativeExpectations }}</textarea>
        </div>
    </div>

    <x-onboarding.form-footer :back-url="route('matrimony.onboarding.show', ['step' => 6])" :submit-label="__('onboarding.step7_continue')" />
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-edit-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-edit-toggle');
            if (!id) return;
            var panel = document.getElementById(id);
            if (!panel) return;
            panel.classList.toggle('hidden');
        });
    });

    var educationOpenAll = document.getElementById('education_open_all');
    var educationChoices = document.getElementById('education_choices');
    if (educationOpenAll && educationChoices) {
        educationOpenAll.addEventListener('change', function () {
            educationChoices.classList.toggle('hidden', educationOpenAll.checked);
        });
    }
    var dietOpenAll = document.getElementById('diet_open_all');
    var dietChoices = document.getElementById('diet_choices');
    if (dietOpenAll && dietChoices) {
        dietOpenAll.addEventListener('change', function () {
            dietChoices.classList.toggle('hidden', dietOpenAll.checked);
        });
    }

    function bindDualRange(cfg) {
        var minR = document.getElementById(cfg.minRangeId);
        var maxR = document.getElementById(cfg.maxRangeId);
        var minH = document.getElementById(cfg.minHiddenId);
        var maxH = document.getElementById(cfg.maxHiddenId);
        var fill = document.getElementById(cfg.fillId);
        var label = document.getElementById(cfg.labelId);
        if (!minR || !maxR || !minH || !maxH || !fill || !label) return;

        function clamp(n) {
            n = parseInt(n, 10);
            if (isNaN(n)) return cfg.absMin;
            return Math.max(cfg.absMin, Math.min(cfg.absMax, n));
        }
        function cmToFtIn(cm) {
            cm = parseInt(cm, 10);
            if (isNaN(cm)) return [0, 0];
            var totalIn = cm / 2.54;
            var ft = Math.floor(totalIn / 12);
            var inch = Math.round(totalIn - ft * 12);
            if (inch === 12) { ft++; inch = 0; }
            return [ft, inch];
        }
        function formatHeightRangeFeet(minCm, maxCm) {
            var p1 = cmToFtIn(minCm);
            var p2 = cmToFtIn(maxCm);
            return p1[0] + "'" + p1[1] + '" - ' + p2[0] + "'" + p2[1] + '"';
        }
        function paint() {
            var mn = clamp(minR.value);
            var mx = clamp(maxR.value);
            if (mn > mx) {
                var t = mn; mn = mx; mx = t;
                minR.value = String(mn);
                maxR.value = String(mx);
            }
            minH.value = String(mn);
            maxH.value = String(mx);
            var span = cfg.absMax - cfg.absMin;
            var p1 = span > 0 ? ((mn - cfg.absMin) / span) * 100 : 0;
            var p2 = span > 0 ? ((mx - cfg.absMin) / span) * 100 : 0;
            fill.style.left = p1 + '%';
            fill.style.width = Math.max(0, p2 - p1) + '%';
            label.textContent = cfg.unit === 'cm' ? formatHeightRangeFeet(mn, mx) : (mn + ' - ' + mx);
        }
        minR.addEventListener('input', function () {
            var mn = clamp(minR.value);
            var mx = clamp(maxR.value);
            if (mn > mx) maxR.value = String(mn);
            paint();
        });
        maxR.addEventListener('input', function () {
            var mn = clamp(minR.value);
            var mx = clamp(maxR.value);
            if (mx < mn) minR.value = String(mx);
            paint();
        });
        paint();
    }

    bindDualRange({
        minRangeId: 'onb-age-range-min',
        maxRangeId: 'onb-age-range-max',
        minHiddenId: 'onb-age-min-hidden',
        maxHiddenId: 'onb-age-max-hidden',
        fillId: 'onb-age-range-fill',
        labelId: 'onb-age-range-label',
        absMin: 18,
        absMax: 80,
        unit: 'age'
    });
    bindDualRange({
        minRangeId: 'onb-height-range-min',
        maxRangeId: 'onb-height-range-max',
        minHiddenId: 'onb-height-min-hidden',
        maxHiddenId: 'onb-height-max-hidden',
        fillId: 'onb-height-range-fill',
        labelId: 'onb-height-range-label',
        absMin: 120,
        absMax: 220,
        unit: 'cm'
    });

    var payloadEl = document.querySelector('script[data-exp-json]');
    if (payloadEl) {
        var templates = [];
        try { templates = JSON.parse(payloadEl.textContent || '[]'); } catch (e) { templates = []; }
        document.querySelectorAll('[data-exp-template]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-exp-index') || '-1', 10);
                var target = document.getElementById(btn.getAttribute('data-exp-target') || '');
                if (!target || idx < 0 || idx >= templates.length) return;
                var tpl = templates[idx];
                target.value = (tpl && typeof tpl === 'object') ? (tpl.text || '') : String(tpl || '');
            });
        });
    }

    function syncCasteByReligion() {
        var selectedReligionIds = [];
        document.querySelectorAll('.community-religion:checked').forEach(function (el) {
            selectedReligionIds.push(parseInt(el.value, 10));
        });
        document.querySelectorAll('.caste-chip').forEach(function (chip) {
            var relId = parseInt(chip.getAttribute('data-religion-id') || '0', 10);
            var show = selectedReligionIds.length === 0 || selectedReligionIds.indexOf(relId) !== -1;
            chip.style.display = show ? '' : 'none';
            var cb = chip.querySelector('input.community-caste');
            if (cb) {
                cb.disabled = !show;
                if (!show) cb.checked = false;
            }
        });
    }
    document.querySelectorAll('.community-religion').forEach(function (el) {
        el.addEventListener('change', syncCasteByReligion);
    });
    syncCasteByReligion();
});
</script>
