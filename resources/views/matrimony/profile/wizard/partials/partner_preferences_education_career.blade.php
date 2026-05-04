<div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 p-4 space-y-4 mt-6" id="partner-pref-education-career-section">
    <div class="border-b border-gray-200 dark:border-gray-700 pb-2">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.partner_education_career_heading') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('wizard.partner_education_career_intro') }}</p>
    </div>

    <div class="flex flex-wrap gap-2 pt-1" role="navigation" aria-label="{{ __('wizard.partner_education_career_heading') }}">
        <a href="#partner-pref-ec-qual" class="inline-flex items-center rounded-full border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 px-2.5 py-1 text-[11px] font-medium text-gray-700 dark:text-gray-200 hover:border-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300">{{ __('wizard.pref_qualification_label') }}</a>
        <a href="#partner-pref-ec-occupation" class="inline-flex items-center rounded-full border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 px-2.5 py-1 text-[11px] font-medium text-gray-700 dark:text-gray-200 hover:border-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300">{{ __('wizard.partner_pref_occupation_heading') }}</a>
        <a href="#partner-pref-ec-income" class="inline-flex items-center rounded-full border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 px-2.5 py-1 text-[11px] font-medium text-gray-700 dark:text-gray-200 hover:border-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300">{{ __('wizard.pref_annual_income_label') }}</a>
    </div>

    <style>
        .partner-ec-pref-dual-range { pointer-events: none; }
        .partner-ec-pref-dual-range::-webkit-slider-thumb { pointer-events: auto; -webkit-appearance: none; appearance: none; height: 1.125rem; width: 1.125rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.15); cursor: grab; margin-top: -5px; }
        .partner-ec-pref-dual-range::-moz-range-thumb { pointer-events: auto; height: 1.125rem; width: 1.125rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.15); cursor: grab; }
        .partner-ec-pref-dual-range::-webkit-slider-runnable-track { -webkit-appearance: none; appearance: none; height: 8px; background: transparent; }
        .partner-ec-pref-dual-range::-moz-range-track { height: 8px; background: transparent; }
    </style>

    {{-- 1) Qualification — SSOT: education_degrees (+ categories), same catalogue as onboarding education engine --}}
    @php
        $eduPrefLocaleMr = str_starts_with(strtolower((string) app()->getLocale()), 'mr');
        $selectedPreferredEducationDegreeIds = $selectedPreferredEducationDegreeIds ?? [];
    @endphp
    <div id="partner-pref-ec-qual" class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 p-3 space-y-2 scroll-mt-4">
        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.pref_qualification_label') }}</h4>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.pref_qualification_hint') }}</p>
        <input type="search" id="partner-ec-education-filter" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm" placeholder="{{ __('wizard.filter_locations') }}" autocomplete="off">
        <div class="max-h-72 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-white dark:bg-gray-800/60">
            <div id="partner-ec-education-chips" class="space-y-3">
                @foreach(($educationCategoriesPartnerPrefs ?? collect()) as $cat)
                    @if($cat->degrees->isEmpty())
                        @continue
                    @endif
                    @php
                        $catLabel = $eduPrefLocaleMr && filled($cat->name_mr ?? null) ? $cat->name_mr : $cat->name;
                        $catSelectAllTitle = __('wizard.partner_pref_education_category_select_all');
                    @endphp
                    <div class="partner-ec-edu-category space-y-1.5" data-chip-label="{{ $catLabel }}">
                        @php
                            $partnerEcCatAllId = 'partner-ec-cat-all-'.(int) $cat->id;
                        @endphp
                        <div class="flex items-center gap-2">
                            <input
                                id="{{ $partnerEcCatAllId }}"
                                type="checkbox"
                                class="partner-ec-edu-category-all size-4 shrink-0 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                                title="{{ $catSelectAllTitle }}"
                                aria-label="{{ $catSelectAllTitle }}: {{ $catLabel }}"
                            >
                            <label for="{{ $partnerEcCatAllId }}" class="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 select-none">{{ $catLabel }}</label>
                        </div>
                        <div class="flex flex-wrap gap-2 content-start partner-ec-edu-category-degrees">
                            @foreach($cat->degrees as $deg)
                                @php
                                    $degLabel = $eduPrefLocaleMr && filled($deg->title_mr ?? null) ? $deg->title_mr : $deg->title;
                                @endphp
                                <label class="partner-ec-edu-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500" data-chip-label="{{ $degLabel }}">
                                    <input type="checkbox" name="preferred_education_degree_ids[]" value="{{ $deg->id }}" class="partner-ec-edu-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"
                                        @if(in_array((int) $deg->id, $selectedPreferredEducationDegreeIds, true)) checked @endif>
                                    <span class="text-gray-800 dark:text-gray-100">{{ $degLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- 2) Occupation — SSOT: occupation_master + occupation_categories (same engine as onboarding `<x-occupation-search-engine>` / wizard career) --}}
    @php
        $occPrefLocaleMr = str_starts_with(strtolower((string) app()->getLocale()), 'mr');
        $selectedPreferredOccupationMasterIds = $selectedPreferredOccupationMasterIds ?? [];
    @endphp
    <div id="partner-pref-ec-occupation" class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 p-3 space-y-2 scroll-mt-4">
        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.partner_pref_occupation_heading') }}</h4>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.partner_pref_occupation_intro') }}</p>
        <input type="search" id="partner-ec-occupation-filter" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm" placeholder="{{ __('wizard.filter_locations') }}" autocomplete="off">
        <div class="max-h-72 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-white dark:bg-gray-800/60">
            <div id="partner-ec-occupation-chips" class="space-y-3">
                @foreach(($occupationCategoriesPartnerPrefs ?? collect()) as $occCat)
                    @if($occCat->occupations->isEmpty())
                        @continue
                    @endif
                    @php
                        $occCatLabel = $occPrefLocaleMr && filled($occCat->name_mr ?? null) ? $occCat->name_mr : $occCat->name;
                        $occCatSelectAllTitle = __('wizard.partner_pref_occupation_category_select_all');
                        $partnerEcOccCatAllId = 'partner-ec-occ-cat-all-'.(int) $occCat->id;
                    @endphp
                    <div class="partner-ec-occ-category space-y-1.5" data-chip-label="{{ $occCatLabel }}">
                        <div class="flex items-center gap-2">
                            <input
                                id="{{ $partnerEcOccCatAllId }}"
                                type="checkbox"
                                class="partner-ec-occ-category-all size-4 shrink-0 rounded border-gray-300 dark:border-gray-600 text-teal-600 focus:ring-teal-500"
                                title="{{ $occCatSelectAllTitle }}"
                                aria-label="{{ $occCatSelectAllTitle }}: {{ $occCatLabel }}"
                            >
                            <label for="{{ $partnerEcOccCatAllId }}" class="cursor-pointer text-[11px] font-semibold uppercase tracking-wide text-teal-800 dark:text-teal-300 select-none">{{ $occCatLabel }}</label>
                        </div>
                        <div class="flex flex-wrap gap-2 content-start">
                            @foreach($occCat->occupations as $occ)
                                @php
                                    $occLabel = $occPrefLocaleMr && filled($occ->name_mr ?? null) ? $occ->name_mr : $occ->name;
                                @endphp
                                <label class="partner-ec-occ-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer hover:border-teal-400 dark:hover:border-teal-500" data-chip-label="{{ $occLabel }}">
                                    <input type="checkbox" name="preferred_occupation_master_ids[]" value="{{ $occ->id }}" class="partner-ec-occ-cb rounded border-gray-300 dark:border-gray-600 text-teal-600"
                                        @if(in_array((int) $occ->id, $selectedPreferredOccupationMasterIds, true)) checked @endif>
                                    <span class="text-gray-800 dark:text-gray-100">{{ $occLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- 3) Annual income (existing min/max rupees) --}}
    <div id="partner-pref-ec-income" class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 p-3 space-y-2 scroll-mt-4">
        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.pref_annual_income_label') }}</h4>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.pref_annual_income_hint') }}</p>
        <p id="partner-income-range-label" class="text-base font-semibold text-indigo-700 dark:text-indigo-300 tabular-nums" aria-live="polite">{{ $incomeFmtLakh($incMinLakhs) }} – {{ $incomeFmtLakh($incMaxLakhs) }}</p>
        <div class="partner-income-slider relative h-10 px-0.5">
            <div class="absolute left-0 right-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-gray-200 dark:bg-gray-600 pointer-events-none"></div>
            <div id="partner-income-range-fill" class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-indigo-500 pointer-events-none" style="left: 0%; width: 0%"></div>
            <input type="range" id="partner-income-range-min" class="partner-ec-pref-dual-range absolute inset-x-0 top-0 z-[2] h-10 w-full cursor-pointer appearance-none bg-transparent" min="0" max="500" step="1" value="{{ $incMinLakhs }}" aria-label="{{ __('wizard.income_min') }}">
            <input type="range" id="partner-income-range-max" class="partner-ec-pref-dual-range absolute inset-x-0 top-0 z-[3] h-10 w-full cursor-pointer appearance-none bg-transparent" min="0" max="500" step="1" value="{{ $incMaxLakhs }}" aria-label="{{ __('wizard.income_max') }}">
        </div>
        <input type="hidden" name="preferred_income_min" id="partner-income-min-hidden" value="{{ $incMinLakhs * 100000 }}">
        <input type="hidden" name="preferred_income_max" id="partner-income-max-hidden" value="{{ $incMaxLakhs * 100000 }}">
        <script>
            (function () {
                var ABS_MIN = 0;
                var ABS_MAX = 500;
                var LAKH = 100000;
                var minR = document.getElementById('partner-income-range-min');
                var maxR = document.getElementById('partner-income-range-max');
                var minH = document.getElementById('partner-income-min-hidden');
                var maxH = document.getElementById('partner-income-max-hidden');
                var fill = document.getElementById('partner-income-range-fill');
                var label = document.getElementById('partner-income-range-label');
                var lakhFmt = @json(__('wizard.income_lakh_format'));
                var lakhZero = @json(__('wizard.income_lakh_zero'));
                if (!minR || !maxR || !minH || !maxH || !fill || !label) return;

                function fmtLakhs(l) {
                    l = parseInt(l, 10);
                    if (isNaN(l)) l = 0;
                    if (l === 0) return lakhZero;
                    return String(lakhFmt).split(':num').join(String(l));
                }

                function clamp(n) {
                    n = parseInt(n, 10);
                    if (isNaN(n)) return ABS_MIN;
                    return Math.max(ABS_MIN, Math.min(ABS_MAX, n));
                }

                function syncZ() {
                    var mn = parseInt(minR.value, 10);
                    var mx = parseInt(maxR.value, 10);
                    minR.style.zIndex = mn > ABS_MAX - 25 ? '4' : '2';
                    maxR.style.zIndex = mx < ABS_MIN + 25 ? '4' : '3';
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
                    minH.value = String(mn * LAKH);
                    maxH.value = String(mx * LAKH);
                    var p1 = ABS_MAX > ABS_MIN ? ((mn - ABS_MIN) / (ABS_MAX - ABS_MIN)) * 100 : 0;
                    var p2 = ABS_MAX > ABS_MIN ? ((mx - ABS_MIN) / (ABS_MAX - ABS_MIN)) * 100 : 0;
                    fill.style.left = p1 + '%';
                    fill.style.width = Math.max(0, p2 - p1) + '%';
                    label.textContent = fmtLakhs(mn) + ' – ' + fmtLakhs(mx);
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

                window.__setPartnerIncomeRange = function (minRupees, maxRupees) {
                    var mn = Math.round((parseFloat(minRupees) || 0) / LAKH);
                    var mx = maxRupees != null && maxRupees !== ''
                        ? Math.round((parseFloat(maxRupees) || 0) / LAKH)
                        : Math.min(ABS_MAX, mn + 20);
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

    <script>
        (function () {
            function applyEcFilter(chipClass, filterId) {
                var q = ((document.getElementById(filterId) || {}).value || '').trim().toLowerCase();
                document.querySelectorAll('.' + chipClass).forEach(function (el) {
                    var lab = (el.getAttribute('data-chip-label') || '').toLowerCase();
                    el.style.display = !q || lab.indexOf(q) !== -1 ? '' : 'none';
                });
            }

            function applyPartnerEducationCategoryFilter() {
                var q = ((document.getElementById('partner-ec-education-filter') || {}).value || '').trim().toLowerCase();
                document.querySelectorAll('#partner-ec-education-chips .partner-ec-edu-category').forEach(function (catEl) {
                    var catLab = (catEl.getAttribute('data-chip-label') || '').toLowerCase();
                    var catMatches = q !== '' && catLab.indexOf(q) !== -1;
                    var anyChipVisible = false;
                    catEl.querySelectorAll('.partner-ec-edu-chip').forEach(function (chip) {
                        var lab = (chip.getAttribute('data-chip-label') || '').toLowerCase();
                        var show = q === '' || lab.indexOf(q) !== -1 || catMatches;
                        chip.style.display = show ? '' : 'none';
                        if (show) {
                            anyChipVisible = true;
                        }
                    });
                    catEl.style.display = (q === '' || catMatches || anyChipVisible) ? '' : 'none';
                });
            }

            function syncPartnerEduCategoryHeader(catRoot) {
                var allInput = catRoot.querySelector('.partner-ec-edu-category-all');
                var degreeCbs = catRoot.querySelectorAll('input.partner-ec-edu-cb[type="checkbox"]');
                if (!allInput || degreeCbs.length === 0) {
                    return;
                }
                var checked = 0;
                degreeCbs.forEach(function (c) {
                    if (c.checked) {
                        checked++;
                    }
                });
                var n = degreeCbs.length;
                allInput.checked = checked === n && n > 0;
                allInput.indeterminate = checked > 0 && checked < n;
            }

            function initPartnerEduCategorySelectAll() {
                document.querySelectorAll('#partner-ec-education-chips .partner-ec-edu-category').forEach(function (catEl) {
                    syncPartnerEduCategoryHeader(catEl);
                    var allInput = catEl.querySelector('.partner-ec-edu-category-all');
                    if (!allInput) {
                        return;
                    }
                    allInput.addEventListener('change', function () {
                        var on = allInput.checked;
                        catEl.querySelectorAll('input.partner-ec-edu-cb[type="checkbox"]').forEach(function (d) {
                            d.checked = on;
                        });
                        allInput.indeterminate = false;
                    });
                    catEl.querySelectorAll('input.partner-ec-edu-cb[type="checkbox"]').forEach(function (d) {
                        d.addEventListener('change', function () {
                            syncPartnerEduCategoryHeader(catEl);
                        });
                    });
                });
            }

            function applyPartnerOccupationCategoryFilter() {
                var q = ((document.getElementById('partner-ec-occupation-filter') || {}).value || '').trim().toLowerCase();
                document.querySelectorAll('#partner-ec-occupation-chips .partner-ec-occ-category').forEach(function (catEl) {
                    var catLab = (catEl.getAttribute('data-chip-label') || '').toLowerCase();
                    var catMatches = q !== '' && catLab.indexOf(q) !== -1;
                    var anyChipVisible = false;
                    catEl.querySelectorAll('.partner-ec-occ-chip').forEach(function (chip) {
                        var lab = (chip.getAttribute('data-chip-label') || '').toLowerCase();
                        var show = q === '' || lab.indexOf(q) !== -1 || catMatches;
                        chip.style.display = show ? '' : 'none';
                        if (show) {
                            anyChipVisible = true;
                        }
                    });
                    catEl.style.display = (q === '' || catMatches || anyChipVisible) ? '' : 'none';
                });
            }

            function syncPartnerOccCategoryHeader(catRoot) {
                var allInput = catRoot.querySelector('.partner-ec-occ-category-all');
                var occCbs = catRoot.querySelectorAll('input.partner-ec-occ-cb[type="checkbox"]');
                if (!allInput || occCbs.length === 0) {
                    return;
                }
                var checked = 0;
                occCbs.forEach(function (c) {
                    if (c.checked) {
                        checked++;
                    }
                });
                var n = occCbs.length;
                allInput.checked = checked === n && n > 0;
                allInput.indeterminate = checked > 0 && checked < n;
            }

            function initPartnerOccCategorySelectAll() {
                document.querySelectorAll('#partner-ec-occupation-chips .partner-ec-occ-category').forEach(function (catEl) {
                    syncPartnerOccCategoryHeader(catEl);
                    var allInput = catEl.querySelector('.partner-ec-occ-category-all');
                    if (!allInput) {
                        return;
                    }
                    allInput.addEventListener('change', function () {
                        var on = allInput.checked;
                        catEl.querySelectorAll('input.partner-ec-occ-cb[type="checkbox"]').forEach(function (d) {
                            d.checked = on;
                        });
                        allInput.indeterminate = false;
                    });
                    catEl.querySelectorAll('input.partner-ec-occ-cb[type="checkbox"]').forEach(function (d) {
                        d.addEventListener('change', function () {
                            syncPartnerOccCategoryHeader(catEl);
                        });
                    });
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                var ef = document.getElementById('partner-ec-education-filter');
                var of = document.getElementById('partner-ec-occupation-filter');
                if (ef) ef.addEventListener('input', function () { applyPartnerEducationCategoryFilter(); });
                if (of) of.addEventListener('input', function () { applyPartnerOccupationCategoryFilter(); });
                initPartnerEduCategorySelectAll();
                initPartnerOccCategorySelectAll();
            });
        })();
    </script>
</div>
