    @php
        $partnerMaritalSelectedIds = collect(old('preferred_marital_status_ids', $preferredMaritalStatusIds ?? []))->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()->all();
        $allMaritalStatusIdsForUi = collect($allMaritalStatuses ?? [])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $maritalIdsForCheckboxUi = ($partnerMaritalSelectedIds === [] && $allMaritalStatusIdsForUi !== [])
            ? $allMaritalStatusIdsForUi
            : $partnerMaritalSelectedIds;
        $neverMsId = $neverMarriedMaritalStatusId ?? null;
        $onlyNeverMaritalPref = $neverMsId !== null
            && count($partnerMaritalSelectedIds) === 1
            && (int) $partnerMaritalSelectedIds[0] === (int) $neverMsId;
        $showPartnerChildren = ! $onlyNeverMaritalPref;
        $pwc = $partnerProfileWithChildren ?? null;
    @endphp
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.marriage_type_preference') }}</label>
            <select name="marriage_type_preference_id" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-3 py-2">
                <option value="">{{ __('common.select_placeholder') }}</option>
                @foreach($marriageTypePreferences ?? [] as $mtp)
                    <option value="{{ $mtp->id }}" {{ (string)($oldCriteria['marriage_type_preference_id'] ?? '') === (string)$mtp->id ? 'selected' : '' }}>{{ $optionLabel($mtp, 'marriage_type_preference') }}</option>
                @endforeach
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('wizard.marital_status_preference') }}</label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('wizard.open_to_all_marital_pref') }}</p>
            <div class="flex flex-wrap gap-2 max-h-28 overflow-y-auto">
                @foreach(($allMaritalStatuses ?? collect()) as $ms)
                    <label class="inline-flex items-center gap-1 rounded-full border border-gray-300 dark:border-gray-600 px-2 py-0.5 text-xs cursor-pointer text-gray-800 dark:text-gray-100">
                        <input type="checkbox" name="preferred_marital_status_ids[]" value="{{ $ms->id }}" class="partner-marital-pref-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:outline-none focus:ring-0 focus:ring-offset-0"
                            {{ in_array((int) $ms->id, $maritalIdsForCheckboxUi, true) ? 'checked' : '' }}>
                        <span>{{ $optionLabel($ms, 'marital_status') }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>
    <div id="partner-profile-with-children-wrap" class="mt-4 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/40 p-4 space-y-3 {{ $showPartnerChildren ? '' : 'hidden' }}">
        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('wizard.profile_with_children_partner') }}</p>
        <div class="flex flex-col sm:flex-row sm:flex-wrap gap-3 text-sm">
            <label class="inline-flex items-center gap-2 cursor-pointer text-gray-700 dark:text-gray-300">
                <input type="radio" name="partner_profile_with_children" value="no" class="rounded border-gray-300 dark:border-gray-600"
                    {{ ($pwc === 'no') ? 'checked' : '' }} {{ $showPartnerChildren ? '' : 'disabled' }}>
                <span>{{ __('wizard.partner_children_no') }}</span>
            </label>
            <label class="inline-flex items-center gap-2 cursor-pointer text-gray-700 dark:text-gray-300">
                <input type="radio" name="partner_profile_with_children" value="yes_if_live_separate" class="rounded border-gray-300 dark:border-gray-600"
                    {{ ($pwc === 'yes_if_live_separate') ? 'checked' : '' }} {{ $showPartnerChildren ? '' : 'disabled' }}>
                <span>{{ __('wizard.partner_children_yes_if_live_separate') }}</span>
            </label>
            <label class="inline-flex items-center gap-2 cursor-pointer text-gray-700 dark:text-gray-300">
                <input type="radio" name="partner_profile_with_children" value="yes" class="rounded border-gray-300 dark:border-gray-600"
                    {{ ($pwc === 'yes') ? 'checked' : '' }} {{ $showPartnerChildren ? '' : 'disabled' }}>
                <span>{{ __('wizard.partner_children_yes') }}</span>
            </label>
        </div>
    </div>
    <script>
        (function () {
            var wrap = document.getElementById('partner-profile-with-children-wrap');
            var neverId = @json((string) ($neverMarriedMaritalStatusId ?? ''));
            if (!wrap) {
                return;
            }
            function syncPartnerChildrenBlock() {
                var checked = Array.prototype.slice.call(document.querySelectorAll('input.partner-marital-pref-cb:checked'));
                var ids = checked.map(function (el) { return String(el.value || ''); });
                var hide = neverId !== '' && ids.length === 1 && ids[0] === neverId;
                wrap.classList.toggle('hidden', hide);
                wrap.querySelectorAll('input[name="partner_profile_with_children"]').forEach(function (el) {
                    el.disabled = hide;
                });
            }
            window.__syncPartnerPrefMaritalChildren = syncPartnerChildrenBlock;
            document.querySelectorAll('input.partner-marital-pref-cb').forEach(function (el) {
                el.addEventListener('change', syncPartnerChildrenBlock);
            });
            syncPartnerChildrenBlock();
        })();
    </script>
    @php
        $compactPartnerPref = (bool) ($compactPartnerPref ?? false);
        $rangeCardClass = $compactPartnerPref ? 'p-3 space-y-1.5' : 'p-4 space-y-2';
        $rangeSliderHeightClass = $compactPartnerPref ? 'h-8' : 'h-10';
        $rangeInputHeightClass = $compactPartnerPref ? 'h-8' : 'h-10';
    @endphp
    <div class="space-y-4">
        <style>
            .partner-pref-dual-range { pointer-events: none; }
            .partner-pref-dual-range::-webkit-slider-thumb { pointer-events: auto; -webkit-appearance: none; appearance: none; height: 1.125rem; width: 1.125rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.15); cursor: grab; margin-top: -5px; }
            .partner-pref-dual-range::-moz-range-thumb { pointer-events: auto; height: 1.125rem; width: 1.125rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.15); cursor: grab; }
            .partner-pref-dual-range::-webkit-slider-runnable-track { -webkit-appearance: none; appearance: none; height: 8px; background: transparent; }
            .partner-pref-dual-range::-moz-range-track { height: 8px; background: transparent; }
        </style>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-stretch">
            <div class="rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 {{ $rangeCardClass }} shadow-sm min-w-0">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('wizard.preferred_age_range') }}</label>
            @if (! $compactPartnerPref)
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.preferred_age_range_hint') }}</p>
            @endif
            <p id="partner-age-range-label" class="text-base font-semibold text-indigo-700 dark:text-indigo-300 tabular-nums" aria-live="polite">{{ $ageMinInit }} – {{ $ageMaxInit }} {{ __('wizard.years') }}</p>
            <div class="partner-age-slider relative {{ $rangeSliderHeightClass }} px-0.5" data-age-absolute-min="18" data-age-absolute-max="80">
                <div class="absolute left-0 right-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-gray-200 dark:bg-gray-600 pointer-events-none"></div>
                <div id="partner-age-range-fill" class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-indigo-500 pointer-events-none" style="left: 0%; width: 0%"></div>
                <input type="range" id="partner-age-range-min" class="partner-pref-dual-range absolute inset-x-0 top-0 z-[2] {{ $rangeInputHeightClass }} w-full cursor-pointer appearance-none bg-transparent" min="18" max="80" step="1" value="{{ $ageMinInit }}" aria-label="{{ __('wizard.age_min') }}">
                <input type="range" id="partner-age-range-max" class="partner-pref-dual-range absolute inset-x-0 top-0 z-[3] {{ $rangeInputHeightClass }} w-full cursor-pointer appearance-none bg-transparent" min="18" max="80" step="1" value="{{ $ageMaxInit }}" aria-label="{{ __('wizard.age_max') }}">
            </div>
            <input type="hidden" name="preferred_age_min" id="partner-age-min-hidden" value="{{ $ageMinInit }}">
            <input type="hidden" name="preferred_age_max" id="partner-age-max-hidden" value="{{ $ageMaxInit }}">
            <script>
                (function () {
                    var ABS_MIN = 18;
                    var ABS_MAX = 80;
                    var minR = document.getElementById('partner-age-range-min');
                    var maxR = document.getElementById('partner-age-range-max');
                    var minH = document.getElementById('partner-age-min-hidden');
                    var maxH = document.getElementById('partner-age-max-hidden');
                    var fill = document.getElementById('partner-age-range-fill');
                    var label = document.getElementById('partner-age-range-label');
                    var yearsWord = @json(__('wizard.years'));
                    if (!minR || !maxR || !minH || !maxH || !fill || !label) return;

                    function clamp(n) {
                        n = parseInt(n, 10);
                        if (isNaN(n)) return ABS_MIN;
                        return Math.max(ABS_MIN, Math.min(ABS_MAX, n));
                    }

                    function syncZ() {
                        var mn = parseInt(minR.value, 10);
                        var mx = parseInt(maxR.value, 10);
                        minR.style.zIndex = mn > ABS_MAX - 5 ? '4' : '2';
                        maxR.style.zIndex = mx < ABS_MIN + 5 ? '4' : '3';
                    }

                    function paint() {
                        var mn = clamp(minR.value);
                        var mx = clamp(maxR.value);
                        if (mn > mx) {
                            var t = mn;
                            mn = mx;
                            mx = t;
                            minR.value = String(mn);
                            maxR.value = String(mx);
                        }
                        minH.value = String(mn);
                        maxH.value = String(mx);
                        var p1 = ((mn - ABS_MIN) / (ABS_MAX - ABS_MIN)) * 100;
                        var p2 = ((mx - ABS_MIN) / (ABS_MAX - ABS_MIN)) * 100;
                        fill.style.left = p1 + '%';
                        fill.style.width = Math.max(0, p2 - p1) + '%';
                        label.textContent = mn + ' – ' + mx + ' ' + yearsWord;
                        syncZ();
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

                    window.__setPartnerAgeRange = function (mn, mx) {
                        mn = clamp(mn);
                        mx = clamp(mx);
                        if (mn > mx) {
                            var s = mn;
                            mn = mx;
                            mx = s;
                        }
                        minR.value = String(mn);
                        maxR.value = String(mx);
                        paint();
                    };

                    paint();
                })();
            </script>
            </div>
            <div class="rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 {{ $rangeCardClass }} shadow-sm min-w-0">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('wizard.preferred_height_range') }}</label>
                @if(!empty($showHeightHint) && ! $compactPartnerPref)
                    <p class="text-xs text-indigo-600 dark:text-indigo-400">{{ __('wizard.suggested_from_profile') }}</p>
                @endif
                @if (! $compactPartnerPref)
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.preferred_height_range_hint') }}</p>
                @endif
                <p id="partner-height-range-label" class="text-base font-semibold text-indigo-700 dark:text-indigo-300 tabular-nums leading-snug" aria-live="polite">{{ \App\Support\HeightDisplay::formatCmRange($heightMinInit, $heightMaxInit) }}</p>
                <div class="partner-height-slider relative {{ $rangeSliderHeightClass }} px-0.5" data-height-abs-min="{{ $heightSliderMin }}" data-height-abs-max="{{ $heightSliderMax }}">
                    <div class="absolute left-0 right-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-gray-200 dark:bg-gray-600 pointer-events-none"></div>
                    <div id="partner-height-range-fill" class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-indigo-500 pointer-events-none" style="left: 0%; width: 0%"></div>
                    <input type="range" id="partner-height-range-min" class="partner-pref-dual-range absolute inset-x-0 top-0 z-[2] {{ $rangeInputHeightClass }} w-full cursor-pointer appearance-none bg-transparent" min="{{ $heightSliderMin }}" max="{{ $heightSliderMax }}" step="1" value="{{ $heightMinInit }}" aria-label="{{ __('wizard.height_range_min') }}">
                    <input type="range" id="partner-height-range-max" class="partner-pref-dual-range absolute inset-x-0 top-0 z-[3] {{ $rangeInputHeightClass }} w-full cursor-pointer appearance-none bg-transparent" min="{{ $heightSliderMin }}" max="{{ $heightSliderMax }}" step="1" value="{{ $heightMaxInit }}" aria-label="{{ __('wizard.height_range_max') }}">
                </div>
                <input type="hidden" name="preferred_height_min_cm" id="partner-height-min-hidden" value="{{ $heightMinInit }}">
                <input type="hidden" name="preferred_height_max_cm" id="partner-height-max-hidden" value="{{ $heightMaxInit }}">
                <script>
                    (function () {
                        var ABS_MIN = {{ $heightSliderMin }};
                        var ABS_MAX = {{ $heightSliderMax }};
                        var minR = document.getElementById('partner-height-range-min');
                        var maxR = document.getElementById('partner-height-range-max');
                        var minH = document.getElementById('partner-height-min-hidden');
                        var maxH = document.getElementById('partner-height-max-hidden');
                        var fill = document.getElementById('partner-height-range-fill');
                        var label = document.getElementById('partner-height-range-label');
                        if (!minR || !maxR || !minH || !maxH || !fill || !label) return;

                        function cmToFtIn(cm) {
                            cm = parseInt(cm, 10);
                            if (isNaN(cm)) return [0, 0];
                            var totalIn = cm / 2.54;
                            var ft = Math.floor(totalIn / 12);
                            var inch = Math.round(totalIn - ft * 12);
                            if (inch === 12) { ft++; inch = 0; }
                            return [ft, inch];
                        }
                        function fmtOne(cm) {
                            cm = parseInt(cm, 10);
                            if (isNaN(cm) || cm < 1) return '';
                            var p = cmToFtIn(cm);
                            return p[0] + "'" + p[1] + '" (' + cm + ' cm)';
                        }
                        function fmtRange(mn, mx) {
                            return fmtOne(mn) + ' – ' + fmtOne(mx);
                        }

                        function clamp(n) {
                            n = parseInt(n, 10);
                            if (isNaN(n)) return ABS_MIN;
                            return Math.max(ABS_MIN, Math.min(ABS_MAX, n));
                        }
                        function syncZ() {
                            var mn = parseInt(minR.value, 10);
                            var mx = parseInt(maxR.value, 10);
                            minR.style.zIndex = mn > ABS_MAX - 8 ? '4' : '2';
                            maxR.style.zIndex = mx < ABS_MIN + 8 ? '4' : '3';
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
                            var span = ABS_MAX - ABS_MIN;
                            var p1 = span > 0 ? ((mn - ABS_MIN) / span) * 100 : 0;
                            var p2 = span > 0 ? ((mx - ABS_MIN) / span) * 100 : 0;
                            fill.style.left = p1 + '%';
                            fill.style.width = Math.max(0, p2 - p1) + '%';
                            label.textContent = fmtRange(mn, mx);
                            syncZ();
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
                        window.__setPartnerHeightRangeCm = function (mn, mx) {
                            mn = clamp(mn);
                            mx = clamp(mx);
                            if (mn > mx) { var s = mn; mn = mx; mx = s; }
                            minR.value = String(mn);
                            maxR.value = String(mx);
                            paint();
                        };
                        paint();
                    })();
                </script>
            </div>
        </div>
    </div>

    @if(!empty($preferencePresetDefaults ?? []))
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var presets = @json($preferencePresetDefaults);
                var selectEl = document.getElementById('preference_preset');
                if (!selectEl || !presets) {
                    return;
                }

                function applyPreset(presetKey) {
                    var preset = presets[presetKey];
                    if (!preset) {
                        return;
                    }

                    var ageMin = document.getElementById('partner-age-min-hidden');
                    var ageMax = document.getElementById('partner-age-max-hidden');
                    var incomeMin = document.querySelector('input[name="preferred_income_min"]');
                    var incomeMax = document.querySelector('input[name="preferred_income_max"]');
                    var religionCbs = document.querySelectorAll('input.partner-religion-cb');

                    if (typeof window.__setPartnerAgeRange === 'function'
                        && preset.preferred_age_min !== undefined && preset.preferred_age_min !== null
                        && preset.preferred_age_max !== undefined && preset.preferred_age_max !== null) {
                        window.__setPartnerAgeRange(preset.preferred_age_min, preset.preferred_age_max);
                    } else {
                        if (ageMin && preset.preferred_age_min !== undefined && preset.preferred_age_min !== null) {
                            ageMin.value = preset.preferred_age_min;
                        }
                        if (ageMax && preset.preferred_age_max !== undefined && preset.preferred_age_max !== null) {
                            ageMax.value = preset.preferred_age_max;
                        }
                    }
                    if (typeof window.__setPartnerIncomeRange === 'function'
                        && preset.preferred_income_min !== undefined && preset.preferred_income_min !== null) {
                        window.__setPartnerIncomeRange(
                            preset.preferred_income_min,
                            preset.preferred_income_max !== undefined && preset.preferred_income_max !== null
                                ? preset.preferred_income_max
                                : null
                        );
                    } else {
                        if (incomeMin && preset.preferred_income_min !== undefined && preset.preferred_income_min !== null) {
                            incomeMin.value = preset.preferred_income_min;
                        }
                        if (incomeMax && preset.preferred_income_max !== undefined && preset.preferred_income_max !== null) {
                            incomeMax.value = preset.preferred_income_max;
                        }
                    }
                    if (religionCbs.length && Array.isArray(preset.preferred_religion_ids)) {
                        var relIds = preset.preferred_religion_ids.map(function (v) { return parseInt(v, 10); });
                        religionCbs.forEach(function (cb) {
                            var val = parseInt(cb.value || '0', 10);
                            cb.checked = relIds.indexOf(val) !== -1;
                        });
                        setTimeout(function () {
                            if (typeof window.__partnerCommunityApplyCasteIds === 'function' && Array.isArray(preset.preferred_caste_ids)) {
                                window.__partnerCommunityApplyCasteIds(preset.preferred_caste_ids.map(function (v) { return parseInt(v, 10); }));
                            } else if (typeof window.__partnerCommunityRefreshCastes === 'function') {
                                window.__partnerCommunityRefreshCastes();
                            }
                        }, 0);
                    }
                    var maritalCbs = document.querySelectorAll('input.partner-marital-pref-cb');
                    if (maritalCbs.length) {
                        if (Array.isArray(preset.preferred_marital_status_ids) && preset.preferred_marital_status_ids.length > 0) {
                            var mIds = preset.preferred_marital_status_ids.map(function (v) { return parseInt(v, 10); });
                            maritalCbs.forEach(function (cb) {
                                var val = parseInt(cb.value || '0', 10);
                                cb.checked = mIds.indexOf(val) !== -1;
                            });
                        } else if (preset.preferred_marital_status_id !== undefined && preset.preferred_marital_status_id !== null && preset.preferred_marital_status_id !== '') {
                            var singleM = parseInt(preset.preferred_marital_status_id, 10);
                            maritalCbs.forEach(function (cb) {
                                cb.checked = parseInt(cb.value || '0', 10) === singleM;
                            });
                        } else {
                            maritalCbs.forEach(function (cb) { cb.checked = true; });
                        }
                    }
                    if (typeof window.__syncPartnerPrefMaritalChildren === 'function') {
                        window.__syncPartnerPrefMaritalChildren();
                    }
                }

                selectEl.addEventListener('change', function () {
                    var value = this.value;
                    if (value === 'custom') {
                        return;
                    }
                    applyPreset(value);
                });
            });
        </script>
    @endif
