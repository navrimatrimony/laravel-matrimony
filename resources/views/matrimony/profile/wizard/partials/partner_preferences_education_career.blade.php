<div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/60 p-4 space-y-4 mt-6" id="partner-pref-education-career-section">
    <div class="border-b border-gray-200 dark:border-gray-700 pb-2">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.partner_education_career_heading') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('wizard.partner_education_career_intro') }}</p>
    </div>

    <div class="flex flex-wrap gap-2 pt-1" role="navigation" aria-label="{{ __('wizard.partner_education_career_heading') }}">
        <a href="#partner-pref-ec-qual" class="inline-flex items-center rounded-full border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 px-2.5 py-1 text-[11px] font-medium text-gray-700 dark:text-gray-200 hover:border-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300">{{ __('wizard.pref_qualification_label') }}</a>
        <a href="#partner-pref-ec-ww" class="inline-flex items-center rounded-full border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 px-2.5 py-1 text-[11px] font-medium text-gray-700 dark:text-gray-200 hover:border-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300">{{ __('wizard.pref_working_with_label') }}</a>
        <a href="#partner-pref-ec-prof" class="inline-flex items-center rounded-full border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 px-2.5 py-1 text-[11px] font-medium text-gray-700 dark:text-gray-200 hover:border-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300">{{ __('wizard.pref_profession_label') }}</a>
        <a href="#partner-pref-ec-income" class="inline-flex items-center rounded-full border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 px-2.5 py-1 text-[11px] font-medium text-gray-700 dark:text-gray-200 hover:border-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300">{{ __('wizard.pref_annual_income_label') }}</a>
    </div>

    <style>
        .partner-ec-pref-dual-range { pointer-events: none; }
        .partner-ec-pref-dual-range::-webkit-slider-thumb { pointer-events: auto; -webkit-appearance: none; appearance: none; height: 1.125rem; width: 1.125rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.15); cursor: grab; margin-top: -5px; }
        .partner-ec-pref-dual-range::-moz-range-thumb { pointer-events: auto; height: 1.125rem; width: 1.125rem; border-radius: 9999px; background: rgb(79 70 229); border: 2px solid rgb(255 255 255); box-shadow: 0 1px 2px rgb(0 0 0 / 0.15); cursor: grab; }
        .partner-ec-pref-dual-range::-webkit-slider-runnable-track { -webkit-appearance: none; appearance: none; height: 8px; background: transparent; }
        .partner-ec-pref-dual-range::-moz-range-track { height: 8px; background: transparent; }
    </style>

    {{-- 1) Qualification --}}
    <div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 p-3 space-y-2">
        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.pref_qualification_label') }}</h4>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.pref_qualification_hint') }}</p>
        <input type="search" id="partner-ec-education-filter" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm" placeholder="{{ __('wizard.filter_locations') }}" autocomplete="off">
        <div class="max-h-32 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-white dark:bg-gray-800/60">
            <div id="partner-ec-education-chips" class="flex flex-wrap gap-2 content-start">
                @foreach(($masterEducationOptions ?? collect()) as $me)
                    <label class="partner-ec-edu-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500" data-chip-label="{{ $me->name }}">
                        <input type="checkbox" name="preferred_master_education_ids[]" value="{{ $me->id }}" class="partner-ec-edu-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"
                            @if(in_array($me->id, $selectedMasterEducationIds ?? [], true)) checked @endif>
                        <span class="text-gray-800 dark:text-gray-100">{{ $me->name }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    {{-- 2) Working with --}}
    <div id="partner-pref-ec-ww" class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 p-3 space-y-2 scroll-mt-4">
        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.pref_working_with_label') }}</h4>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.pref_working_with_hint') }}</p>
        <input type="search" id="partner-ec-ww-filter" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm" placeholder="{{ __('wizard.filter_locations') }}" autocomplete="off">
        <div class="max-h-28 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-white dark:bg-gray-800/60">
            <div class="flex flex-wrap gap-2" id="partner-ec-ww-chips">
                @foreach(($workingWithTypes ?? collect()) as $ww)
                    <label class="partner-ec-ww-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500" data-chip-label="{{ $ww->name }}">
                        <input type="checkbox" name="preferred_working_with_type_ids[]" value="{{ $ww->id }}" class="partner-ec-ww-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"
                            @if(in_array($ww->id, $selectedWorkingWithTypeIds ?? [], true)) checked @endif>
                        <span class="text-gray-800 dark:text-gray-100">{{ $ww->name }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    {{-- 3) Profession (filtered by working-with) --}}
    <div id="partner-pref-ec-prof" class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 p-3 space-y-2 scroll-mt-4">
        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.pref_profession_label') }}</h4>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.pref_profession_hint') }}</p>
        <p id="partner-ec-profession-placeholder" class="text-xs text-amber-700 dark:text-amber-400 min-h-[1rem] hidden"></p>
        <input type="search" id="partner-ec-profession-filter" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm" placeholder="{{ __('wizard.filter_locations') }}" autocomplete="off">
        <div class="max-h-36 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-white dark:bg-gray-800/60">
            <div id="partner-ec-profession-chips" class="flex flex-wrap gap-2 content-start"></div>
        </div>
    </div>

    {{-- 4) Annual income (existing min/max rupees) --}}
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
            var byWw = @json($partnerProfessionsByWorkingWithType ?? []);
            var professionById = @json($partnerProfessionById ?? []);
            var orphanHint = @json(__('wizard.location_orphan_hint'));
            var selectedProfessionMap = @json(array_fill_keys($selectedProfessionIds ?? [], true));
            var wwDebounce;

            function esc(s) {
                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
            }

            function getSelectedWwIds() {
                var ids = [];
                document.querySelectorAll('input.partner-ec-ww-cb:checked').forEach(function (cb) {
                    ids.push(parseInt(cb.value, 10));
                });
                return ids;
            }

            function mergeProfessionItems() {
                var wids = getSelectedWwIds();
                var seen = {};
                var items = [];
                wids.forEach(function (wid) {
                    var list = byWw[String(wid)] || byWw[wid] || [];
                    list.forEach(function (item) {
                        if (seen[item.id]) return;
                        seen[item.id] = true;
                        items.push({ id: item.id, label: item.name, working_with_type_id: item.working_with_type_id, orphan: false });
                    });
                });
                Object.keys(selectedProfessionMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedProfessionMap[id] || seen[id]) return;
                    var meta = professionById[String(id)] || professionById[id];
                    if (meta) {
                        items.push({ id: meta.id, label: meta.name, working_with_type_id: meta.working_with_type_id, orphan: true });
                        seen[id] = true;
                    }
                });
                items.sort(function (a, b) { return a.label.localeCompare(b.label); });
                return items;
            }

            function pruneProfessionsAfterWwChange() {
                var wids = getSelectedWwIds();
                Object.keys(selectedProfessionMap).forEach(function (k) {
                    var id = parseInt(k, 10);
                    if (!selectedProfessionMap[id]) return;
                    var meta = professionById[String(id)] || professionById[id];
                    if (meta && wids.indexOf(meta.working_with_type_id) === -1) {
                        delete selectedProfessionMap[id];
                    }
                });
            }

            function paintProfessionChips(items) {
                var inner = document.getElementById('partner-ec-profession-chips');
                var ph = document.getElementById('partner-ec-profession-placeholder');
                if (!inner) return;
                var wids = getSelectedWwIds();
                if (ph) {
                    if (wids.length === 0 && items.length === 0) {
                        ph.textContent = @json(__('wizard.select_working_with_first'));
                        ph.classList.remove('hidden');
                    } else {
                        ph.textContent = '';
                        ph.classList.add('hidden');
                    }
                }
                var html = [];
                items.forEach(function (item) {
                    var checked = selectedProfessionMap[item.id] ? ' checked' : '';
                    var orphanClass = item.orphan ? ' border-amber-400 dark:border-amber-600 bg-amber-50/80 dark:bg-amber-900/20' : '';
                    var title = item.orphan ? orphanHint : '';
                    html.push(
                        '<label class="partner-ec-prof-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer' + orphanClass + '" data-chip-label="' + esc(item.label) + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>' +
                        '<input type="checkbox" name="preferred_profession_ids[]" value="' + item.id + '" class="partner-ec-prof-cb rounded border-gray-300 dark:border-gray-600 text-indigo-600"' + checked + '>' +
                        '<span class="text-gray-800 dark:text-gray-100">' + esc(item.label) + '</span></label>'
                    );
                });
                inner.innerHTML = html.join('');
                inner.querySelectorAll('input.partner-ec-prof-cb').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var id = parseInt(cb.value, 10);
                        if (cb.checked) selectedProfessionMap[id] = true;
                        else delete selectedProfessionMap[id];
                    });
                });
                applyEcFilter('partner-ec-prof-chip', 'partner-ec-profession-filter');
            }

            function applyEcFilter(chipClass, filterId) {
                var q = ((document.getElementById(filterId) || {}).value || '').trim().toLowerCase();
                document.querySelectorAll('.' + chipClass).forEach(function (el) {
                    var lab = (el.getAttribute('data-chip-label') || '').toLowerCase();
                    el.style.display = !q || lab.indexOf(q) !== -1 ? '' : 'none';
                });
            }

            function renderProfessions() {
                pruneProfessionsAfterWwChange();
                paintProfessionChips(mergeProfessionItems());
            }

            function scheduleWwChange() {
                clearTimeout(wwDebounce);
                wwDebounce = setTimeout(renderProfessions, 150);
            }

            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('input.partner-ec-ww-cb').forEach(function (cb) {
                    cb.addEventListener('change', scheduleWwChange);
                });
                var ef = document.getElementById('partner-ec-education-filter');
                var wf = document.getElementById('partner-ec-ww-filter');
                var pf = document.getElementById('partner-ec-profession-filter');
                if (ef) ef.addEventListener('input', function () { applyEcFilter('partner-ec-edu-chip', 'partner-ec-education-filter'); });
                if (wf) wf.addEventListener('input', function () { applyEcFilter('partner-ec-ww-chip', 'partner-ec-ww-filter'); });
                if (pf) pf.addEventListener('input', function () { applyEcFilter('partner-ec-prof-chip', 'partner-ec-profession-filter'); });
                renderProfessions();
            });
        })();
    </script>
</div>
