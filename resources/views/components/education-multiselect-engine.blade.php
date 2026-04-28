@props([
    'profile',
    'namePrefix' => '',
    'formSelector' => null,
    'suffix' => null,
])
@php
    use App\Models\EducationDegree;
    use Illuminate\Support\Facades\Schema;

    $suffix = $suffix ?? substr(bin2hex(random_bytes(8)), 0, 12);
    $oldKey = fn (string $key): string => $namePrefix !== ''
        ? str_replace(']', '', str_replace('[', '.', $namePrefix.'['.$key.']'))
        : $key;

    $hasEducationEngine = Schema::hasColumn('matrimony_profiles', 'education_degree_id');
    if ($profile instanceof \App\Models\MatrimonyProfile) {
        $profile->loadMissing('educationDegree');
    }

    $educationDegreeId = old($oldKey('education_degree_id'), $hasEducationEngine ? ($profile->education_degree_id ?? null) : null);
    $educationManual = old($oldKey('education_text'), $hasEducationEngine ? ($profile->education_text ?? null) : null);

    $degreeChipTitle = '';
    $localeMr = app()->getLocale() === 'mr';
    if ($hasEducationEngine && $educationDegreeId) {
        if ($profile instanceof \App\Models\MatrimonyProfile && $profile->educationDegree) {
            $d = $profile->educationDegree;
            $label = ($localeMr && filled($d->title_mr)) ? $d->title_mr : ($d->title ?? '');
            $degreeChipTitle = (string) ($label !== '' ? $label : ($d->code ?? ''));
        } else {
            $_degRow = EducationDegree::query()->find((int) $educationDegreeId);
            if ($_degRow) {
                $label = ($localeMr && filled($_degRow->title_mr)) ? $_degRow->title_mr : ($_degRow->title ?? '');
                $degreeChipTitle = (string) ($label !== '' ? $label : ($_degRow->code ?? ''));
            }
        }
    }

    $initialEducationChips = [];
    if ($hasEducationEngine && $educationDegreeId) {
        $initialEducationChips[] = [
            'id' => (int) $educationDegreeId,
            'name' => $degreeChipTitle,
            'custom' => false,
        ];
    }
    if ($hasEducationEngine && $educationManual) {
        $parts = preg_split('/[,\\s]+/', trim((string) $educationManual), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            $initialEducationChips[] = [
                'id' => 'custom:'.rawurlencode($part),
                'name' => $part,
                'custom' => true,
            ];
        }
    }
    if ($hasEducationEngine && $initialEducationChips === []) {
        $legacyHe = trim((string) old($oldKey('highest_education'), $profile->highest_education ?? ''));
        if ($legacyHe !== '') {
            $parts = preg_split('/[,\\s]+/', $legacyHe, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $part) {
                $initialEducationChips[] = [
                    'id' => 'custom:'.rawurlencode($part),
                    'name' => $part,
                    'custom' => true,
                ];
            }
        }
    }

    $nameDegreeArr = $namePrefix !== '' ? $namePrefix.'[education_degree_ids][]' : 'education_degree_ids[]';
    $nameCustomArr = $namePrefix !== '' ? $namePrefix.'[education_custom][]' : 'education_custom[]';
    $nameSlots = $namePrefix !== '' ? $namePrefix.'[education_slots]' : 'education_slots';
@endphp

