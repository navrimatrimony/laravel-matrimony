{{--
  Shared Horoscope Dependency Engine. Works in wizard (namePrefix=horoscope) and intake preview (namePrefix=snapshot[horoscope]).
  Renders: nakshatra_id, charan, rashi_id, gan_id, nadi_id, yoni_id, mangal_dosh_type_id, devak, kul, gotra, navras_name, birth_weekday.
  Option 2: Save allowed with persistent mismatch warning. All fields editable. dependencyWarnings format: { field => { message, expected: [{id, label}] } }.
--}}
@props([
    'row' => [],
    'rashis' => [],
    'nakshatras' => [],
    'gans' => [],
    'nadis' => [],
    'yonis' => [],
    'mangalDoshTypes' => [],
    'horoscopeRulesJson' => ['rashi_rules' => [], 'nakshatra_attributes' => [], 'distinct_rashi_ids_by_nakshatra' => [], 'nakshatra_ids_by_rashi' => []],
    'rashiAshtakootaJson' => [],
    'namePrefix' => 'horoscope',
    'mode' => 'wizard',
    'dependencyExpected' => [],
    'dependencyWarnings' => [],
    'birthWeekdayExpected' => null,
])
@php
    $row = $row ?? [];
    $row = is_object($row) ? (array) $row : $row;
    $rashis = $rashis ?? collect();
    $nakshatras = $nakshatras ?? collect();
    $gans = $gans ?? collect();
    $nadis = $nadis ?? collect();
    $yonis = $yonis ?? collect();
    $mangalDoshTypes = $mangalDoshTypes ?? collect();
    $namePrefix = $namePrefix ?? 'horoscope';
    $dependencyWarnings = $dependencyWarnings ?? [];
    $n = fn($key) => $namePrefix ? $namePrefix . '[' . $key . ']' : $key;
    $oldKey = fn($key) => $namePrefix ? str_replace(']', '', str_replace('[', '.', $namePrefix . '[' . $key . ']')) : $key;
    $val = fn($key) => old($oldKey($key), $row[$key] ?? null);
    $cls = 'w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm';
    $labelCls = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
    $horoscopeRulesJson = $horoscopeRulesJson ?? ['rashi_rules' => [], 'nakshatra_attributes' => [], 'distinct_rashi_ids_by_nakshatra' => [], 'nakshatra_ids_by_rashi' => []];
    $rashiAshtakootaJson = $rashiAshtakootaJson ?? [];
    $filterOther = fn($c) => ($c->key ?? '') !== 'other';
    $yoniDisplayLabel = function($r) {
        $l = $r->label ?? $r->name ?? '';
        $l = preg_replace('/\s*\(Male\)\s*$/i', '', $l);
        $l = preg_replace('/\s*\(Female\)\s*$/i', '', $l);
        return trim($l);
    };
    $masterListsJson = [
        'rashis' => $rashis->filter($filterOther)->map(fn($r) => ['id' => (int)$r->id, 'label' => $r->label ?? $r->name ?? ''])->values()->all(),
        'nakshatras' => $nakshatras->filter($filterOther)->map(fn($r) => ['id' => (int)$r->id, 'label' => $r->label ?? $r->name ?? ''])->values()->all(),
        'gans' => $gans->filter($filterOther)->map(fn($r) => ['id' => (int)$r->id, 'label' => $r->label ?? $r->name ?? ''])->values()->all(),
        'nadis' => $nadis->filter($filterOther)->map(fn($r) => ['id' => (int)$r->id, 'label' => $r->label ?? $r->name ?? ''])->values()->all(),
        'yonis' => $yonis->filter($filterOther)->map(fn($r) => ['id' => (int)$r->id, 'label' => $yoniDisplayLabel($r)])->values()->all(),
    ];
    $warn = fn($field) => $dependencyWarnings[$field] ?? null;
    $birthWeekdayExpected = $birthWeekdayExpected ?? null;
    $birthWeekdayValue = $val('birth_weekday');
    $birthWeekdayMismatch = $birthWeekdayExpected && $birthWeekdayValue && $birthWeekdayValue !== $birthWeekdayExpected;
    $mangalNone = $mangalDoshTypes->firstWhere('key', 'none');
    $mangalYesDefault = $mangalDoshTypes->firstWhere('key', 'bhumangal') ?? $mangalDoshTypes->whereNotIn('key', ['none', 'other'])->first();
    $mangalNoneId = $mangalNone ? (int) $mangalNone->id : '';
    $mangalYesId = $mangalYesDefault ? (int) $mangalYesDefault->id : '';
    $mangalCurrent = $val('mangal_dosh_type_id');
    $isMangalYes = $mangalCurrent && $mangalCurrent !== '' && (string)$mangalCurrent !== (string)$mangalNoneId;
