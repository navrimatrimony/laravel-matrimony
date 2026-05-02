@props([
    'label' => 'Income',
    'namePrefix' => 'income',
    'values' => [],
    'profile' => null,
    'currencies' => [],
    'privacyEnabled' => true,
    'periodOptions' => null,
    'valueTypeOptions' => null,
    'emptyValueTypeDefault' => null,
    'disabled' => false,
    'helpText' => null,
    'readOnly' => false,
    'errors' => [],
])
@php
    use App\Models\MasterIncomeCurrency;

    $currencies = !empty($currencies) ? $currencies : MasterIncomeCurrency::where('is_active', true)->get();
    $profile = $profile ?? new \stdClass();
    $prefix = $namePrefix ?: 'income';
    $periodOptions = $periodOptions ?? [
        ['value' => 'annual', 'label' => 'Annual'],
        ['value' => 'monthly', 'label' => 'Monthly'],
    ];
    $valueTypeOptions = $valueTypeOptions ?? [
        ['value' => 'exact', 'label' => 'Exact'],
        ['value' => 'approximate', 'label' => 'Approx.'],
        ['value' => 'range', 'label' => 'Range'],
        ['value' => 'undisclosed', 'label' => 'Prefer not to say'],
    ];
    $errorsArray = is_array($errors) ? $errors : [];
    if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
        $bag = $errors->getBag('default');
        foreach ($bag->getMessages() as $k => $msgs) {
            $errorsArray[$k] = is_array($msgs) ? ($msgs[0] ?? null) : $msgs;
        }
    }
    $key = fn($suffix) => $prefix . '_' . $suffix;
    $n = fn($suffix) => $key($suffix);
    $oldKey = fn($suffix) => $n($suffix);
    $v = function($suffix) use ($profile, $values, $key, $prefix) {
        $k = $key($suffix);
        if (is_array($values) && array_key_exists($k, $values)) {
            return $values[$k];
        }
        $val = $profile->$k ?? null;
        if ($val === null && $suffix === 'amount' && $prefix === 'income') {
            $val = $profile->annual_income ?? null;
        }
        if ($val === null && $suffix === 'amount' && $prefix === 'family_income') {
            $val = $profile->family_income ?? null;
        }
        return $val;
    };
    $err = fn($suffix) => $errorsArray[$n($suffix)] ?? $errorsArray[$key($suffix)] ?? null;

    $periodRaw = old($oldKey('period'), $v('period')) ?: 'annual';
    $valueTypeRaw = old($oldKey('value_type'), $v('value_type'));
    if ($valueTypeRaw === null || $valueTypeRaw === '') {
        $whenEmptyType = $emptyValueTypeDefault ?? 'undisclosed';
        $valueTypeRaw = ($v('amount') !== null || $v('min_amount') !== null || ($prefix === 'income' ? ($profile->annual_income ?? null) : ($profile->family_income ?? null)) !== null) ? 'exact' : $whenEmptyType;
    }
    $currencyIdRaw = old($oldKey('currency_id'), $v('currency_id'));
    $privateRaw = old($oldKey('private'), $v('private'));
    if ($prefix === 'family_income' && ($currencyIdRaw === null || $currencyIdRaw === '')) {
        $currencyIdRaw = $profile->income_currency_id ?? null;
    }
    $defaultCurrencyId = $currencyIdRaw ?: (collect($currencies)->firstWhere('is_default', true)?->id ?? $currencies->first()?->id);
    $selectedCurrency = collect($currencies)->firstWhere('id', $defaultCurrencyId);
    $currencyDisplayLabel = '—';
    if ($selectedCurrency) {
        $sym = $selectedCurrency->displaySymbol();
        $currencyDisplayLabel = trim($sym.' '.($selectedCurrency->code ?? ''));
    }

    $amountDisplay = function($val) {
        if ($val === null || $val === '') return '';
        $v = is_numeric($val) ? (float) $val : null;
        if ($v === null) return $val;
        return (floor($v) == $v) ? (string)(int)$v : (string)$v;
    };
    $indianFormat = function($val) {
        if ($val === null || $val === '') return '';
        $n = (string)(int) round((float) $val);
        $len = strlen($n);
        if ($len <= 3) return $n;
        $last3 = substr($n, -3);
        $rest = substr($n, 0, -3);
        $rest = strrev(implode(',', str_split(strrev($rest), 2)));
        return $rest . ',' . $last3;
    };
    $amountVal = old($oldKey('amount'), $v('amount'));
    $minAmountVal = old($oldKey('min_amount'), $v('min_amount'));
    $maxAmountVal = old($oldKey('max_amount'), $v('max_amount'));
    $amountDisplayFormatted = $amountDisplay($amountVal) !== '' ? $indianFormat($amountVal) : '';
    $minAmountDisplayFormatted = $amountDisplay($minAmountVal) !== '' ? $indianFormat($minAmountVal) : '';
    $maxAmountDisplayFormatted = $amountDisplay($maxAmountVal) !== '' ? $indianFormat($maxAmountVal) : '';

    $chipCls = 'rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/60 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 focus:ring-2 focus:ring-indigo-500/35 focus:border-indigo-400 outline-none min-w-0 cursor-pointer';
    $amountCls = 'rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-indigo-500/35 focus:border-indigo-400 outline-none transition-shadow';
    // Invisible overlay select: visible label is plain green text (no pill); avoids native <select> width quirks.
    $currencySelectOverlayCls = 'income-currency-select absolute inset-0 z-10 h-full min-h-[2.25rem] w-full cursor-pointer opacity-0 appearance-none border-0 bg-transparent p-0 outline-none focus:ring-0 disabled:cursor-not-allowed disabled:opacity-50';
    $currencyReadonlyCls = 'inline-flex shrink-0 items-center justify-end whitespace-nowrap text-right text-sm font-semibold text-emerald-600 dark:text-emerald-400';
    $privacyLabelText = $prefix === 'income' ? 'Keep my income private' : 'Keep family income private';