@if ($hasEducationEngine)
    @once
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.default.min.css" crossorigin="anonymous">
        <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js" crossorigin="anonymous"></script>
    @endonce

    <style>
        #education-ts-field-{{ $suffix }} .ts-control {
            border: 1px solid #ced4da;
            border-radius: 6px;
            padding: 6px 10px;
            min-height: 42px;
        }
        #education-ts-field-{{ $suffix }} .ts-control.focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        #education-ts-field-{{ $suffix }} .ts-control .item {
            margin: 3px;
            padding: 4px 8px;
            border-radius: 6px;
        }
    </style>

    <div id="education-multiselect-root-{{ $suffix }}">
        <div id="education-degree-ids-hidden-{{ $suffix }}" class="hidden" aria-hidden="true"></div>
        <div id="education-custom-hidden-{{ $suffix }}" class="hidden" aria-hidden="true"></div>

        <div class="space-y-1">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="education-ts-{{ $suffix }}">{{ __('Education') }}</label>
            <div id="education-ts-field-{{ $suffix }}" class="relative w-full">
                <select id="education-ts-{{ $suffix }}" multiple class="w-full" autocomplete="off" aria-describedby="education-multi-static-hint-{{ $suffix }} education-helper-custom-{{ $suffix }} education-did-you-mean-{{ $suffix }} education-invalid-hint-{{ $suffix }}"></select>
            </div>
            <p id="education-multi-static-hint-{{ $suffix }}" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('components.education.multiselect_intro') }}</p>
            <p id="education-helper-custom-{{ $suffix }}" class="hidden mt-1 text-xs text-green-600 dark:text-green-400 font-medium" aria-live="polite">{{ __('components.education.custom_added') }}</p>
            <div id="education-did-you-mean-{{ $suffix }}" class="hidden mt-1 text-xs text-amber-700 dark:text-amber-300" aria-live="polite"></div>
            <div id="education-invalid-hint-{{ $suffix }}" class="hidden mt-1 text-xs text-amber-800/90 dark:text-amber-200/90" aria-live="polite"></div>
        </div>

        @error($oldKey('highest_education'))<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error($oldKey('education_degree_id'))<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error($oldKey('education_text'))<p class="text-sm text-red-600">{{ $message }}</p>@enderror

        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">{{ __('onboarding.education_examples') }}</p>
    </div>

    <script>
    (function () {
        var suffix = @json($suffix);
        var degreeArrName = @json($nameDegreeArr);
        var customArrName = @json($nameCustomArr);
        var slotsFieldName = @json($nameSlots);
        var formSelector = @json($formSelector);
        var searchUrl = @json(route('api.education_degrees.search'));
        var didYouMeanTpl = @json(__('components.education.education_did_you_mean'));
        var invalidSoftMsg = @json(__('components.education.education_invalid_soft'));
        var noResultsEmptyMsg = @json(__('components.education.no_match_press_enter'));

        var degreeHiddenWrap = document.getElementById('education-degree-ids-hidden-' + suffix);
        var customHiddenWrap = document.getElementById('education-custom-hidden-' + suffix);
        var sel = document.getElementById('education-ts-' + suffix);
        if (!sel || typeof TomSelect === 'undefined') return;

        var initialChips = @json($initialEducationChips);

        var educationLastQuery = '';
        var educationLastSuggestion = null;
        var educationSearchAbort = null;

        function escapeHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function normalizeSearchToken(v) {
            return String(v || '').toLowerCase().replace(/[^a-z0-9]/gi, '');
        }

        function normalizeKeyDisplay(v) {
            return String(v || '').trim().toLowerCase();
        }

        function highlightMatch(text, query) {
            var raw = String(text == null ? '' : text);
            var q = normalizeSearchToken(query);
            if (!q) return escapeHtml(raw);
            var tNorm = normalizeSearchToken(raw);
            var idx = tNorm.indexOf(q);
            if (idx < 0) {
                var lit = String(query || '').trim();
                if (lit.length) {
                    var lower = raw.toLowerCase();
                    var li = lower.indexOf(lit.toLowerCase());
                    if (li >= 0) {
                        return escapeHtml(raw.slice(0, li)) + '<strong>' + escapeHtml(raw.slice(li, li + lit.length)) + '</strong>' + escapeHtml(raw.slice(li + lit.length));
                    }
                }
                return escapeHtml(raw);
            }
            var normPos = 0;
            var startChar = -1;
            var endChar = -1;
            for (var i = 0; i < raw.length; i++) {
                if (/[a-z0-9]/i.test(raw.charAt(i))) {
                    if (normPos === idx) startChar = i;
                    normPos++;
                    if (normPos === idx + q.length) {
                        endChar = i + 1;
                        break;
                    }
                }
            }
            if (startChar < 0) return escapeHtml(raw);
            if (endChar < 0) endChar = raw.length;
            return escapeHtml(raw.slice(0, startChar)) + '<strong>' + escapeHtml(raw.slice(startChar, endChar)) + '</strong>' + escapeHtml(raw.slice(endChar));
        }

        function splitEducationTokens(str) {
            return String(str || '').split(/[,\s]+/).map(function (s) { return s.trim(); }).filter(Boolean);
        }

        function getValueArray(ts) {
            var v = ts.getValue();
            if (v == null || v === '') return [];
            return Array.isArray(v) ? v : [v];
        }

        function hasDuplicateNormalized(ts, displayName) {
            var nk = normalizeKeyDisplay(displayName);
            if (!nk) return true;
            var vals = getValueArray(ts);
            for (var i = 0; i < vals.length; i++) {
                var vid = vals[i];
                var opt = ts.options[vid];
                if (opt && normalizeKeyDisplay(opt.name) === nk) return true;
            }
            return false;
        }

        function hasDuplicateDegreeId(ts, id) {
            var s = String(id);
            var vals = getValueArray(ts);
            return vals.map(String).indexOf(s) >= 0;
        }

        function syncArrayHiddens(ts) {
            if (!degreeHiddenWrap || !customHiddenWrap) return;
            degreeHiddenWrap.innerHTML = '';
            customHiddenWrap.innerHTML = '';
            var vals = getValueArray(ts);
            var ordered = [];
            vals.forEach(function (vid) {
                var key = String(vid);
                if (key.indexOf('custom:') === 0) {
                    try {
                        var t = decodeURIComponent(key.substring(7));
                        ordered.push({ t: 'c', x: t });
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = customArrName;
                        inp.value = t;
                        customHiddenWrap.appendChild(inp);
                    } catch (_e) {}
                } else if (key !== '' && key != null && !isNaN(Number(key))) {
                    var idNum = Number(key);
                    ordered.push({ t: 'd', id: idNum });
                    var inp2 = document.createElement('input');
                    inp2.type = 'hidden';
                    inp2.name = degreeArrName;
                    inp2.value = String(idNum);
                    degreeHiddenWrap.appendChild(inp2);
                }
            });
            var root = document.getElementById('education-multiselect-root-' + suffix);
            var slotsInp = document.getElementById('education-slots-input-' + suffix);
            if (!slotsInp && root) {
                slotsInp = document.createElement('input');
                slotsInp.type = 'hidden';
                slotsInp.id = 'education-slots-input-' + suffix;
                slotsInp.name = slotsFieldName;
                root.appendChild(slotsInp);
            }
            if (slotsInp) {
                slotsInp.value = JSON.stringify(ordered);
            }
        }

        function updateCustomHelper(ts) {
            var el = document.getElementById('education-helper-custom-' + suffix);
            if (!el) return;
            var vals = getValueArray(ts);
            var any = false;
            for (var i = 0; i < vals.length; i++) {
                if (String(vals[i]).indexOf('custom:') === 0) {
                    any = true;
                    break;
                }
            }
            if (any) el.classList.remove('hidden');
            else el.classList.add('hidden');
        }

        function hideEducationHints() {
            var dym = document.getElementById('education-did-you-mean-' + suffix);
            var inv = document.getElementById('education-invalid-hint-' + suffix);
            if (dym) {
                dym.classList.add('hidden');
                dym.textContent = '';
            }
            if (inv) {
                inv.classList.add('hidden');
                inv.textContent = '';
            }
        }

        function clearEducationTextbox(ts) {
            if (!ts) return;
            if (typeof ts.setTextboxValue === 'function') ts.setTextboxValue('');
            if (typeof ts.refreshOptions === 'function') ts.refreshOptions(false);
        }

        function updateEducationHints(query, results, suggestion) {
            var dym = document.getElementById('education-did-you-mean-' + suffix);
            var inv = document.getElementById('education-invalid-hint-' + suffix);
            if (!dym || !inv) return;
            var q = String(query || '').trim();
            dym.classList.add('hidden');
            inv.classList.add('hidden');
            dym.textContent = '';
            inv.textContent = '';
            educationLastSuggestion = suggestion || null;
            if (results && results.length > 0) return;
            if (suggestion) {
                dym.textContent = didYouMeanTpl.split('__NAME__').join(suggestion);
                dym.classList.remove('hidden');
                return;
            }
            if (q.length > 4) {
                inv.textContent = invalidSoftMsg;
                inv.classList.remove('hidden');
            }
        }

        function addCustomChip(ts, text) {
            var t = String(text || '').trim();
            if (t.length < 1) return;
            if (hasDuplicateNormalized(ts, t)) return;
            var enc = encodeURIComponent(t);
            var cid = 'custom:' + enc;
            if (!ts.options[cid]) {
                ts.addOption({ id: cid, name: t, custom: true });
            }
            ts.addItem(cid);
            clearEducationTextbox(ts);
        }

        function addDegreeChip(ts, row) {
            if (!row || row.id == null) return;
            if (hasDuplicateDegreeId(ts, row.id)) return;
            var idNum = Number(row.id);
            ts.addOption({
                id: idNum,
                name: row.name
            });
            ts.addItem(idNum);
            clearEducationTextbox(ts);
        }

        function fetchRowsForToken(token, cb) {
            var url = searchUrl + (searchUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(token);
            fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    var rows = Array.isArray(json) ? json : (json && json.results ? json.results : []);
                    cb(rows);
                })
                .catch(function () { cb([]); });
        }

        function resolveTokenAndAdd(ts, token, done) {
            token = String(token || '').trim();
            if (!token) {
                if (done) done();
                return;
            }
            if (hasDuplicateNormalized(ts, token)) {
                if (done) done();
                return;
            }
            fetchRowsForToken(token, function (rows) {
                var lower = token.toLowerCase();
                var exact = null;
                for (var i = 0; i < rows.length; i++) {
                    if (String(rows[i].name || '').trim().toLowerCase() === lower) {
                        exact = rows[i];
                        break;
                    }
                }
                if (exact && exact.id != null) {
                    addDegreeChip(ts, { id: exact.id, name: exact.name });
                } else {
                    addCustomChip(ts, token);
                }
                if (done) done();
            });
        }

        function resolveTokenChain(ts, tokens, index) {
            if (index >= tokens.length) return;
            resolveTokenAndAdd(ts, tokens[index], function () {
                resolveTokenChain(ts, tokens, index + 1);
            });
        }

        function processSplitInput(ts, raw) {
            var tokens = splitEducationTokens(raw);
            if (tokens.length === 0) return;
            resolveTokenChain(ts, tokens, 0);
        }

        var ts;
        ts = new TomSelect(sel, {
            plugins: ['remove_button'],
            persist: false,
            create: false,
            maxItems: null,
            placeholder: @json(__('components.education.placeholder_degrees')),
            valueField: 'id',
            labelField: 'name',
            searchField: [],
            maxOptions: 80,
            preload: false,
            shouldLoad: function (query) {
                return String(query || '').length >= 1;
            },
            score: function () {
                return function () { return 1; };
            },
            render: {
                option: function (data) {
                    return '<div class="px-2 py-1">' + highlightMatch(data.name, educationLastQuery) + '</div>';
                },
                no_results: function (data, esc) {
                    var q = String(data.input || '').trim();
                    if (q.length >= 2) {
                        var enc = encodeURIComponent(q);
                        var attrQ = esc(enc);
                        var disp = esc(q);
                        return '<div class="px-3 py-3 text-sm border-t border-gray-100 dark:border-gray-700">' +
                            '<div class="text-gray-500 dark:text-gray-400">' + esc(noResultsEmptyMsg) + '</div>' +
                            '<div class="mt-3 flex flex-wrap items-center gap-2">' +
                                '<button type="button" data-education-inline-add="' + suffix + '" data-q="' + attrQ + '" class="education-inline-add text-left text-sm text-emerald-700 dark:text-emerald-400 font-medium">' +
                                    'Add &quot;' + disp + '&quot;' +
                                '</button>' +
                                '<button type="button" data-education-inline-add="' + suffix + '" data-q="' + attrQ + '" class="education-inline-add shrink-0 px-3 py-1.5 rounded-lg border border-emerald-600 bg-emerald-50 dark:bg-emerald-900/40 text-emerald-900 dark:text-emerald-100 text-xs font-semibold">' +
                                    'Add' +
                                '</button>' +
                            '</div>' +
                            '</div>';
                    }
                    return '<div class="no-results px-3 py-2 text-sm text-gray-500">' + esc(noResultsEmptyMsg) + '</div>';
                }
            },
            load: function (query, callback) {
                var q = (query || '').trim();
                ts.clearOptions();
                educationLastQuery = q;
                if (educationSearchAbort) {
                    try { educationSearchAbort.abort(); } catch (_e) {}
                }
                educationSearchAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;
                var signal = educationSearchAbort ? educationSearchAbort.signal : undefined;
                var url = searchUrl + (searchUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
                fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: signal
                })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        var rows = [];
                        var suggestion = null;
                        if (Array.isArray(json)) {
                            rows = json;
                        } else if (json && Array.isArray(json.results)) {
                            rows = json.results;
                            suggestion = json.suggestion != null ? json.suggestion : null;
                        }
                        updateEducationHints(q, rows, suggestion);
                        var flat = rows.map(function (r) {
                            return { id: r.id, name: r.name };
                        });
                        callback(flat);
                    })
                    .catch(function (err) {
                        if (err && err.name === 'AbortError') {
                            callback();
                            return;
                        }
                        updateEducationHints(q, [], null);
                        callback();
                    });
            },
            onItemAdd: function () {
                this.setTextboxValue('');
                this.refreshOptions(false);
            },
            onChange: function () {
                syncArrayHiddens(ts);
                updateCustomHelper(ts);
                hideEducationHints();
            }
        });

        function bindInputSplitHandlers(ts) {
            var ci = ts.control_input;
            if (!ci) return;
            ci.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var raw = ci.value || '';
                    ci.value = '';
                    processSplitInput(ts, raw);
                    if (typeof ts.close === 'function') ts.close();
                }
            });
            ci.addEventListener('blur', function () {
                var raw = ci.value || '';
                if (!raw.trim()) return;
                ci.value = '';
                processSplitInput(ts, raw);
            });
        }

        initialChips.forEach(function (chip) {
            if (!chip || chip.id == null) return;
            if (chip.custom) {
                ts.addOption({ id: chip.id, name: chip.name, custom: true });
            } else {
                ts.addOption({
                    id: Number(chip.id),
                    name: chip.name
                });
            }
            ts.addItem(chip.custom ? chip.id : Number(chip.id), true);
        });
        syncArrayHiddens(ts);
        updateCustomHelper(ts);
        bindInputSplitHandlers(ts);

        document.addEventListener('click', function (e) {
            var t = e.target && e.target.closest('[data-education-inline-add="' + suffix + '"]');
            if (!t || !ts || !ts.dropdown_content || !ts.dropdown_content.contains(t)) return;
            e.preventDefault();
            e.stopPropagation();
            var enc = t.getAttribute('data-q');
            if (!enc) return;
            try {
                var txt = decodeURIComponent(enc);
                addCustomChip(ts, txt);
                if (typeof ts.close === 'function') ts.close();
            } catch (_err) {}
        }, true);

        var formEl = formSelector ? document.querySelector(formSelector) : sel.closest('form');
        if (formEl) {
            formEl.addEventListener('submit', function () { syncArrayHiddens(ts); });
        }
    })();
    </script>
@else
    <p class="text-sm text-amber-800 dark:text-amber-200">{{ __('onboarding.run_migrations_education') }}</p>
@endif