@endphp
<div class="horoscope-engine rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-4" data-dependency-warnings="{{ json_encode($dependencyWarnings) }}" data-mangal-none-id="{{ $mangalNoneId }}" data-mangal-yes-id="{{ $mangalYesId }}">
    @if(!empty($row['id']))
        <input type="hidden" name="{{ $n('id') }}" value="{{ $row['id'] }}">
    @endif

    {{-- Row 1: Nakshatra, Charan, Rashi — single line --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label for="horoscope-nakshatra_id" class="{{ $labelCls }}">{{ __('components.horoscope.nakshatra') }}</label>
            <select name="{{ $n('nakshatra_id') }}" id="horoscope-nakshatra_id" class="{{ $cls }}">
                <option value="">{{ __('common.select_placeholder') }}</option>
                @foreach($nakshatras as $item)
                    @if(($item->key ?? '') === 'other') @continue @endif
                    <option value="{{ $item->id }}" {{ (string)($val('nakshatra_id') ?? '') === (string)$item->id ? 'selected' : '' }}>{{ $item->label ?? $item->name ?? '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="horoscope-charan" class="{{ $labelCls }}">{{ __('components.horoscope.charan') }}</label>
            <select name="{{ $n('charan') }}" id="horoscope-charan" class="{{ $cls }}">
                <option value="">{{ __('common.select_placeholder') }}</option>
                @foreach([1, 2, 3, 4] as $c)
                    <option value="{{ $c }}" {{ (string)($val('charan') ?? '') === (string)$c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="horoscope-rashi_id" class="{{ $labelCls }}">{{ __('components.horoscope.rashi') }}</label>
            <select name="{{ $n('rashi_id') }}" id="horoscope-rashi_id" class="{{ $cls }} @if($warn('rashi_id')) border-red-600 dark:border-red-500 @endif" data-horoscope-field="rashi_id">
                <option value="">{{ __('common.select_placeholder') }}</option>
                @foreach($rashis as $item)
                    @if(($item->key ?? '') === 'other') @continue @endif
                    <option value="{{ $item->id }}" {{ (string)($val('rashi_id') ?? '') === (string)$item->id ? 'selected' : '' }}>{{ $item->label ?? $item->name ?? '' }}</option>
                @endforeach
            </select>
            @if($warn('rashi_id'))
                <div class="horoscope-field-warning mt-1">
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $warn('rashi_id')['message'] ?? '' }}</p>
                    @if(!empty($warn('rashi_id')['expected']))
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach($warn('rashi_id')['expected'] as $opt)
                                <button type="button" class="horoscope-hint-chip inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 hover:bg-red-200 dark:hover:bg-red-800/60 border border-red-300 dark:border-red-700" data-field="rashi_id" data-value="{{ $opt['id'] }}">{{ __('components.horoscope.correct_option') }} {{ $opt['label'] ?? $opt['id'] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Row 2: Gan, Nadi, Yoni — single line --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label for="horoscope-gan_id" class="{{ $labelCls }}">{{ __('components.horoscope.gan') }}</label>
            <select name="{{ $n('gan_id') }}" id="horoscope-gan_id" class="{{ $cls }} @if($warn('gan_id')) border-red-600 dark:border-red-500 @endif" data-horoscope-field="gan_id">
                <option value="">{{ __('common.select_placeholder') }}</option>
                @foreach($gans as $item)
                    @if(($item->key ?? '') === 'other') @continue @endif
                    <option value="{{ $item->id }}" {{ (string)($val('gan_id') ?? '') === (string)$item->id ? 'selected' : '' }}>{{ $item->label ?? $item->name ?? '' }}</option>
                @endforeach
            </select>
            @if($warn('gan_id'))
                <div class="horoscope-field-warning mt-1">
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $warn('gan_id')['message'] ?? '' }}</p>
                    @if(!empty($warn('gan_id')['expected']))
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach($warn('gan_id')['expected'] as $opt)
                                <button type="button" class="horoscope-hint-chip inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 hover:bg-red-200 dark:hover:bg-red-800/60 border border-red-300 dark:border-red-700" data-field="gan_id" data-value="{{ $opt['id'] }}">{{ __('components.horoscope.correct_option') }} {{ $opt['label'] ?? $opt['id'] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
        <div>
            <label for="horoscope-nadi_id" class="{{ $labelCls }}">{{ __('components.horoscope.nadi') }}</label>
            <select name="{{ $n('nadi_id') }}" id="horoscope-nadi_id" class="{{ $cls }} @if($warn('nadi_id')) border-red-600 dark:border-red-500 @endif" data-horoscope-field="nadi_id">
                <option value="">{{ __('common.select_placeholder') }}</option>
                @foreach($nadis as $item)
                    @if(($item->key ?? '') === 'other') @continue @endif
                    <option value="{{ $item->id }}" {{ (string)($val('nadi_id') ?? '') === (string)$item->id ? 'selected' : '' }}>{{ $item->label ?? $item->name ?? '' }}</option>
                @endforeach
            </select>
            @if($warn('nadi_id'))
                <div class="horoscope-field-warning mt-1">
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $warn('nadi_id')['message'] ?? '' }}</p>
                    @if(!empty($warn('nadi_id')['expected']))
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach($warn('nadi_id')['expected'] as $opt)
                                <button type="button" class="horoscope-hint-chip inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 hover:bg-red-200 dark:hover:bg-red-800/60 border border-red-300 dark:border-red-700" data-field="nadi_id" data-value="{{ $opt['id'] }}">{{ __('components.horoscope.correct_option') }} {{ $opt['label'] ?? $opt['id'] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
        <div>
            <label for="horoscope-yoni_id" class="{{ $labelCls }}">{{ __('components.horoscope.yoni') }}</label>
            <select name="{{ $n('yoni_id') }}" id="horoscope-yoni_id" class="{{ $cls }} @if($warn('yoni_id')) border-red-600 dark:border-red-500 @endif" data-horoscope-field="yoni_id">
                <option value="">{{ __('common.select_placeholder') }}</option>
                @foreach($yonis as $item)
                    @if(($item->key ?? '') === 'other') @continue @endif
                    <option value="{{ $item->id }}" {{ (string)($val('yoni_id') ?? '') === (string)$item->id ? 'selected' : '' }}>{{ $yoniDisplayLabel($item) }}</option>
                @endforeach
            </select>
            @if($warn('yoni_id'))
                <div class="horoscope-field-warning mt-1">
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $warn('yoni_id')['message'] ?? '' }}</p>
                    @if(!empty($warn('yoni_id')['expected']))
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach($warn('yoni_id')['expected'] as $opt)
                                <button type="button" class="horoscope-hint-chip inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 hover:bg-red-200 dark:hover:bg-red-800/60 border border-red-300 dark:border-red-700" data-field="yoni_id" data-value="{{ $opt['id'] }}">{{ __('components.horoscope.correct_option') }} {{ $opt['label'] ?? $opt['id'] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Row 3: Ashta-Koota (text) + Mangal Dosh toggle — single line --}}
    <div class="flex flex-wrap items-center gap-4 py-2">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('components.horoscope.varna') }} <span id="horoscope-ashtakoota-varna" class="font-medium">—</span></span>
            <span class="text-gray-400">|</span>
            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('components.horoscope.vashya') }} <span id="horoscope-ashtakoota-vashya" class="font-medium">—</span></span>
            <span class="text-gray-400">|</span>
            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('components.horoscope.rashi_lord') }} <span id="horoscope-ashtakoota-rashi-lord" class="font-medium">—</span></span>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('components.horoscope.mangal_dosh') }}</span>
            <input type="hidden" name="{{ $n('mangal_dosh_type_id') }}" id="horoscope-mangal_dosh_type_id" value="{{ $isMangalYes ? $mangalCurrent : ($mangalNoneId ?: '') }}">
            <div class="inline-flex rounded-lg border border-gray-300 dark:border-gray-600 overflow-hidden" role="group">
                <button type="button" class="horoscope-mangal-toggle px-4 py-2 text-sm font-medium border-r border-gray-300 dark:border-gray-600 {{ !$isMangalYes ? 'bg-green-600 text-white border-green-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-green-50 dark:hover:bg-gray-600' }}" data-mangal-value="{{ $mangalNoneId }}">{{ __('common.no') }}</button>
                <button type="button" class="horoscope-mangal-toggle px-4 py-2 text-sm font-medium {{ $isMangalYes ? 'bg-red-600 text-white border-red-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-red-50 dark:hover:bg-gray-600' }}" data-mangal-value="{{ $mangalYesId }}">{{ __('common.yes') }}</button>
            </div>
        </div>
    </div>

    {{-- Row 4: Navras name + Devak --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="horoscope-navras_name" class="{{ $labelCls }}">{{ __('components.horoscope.navras_name') }}</label>
            <input
                type="text"
                name="{{ $n('navras_name') }}"
                id="horoscope-navras_name"
                class="{{ $cls }}"
                value="{{ $val('navras_name') }}"
                autocomplete="off"
            >
        </div>
        <div>
            <label for="horoscope-devak" class="{{ $labelCls }}">{{ __('components.horoscope.devak') }}</label>
            <input
                type="text"
                name="{{ $n('devak') }}"
                id="horoscope-devak"
                class="{{ $cls }}"
                value="{{ $val('devak') }}"
                autocomplete="off"
            >
        </div>
    </div>

    {{-- Row 5: Kul + Gotra --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="horoscope-kul" class="{{ $labelCls }}">{{ __('components.horoscope.kul') }}</label>
            <input
                type="text"
                name="{{ $n('kul') }}"
                id="horoscope-kul"
                class="{{ $cls }}"
                value="{{ $val('kul') }}"
                autocomplete="off"
            >
        </div>
        <div>
            <label for="horoscope-gotra" class="{{ $labelCls }}">{{ __('components.horoscope.gotra') }}</label>
            <input
                type="text"
                name="{{ $n('gotra') }}"
                id="horoscope-gotra"
                class="{{ $cls }}"
                value="{{ $val('gotra') }}"
                autocomplete="off"
            >
        </div>
    </div>

    {{-- Row 6: Janma-waar (day of week from DOB, editable with mismatch warning) --}}
    <div class="mt-2">
        <label for="horoscope-birth_weekday" class="{{ $labelCls }}">{{ __('components.horoscope.birth_weekday') }}</label>
        <select
            name="{{ $n('birth_weekday') }}"
            id="horoscope-birth_weekday"
            class="{{ $cls }} @if($birthWeekdayMismatch) border-red-600 dark:border-red-500 @endif"
        >
            <option value="">{{ __('common.select_placeholder') }}</option>
            @php
                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            @endphp
            @foreach($days as $day)
                <option value="{{ $day }}" {{ (string)($birthWeekdayValue ?? '') === $day ? 'selected' : '' }}>
                    {{ __('components.horoscope.weekdays.' . strtolower($day)) }}
                </option>
            @endforeach
        </select>
        @if($birthWeekdayMismatch)
            <p class="mt-1 text-sm text-red-600 dark:text-red-400">
                {{ __('components.horoscope.birth_weekday_mismatch', ['selected' => __('components.horoscope.weekdays.' . strtolower($birthWeekdayValue)), 'expected' => __('components.horoscope.weekdays.' . strtolower($birthWeekdayExpected))]) }}
            </p>
        @endif
    </div>

    <script>
    (function() {
        var scriptEl = document.currentScript;
        var rules = @json($horoscopeRulesJson);
        var masterLists = @json($masterListsJson);
        var rashiAshtakoota = @json($rashiAshtakootaJson);
        var namePrefix = @json($namePrefix);

        function run() {
            var wrapper = (scriptEl && scriptEl.closest('.horoscope-engine')) || document.querySelector('.horoscope-engine');
            if (!wrapper) return;
            var rashiRules = rules.rashi_rules || [];
            var nakshatraAttrs = rules.nakshatra_attributes || [];
            var distinctRashiByNakshatra = rules.distinct_rashi_ids_by_nakshatra || {};
            var nakshatraIdsByRashi = rules.nakshatra_ids_by_rashi || {};

            function sel(name) {
                var expectedName = namePrefix + '[' + name + ']';
                var selects = wrapper.querySelectorAll('select');
                for (var i = 0; i < selects.length; i++) {
                    if (selects[i].getAttribute('name') === expectedName) return selects[i];
                }
                return null;
            }
            var nakshatraSelect = sel('nakshatra_id');
            var charanSelect = sel('charan');
            var rashiSelect = sel('rashi_id');
            var ganSelect = sel('gan_id');
            var nadiSelect = sel('nadi_id');
            var yoniSelect = sel('yoni_id');
            if (!nakshatraSelect) return;

            function setSelectOptions(select, allowedIds, list, emptyLabel) {
                if (!select || !list) return;
                emptyLabel = emptyLabel || @json(__('common.select_placeholder'));
                var hasEmpty = allowedIds === null || (Array.isArray(allowedIds) && allowedIds.indexOf('') === -1);
                var opts = hasEmpty ? ['<option value="">' + emptyLabel + '</option>'] : [];
                var norm = function(id) { return typeof id === 'string' ? parseInt(id, 10) : id; };
                for (var i = 0; i < list.length; i++) {
                    var id = list[i].id;
                    var idNum = norm(id);
                    if (allowedIds !== null) {
                        var allowed = allowedIds.map(norm);
                        if (allowed.indexOf(idNum) === -1 && allowed.indexOf(id) === -1) continue;
                    }
                    opts.push('<option value="' + id + '">' + (list[i].label || '').replace(/</g, '&lt;') + '</option>');
                }
                var cur = select.value;
                select.innerHTML = opts.join('');
                if (cur) {
                    if (allowedIds === null) select.value = cur;
                    else { var allowed = allowedIds.map(norm); if (allowed.indexOf(parseInt(cur, 10)) !== -1 || allowed.indexOf(cur) !== -1) select.value = cur; }
                }
            }

            function applyDependency() {
                var nakshatraId = nakshatraSelect.value ? parseInt(nakshatraSelect.value, 10) : null;
                var charanVal = charanSelect && charanSelect.value ? parseInt(charanSelect.value, 10) : null;
                var rashiId = rashiSelect && rashiSelect.value ? parseInt(rashiSelect.value, 10) : null;

                if (nakshatraId) {
                    var attrs = nakshatraAttrs.filter(function(a) { return a.nakshatra_id === nakshatraId; })[0];
                    if (attrs) {
                        if (ganSelect && !ganSelect.value && attrs.gan_id != null) { ganSelect.value = String(attrs.gan_id); }
                        if (nadiSelect && !nadiSelect.value && attrs.nadi_id != null) { nadiSelect.value = String(attrs.nadi_id); }
                        if (yoniSelect && attrs.yoni_id != null) {
                            setSelectOptions(yoniSelect, [attrs.yoni_id], masterLists.yonis);
                            if (!yoniSelect.value) { yoniSelect.value = String(attrs.yoni_id); }
                        }
                    }
                    var allowedRashiIds = distinctRashiByNakshatra[String(nakshatraId)];
                    if (charanVal >= 1 && charanVal <= 4) {
                        var rule = rashiRules.filter(function(r) { return r.nakshatra_id === nakshatraId && r.charan === charanVal; })[0];
                        if (rule && rashiSelect && !rashiSelect.value) rashiSelect.value = String(rule.rashi_id);
                    }
                    if (rashiSelect) {
                        setSelectOptions(rashiSelect, allowedRashiIds && allowedRashiIds.length ? allowedRashiIds : null, masterLists.rashis);
                        if (allowedRashiIds && allowedRashiIds.length && rashiSelect.value && allowedRashiIds.indexOf(parseInt(rashiSelect.value, 10)) === -1) rashiSelect.value = String(allowedRashiIds[0]);
                    }
                    if (charanSelect) {
                        setSelectOptions(charanSelect, [1,2,3,4], [{id:1,label:'1'},{id:2,label:'2'},{id:3,label:'3'},{id:4,label:'4'}]);
                        if (rashiId && nakshatraId) {
                            var validCharans = [];
                            for (var i = 0; i < rashiRules.length; i++) {
                                if (rashiRules[i].nakshatra_id === nakshatraId && rashiRules[i].rashi_id === rashiId) validCharans.push(rashiRules[i].charan);
                            }
                            validCharans.sort();
                            if (validCharans.length) { setSelectOptions(charanSelect, validCharans, [{id:1,label:'1'},{id:2,label:'2'},{id:3,label:'3'},{id:4,label:'4'}]); if (charanSelect.value && validCharans.indexOf(parseInt(charanSelect.value, 10)) === -1) charanSelect.value = String(validCharans[0]); }
                        }
                    }
                } else {
                    if (ganSelect) { ganSelect.value = ''; setSelectOptions(ganSelect, null, masterLists.gans); }
                    if (nadiSelect) { nadiSelect.value = ''; setSelectOptions(nadiSelect, null, masterLists.nadis); }
                    if (yoniSelect) { yoniSelect.value = ''; setSelectOptions(yoniSelect, null, masterLists.yonis); }
                    if (rashiSelect) { rashiSelect.value = ''; setSelectOptions(rashiSelect, null, masterLists.rashis); }
                    if (charanSelect) setSelectOptions(charanSelect, [1,2,3,4], [{id:1,label:'1'},{id:2,label:'2'},{id:3,label:'3'},{id:4,label:'4'}]);
                }

                if (rashiId && rashiSelect && rashiSelect.value) {
                    var allowedNakshatraIds = nakshatraIdsByRashi[String(rashiId)] || [];
                    setSelectOptions(nakshatraSelect, allowedNakshatraIds.length ? allowedNakshatraIds : null, masterLists.nakshatras);
                    if (nakshatraSelect.value && allowedNakshatraIds.length && allowedNakshatraIds.indexOf(parseInt(nakshatraSelect.value, 10)) === -1) {
                        nakshatraSelect.value = ''; if (charanSelect) charanSelect.value = ''; if (ganSelect) ganSelect.value = ''; if (nadiSelect) nadiSelect.value = ''; if (yoniSelect) yoniSelect.value = ''; applyDependency(); return;
                    }
                } else if (!rashiId && nakshatraSelect) setSelectOptions(nakshatraSelect, null, masterLists.nakshatras);

                var rashiIdCur = rashiSelect && rashiSelect.value ? rashiSelect.value : null;
                var varnaEl = wrapper.querySelector('#horoscope-ashtakoota-varna');
                var vashyaEl = wrapper.querySelector('#horoscope-ashtakoota-vashya');
                var lordEl = wrapper.querySelector('#horoscope-ashtakoota-rashi-lord');
                if (rashiAshtakoota && varnaEl && vashyaEl && lordEl) {
                    var a = rashiIdCur ? rashiAshtakoota[rashiIdCur] : null;
                    varnaEl.textContent = a ? a.varna : '—';
                    vashyaEl.textContent = a ? a.vashya : '—';
                    lordEl.textContent = a ? a.rashi_lord : '—';
                }
            }

            wrapper.querySelectorAll('.horoscope-hint-chip').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var field = this.getAttribute('data-field');
                    var value = this.getAttribute('data-value');
                    var s = sel(field);
                    if (s) {
                        s.value = value;
                        s.classList.remove('border-red-600', 'dark:border-red-500');
                        s.classList.add('border-gray-300', 'dark:border-gray-600');
                        var cell = s.closest('div');
                        if (cell) {
                            var warnBlock = cell.querySelector('.horoscope-field-warning');
                            if (warnBlock) warnBlock.remove();
                        }
                        applyDependency();
                    }
                });
            });

            nakshatraSelect.addEventListener('change', function() { applyDependency(); });
            if (charanSelect) charanSelect.addEventListener('change', function() { applyDependency(); });
            if (rashiSelect) rashiSelect.addEventListener('change', function() { applyDependency(); });

            var mangalHidden = document.getElementById('horoscope-mangal_dosh_type_id');
            var mangalToggles = wrapper.querySelectorAll('.horoscope-mangal-toggle');
            var mangalNoneId = wrapper.getAttribute('data-mangal-none-id') || '';
            var mangalYesId = wrapper.getAttribute('data-mangal-yes-id') || '';
            function updateMangalToggleStyle() {
                if (!mangalHidden || !mangalToggles.length) return;
                var v = (mangalHidden.value || '').toString();
                var isYes = v !== '' && v !== mangalNoneId;
                mangalToggles.forEach(function(btn) {
                    var val = btn.getAttribute('data-mangal-value');
                    var isNo = (val === mangalNoneId || val === '');
                    if (isNo) {
                        btn.classList.remove('bg-red-600', 'border-red-600', 'text-white');
                        btn.classList.add('border-r', 'border-gray-300', 'dark:border-gray-600');
                        if (!isYes) { btn.classList.add('bg-green-600', 'text-white'); btn.classList.remove('bg-white', 'dark:bg-gray-700', 'text-gray-700', 'dark:text-gray-300'); }
                        else { btn.classList.add('bg-white', 'dark:bg-gray-700', 'text-gray-700', 'dark:text-gray-300'); btn.classList.remove('bg-green-600', 'text-white'); }
                    } else {
                        btn.classList.remove('bg-green-600', 'text-white', 'border-r', 'border-gray-300', 'dark:border-gray-600');
                        if (isYes) { btn.classList.add('bg-red-600', 'text-white'); btn.classList.remove('bg-white', 'dark:bg-gray-700', 'text-gray-700', 'dark:text-gray-300'); }
                        else { btn.classList.add('bg-white', 'dark:bg-gray-700', 'text-gray-700', 'dark:text-gray-300'); btn.classList.remove('bg-red-600', 'text-white'); }
                    }
                });
            }
            mangalToggles.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var val = this.getAttribute('data-mangal-value');
                    if (!mangalHidden) return;
                    if (val === mangalNoneId || val === '') {
                        mangalHidden.value = val || '';
                    } else {
                        if (mangalHidden.value === '' || mangalHidden.value === mangalNoneId) mangalHidden.value = mangalYesId || val;
                        else mangalHidden.value = mangalHidden.value;
                    }
                    updateMangalToggleStyle();
                });
            });
            updateMangalToggleStyle();

            applyDependency();
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
        else run();
    })();
    </script>
</div>