@endphp
<div class="income-engine w-full rounded-2xl border border-gray-200/80 dark:border-gray-600/80 bg-white dark:bg-gray-800/60 shadow-md shadow-gray-200/50 dark:shadow-none overflow-hidden">
    <div class="px-4 pt-4 pb-1">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $label }}</h3>
    </div>
    <div class="p-4 pt-2">
        {{-- md+: one horizontal row. max-md: 2×2 — period | value type; amount row | currency --}}
        <div class="group income-engine-controls-row w-full gap-2 max-md:grid max-md:grid-cols-2 max-md:items-start max-md:gap-2 md:flex md:flex-wrap md:items-center md:gap-3 {{ $valueTypeRaw === 'range' ? 'is-range' : '' }}">
            {{-- Period: same width as value type (income-control-col). Desktop widths unchanged from original (md+). --}}
            <div class="income-control-col w-full min-w-0 shrink-0 transition-[width,max-width] duration-200 md:w-36 lg:w-40 group-[.is-range]:md:w-[6.75rem] group-[.is-range]:md:max-w-[6.75rem] lg:group-[.is-range]:md:w-28 lg:group-[.is-range]:md:max-w-[7rem]">
                @if(!$readOnly)
                    <select name="{{ $n('period') }}" class="{{ $chipCls }} w-full min-w-0 max-w-full" {{ $disabled ? 'disabled' : '' }} aria-label="Period">
                        @foreach($periodOptions as $opt)
                            <option value="{{ $opt['value'] }}" {{ (string)$periodRaw === (string)$opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="inline-flex w-full min-w-0 max-w-full items-center truncate rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/60 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200">{{ collect($periodOptions)->firstWhere('value', $periodRaw)['label'] ?? $periodRaw ?? '—' }}</span>
                @endif
                @if($err('period'))<p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $err('period') }}</p>@endif
            </div>

            {{-- Value type: same width as period --}}
            <div class="income-control-col w-full min-w-0 shrink-0 transition-[width,max-width] duration-200 md:w-36 lg:w-40 group-[.is-range]:md:w-[6.75rem] group-[.is-range]:md:max-w-[6.75rem] lg:group-[.is-range]:md:w-28 lg:group-[.is-range]:md:max-w-[7rem]">
                @if(!$readOnly)
                    <select name="{{ $n('value_type') }}" class="{{ $chipCls }} income-value-type-select w-full min-w-0 max-w-full" data-engine="{{ $prefix }}" {{ $disabled ? 'disabled' : '' }} aria-label="Value type">
                        @foreach($valueTypeOptions as $opt)
                            <option value="{{ $opt['value'] }}" {{ (string)$valueTypeRaw === (string)$opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="inline-flex w-full min-w-0 max-w-full items-center truncate rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/60 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200">{{ collect($valueTypeOptions)->firstWhere('value', $valueTypeRaw)['label'] ?? $valueTypeRaw ?? '—' }}</span>
                @endif
                @if($err('value_type'))<p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $err('value_type') }}</p>@endif
            </div>

            {{-- Amount + currency: md+ single flex strip; max-md unwrap for 2×2 grid (amount | currency) --}}
            <div class="flex min-w-0 flex-[2] flex-wrap items-center gap-2 max-md:contents md:flex md:min-w-0">
                <div class="income-amount-area flex min-w-0 flex-1 flex-wrap items-center gap-2 max-md:min-w-0" data-engine="{{ $prefix }}">
                    <div class="income-amount-single min-w-0 flex-1 basis-[8rem] transition-opacity duration-200" data-show="exact,approximate" style="{{ in_array($valueTypeRaw, ['exact','approximate'], true) ? '' : 'display:none!important' }}">
                        <input type="text" inputmode="numeric" name="{{ $n('amount') }}" value="{{ $amountDisplayFormatted }}" placeholder="Amount" class="{{ $amountCls }} w-full min-w-0 income-amount-indian" data-raw="{{ $amountDisplay($amountVal) }}" {{ $disabled ? 'disabled' : '' }} aria-label="Amount" autocomplete="off">
                    </div>
                    {{-- Compact row vs period/value; dash minimal gap; inputs capped but min-w for Indian-format digits --}}
                    <div class="income-amount-range flex min-w-0 max-w-[min(100%,17.5rem)] flex-1 basis-[8rem] items-center gap-1 transition-opacity duration-200 sm:max-w-[18.5rem]" data-show="range" style="{{ $valueTypeRaw === 'range' ? '' : 'display:none!important' }}">
                        <input type="text" inputmode="numeric" name="{{ $n('min_amount') }}" value="{{ $minAmountDisplayFormatted }}" placeholder="50,000" class="{{ $amountCls }} income-amount-indian min-w-[5.25rem] max-w-[8.5rem] flex-1" data-raw="{{ $amountDisplay($minAmountVal) }}" {{ $disabled ? 'disabled' : '' }} aria-label="Minimum amount" autocomplete="off">
                        <span class="shrink-0 select-none px-0 text-xs font-medium leading-none text-gray-400 dark:text-gray-500" aria-hidden="true">—</span>
                        <input type="text" inputmode="numeric" name="{{ $n('max_amount') }}" value="{{ $maxAmountDisplayFormatted }}" placeholder="75,000" class="{{ $amountCls }} income-amount-indian min-w-[5.25rem] max-w-[8.5rem] flex-1" data-raw="{{ $amountDisplay($maxAmountVal) }}" {{ $disabled ? 'disabled' : '' }} aria-label="Maximum amount" autocomplete="off">
                    </div>
                    <div class="income-amount-undisclosed flex-shrink-0 transition-opacity duration-200" data-show="undisclosed" style="{{ $valueTypeRaw === 'undisclosed' ? '' : 'display:none!important' }}">
                        <span class="text-sm italic text-gray-400 dark:text-gray-500">No amount</span>
                    </div>
                </div>
                {{-- Currency: green text + icon only; invisible overlay <select> for native picker (no wide pill). --}}
                <div class="group relative inline-flex max-w-full min-w-0 flex-shrink-0 items-center justify-end self-center rounded-sm py-0.5 focus-within:ring-2 focus-within:ring-emerald-500/45 focus-within:ring-offset-1 dark:focus-within:ring-offset-gray-800 max-md:w-full max-md:justify-self-end md:ml-auto">
                    @if(!$readOnly)
                        <select name="{{ $n('currency_id') }}" class="{{ $currencySelectOverlayCls }}" {{ $disabled ? 'disabled' : '' }} aria-label="Currency" title="Change currency">
                            @foreach($currencies as $c)
                                @php $sym = $c->displaySymbol(); @endphp
                                <option value="{{ $c->id }}" {{ (string)$defaultCurrencyId === (string)$c->id ? 'selected' : '' }}>{{ $sym }} {{ $c->code }}</option>
                            @endforeach
                        </select>
                        <span class="income-currency-label pointer-events-none whitespace-nowrap text-right text-sm font-semibold text-emerald-600 dark:text-emerald-400" aria-hidden="true">{{ $currencyDisplayLabel }}</span>
                    @else
                        <span class="{{ $currencyReadonlyCls }}">{{ $currencyDisplayLabel }}</span>
                    @endif
                    @if($err('currency_id'))<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $err('currency_id') }}</p>@endif
                </div>
            </div>
        </div>
            @if($err('amount'))<p class="text-red-600 dark:text-red-400 text-xs mt-1 w-full">{{ $err('amount') }}</p>@endif
            @if($err('min_amount'))<p class="text-red-600 dark:text-red-400 text-xs mt-1 w-full">{{ $err('min_amount') }}</p>@endif
            @if($err('max_amount'))<p class="text-red-600 dark:text-red-400 text-xs mt-1 w-full">{{ $err('max_amount') }}</p>@endif

        {{-- Privacy: खालच्या ओळीत --}}
        @if($privacyEnabled)
        <div class="flex items-center gap-2 mt-3 w-full">
            @if(!$readOnly)
                <input type="hidden" name="{{ $n('private') }}" value="0">
                <input type="checkbox" name="{{ $n('private') }}" value="1" id="{{ $prefix }}_private_cb" {{ $privateRaw ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500" {{ $disabled ? 'disabled' : '' }}>
                <label for="{{ $prefix }}_private_cb" class="text-sm text-gray-600 dark:text-gray-400 cursor-pointer select-none inline-flex items-center gap-1.5"><span aria-hidden="true">🔒</span>{{ $privacyLabelText }}</label>
            @else
                <span class="text-sm text-gray-600 dark:text-gray-400 inline-flex items-center gap-1.5"><span aria-hidden="true">🔒</span>{{ $privateRaw ? $privacyLabelText : 'Visible' }}</span>
            @endif
        </div>
        @endif

        @if($helpText)
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">{{ $helpText }}</p>
        @endif
    </div>
</div>

@if(!$readOnly)
<script>
(function() {
    var script = document.currentScript;
    var block = script.previousElementSibling;
    if (!block || !block.classList.contains('income-engine')) return;
    var valueTypeSelect = block.querySelector('.income-value-type-select');
    var amountArea = block.querySelector('.income-amount-area');
    if (!valueTypeSelect || !amountArea) return;
    function toggle() {
        var vt = (valueTypeSelect.value || '').trim().toLowerCase();
        amountArea.querySelectorAll('[data-show]').forEach(function(el) {
            var showList = (el.getAttribute('data-show') || '').toLowerCase().split(',').map(function(s) { return s.trim(); });
            var show = showList.indexOf(vt) !== -1;
            if (show) {
                el.style.removeProperty('display');
            } else {
                el.style.setProperty('display', 'none', 'important');
            }
        });
    }
    var controlsRow = block.querySelector('.income-engine-controls-row');
    function setRangeMode() {
        if (!controlsRow) return;
        var vt = (valueTypeSelect.value || '').trim().toLowerCase();
        controlsRow.classList.toggle('is-range', vt === 'range');
    }
    function applyValueType() {
        toggle();
        setRangeMode();
    }
    valueTypeSelect.addEventListener('change', applyValueType);
    toggle();
    setRangeMode();

    var currencySelect = block.querySelector('select.income-currency-select');
    var currencyLabel = block.querySelector('.income-currency-label');
    if (currencySelect && currencyLabel) {
        currencySelect.addEventListener('change', function() {
            var opt = currencySelect.options[currencySelect.selectedIndex];
            currencyLabel.textContent = (opt && opt.text ? opt.text : '').trim();
        });
    }

    function indianFormat(numStr) {
        var n = String(numStr).replace(/\D/g, '');
        if (n.length === 0) return '';
        if (n.length <= 3) return n;
        var last3 = n.slice(-3);
        var rest = n.slice(0, -3);
        return rest.replace(/\B(?=(?:\d{2})*$)/g, ',') + ',' + last3;
    }
    function stripCommas(str) { return String(str).replace(/,/g, ''); }
    block.querySelectorAll('.income-amount-indian').forEach(function(inp) {
        inp.addEventListener('input', function() {
            var raw = this.value.replace(/\D/g, '');
            this.value = raw;
            this.setAttribute('data-raw', raw);
        });
        inp.addEventListener('blur', function() {
            var raw = this.value.replace(/\D/g, '');
            if (raw === '') { this.value = ''; this.setAttribute('data-raw', ''); return; }
            this.value = indianFormat(raw);
            this.setAttribute('data-raw', raw);
        });
        inp.addEventListener('focus', function() {
            var raw = this.getAttribute('data-raw') || this.value.replace(/\D/g, '');
            if (raw !== '') this.value = raw;
        });
    });
    var form = block.closest('form');
    if (form && !form.hasAttribute('data-income-indian-submit')) {
        form.setAttribute('data-income-indian-submit', '1');
        form.addEventListener('submit', function() {
            form.querySelectorAll('input.income-amount-indian').forEach(function(inp) {
                inp.value = stripCommas(inp.value).replace(/\D/g, '') || inp.getAttribute('data-raw') || '';
            });
        });
    }
})();
</script>
@endif
