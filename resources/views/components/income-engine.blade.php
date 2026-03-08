@props([
    'label' => 'Income',
    'namePrefix' => 'income',
    'values' => [],
    'profile' => null,
    'currencies' => [],
    'privacyEnabled' => true,
    'periodOptions' => null,
    'valueTypeOptions' => null,
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
        $valueTypeRaw = ($v('amount') !== null || $v('min_amount') !== null || ($prefix === 'income' ? ($profile->annual_income ?? null) : ($profile->family_income ?? null)) !== null) ? 'exact' : 'undisclosed';
    }
    $currencyIdRaw = old($oldKey('currency_id'), $v('currency_id'));
    $privateRaw = old($oldKey('private'), $v('private'));
    if ($prefix === 'family_income' && ($currencyIdRaw === null || $currencyIdRaw === '')) {
        $currencyIdRaw = $profile->income_currency_id ?? null;
    }
    $defaultCurrencyId = $currencyIdRaw ?: (collect($currencies)->firstWhere('is_default', true)?->id ?? $currencies->first()?->id);

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

    $chipCls = 'rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/60 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 focus:ring-2 focus:ring-rose-500/40 focus:border-rose-400 outline-none min-w-0 cursor-pointer';
    $amountCls = 'rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 px-4 py-2.5 text-sm font-medium text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-rose-500/40 focus:border-rose-400 outline-none transition-shadow';
    $currencyChipCls = 'rounded-full border-2 border-amber-300 dark:border-amber-600 bg-gradient-to-br from-amber-50 to-amber-100/80 dark:from-amber-900/40 dark:to-amber-800/30 px-4 py-2 text-sm font-semibold text-amber-800 dark:text-amber-200 focus:ring-2 focus:ring-amber-400/50 outline-none cursor-pointer appearance-none min-w-[5.5rem] shadow-sm';
    $privacyLabelText = $prefix === 'income' ? 'Keep my income private' : 'Keep family income private';
@endphp
<div class="income-engine w-full rounded-2xl border border-gray-200/80 dark:border-gray-600/80 bg-white dark:bg-gray-800/60 shadow-md shadow-gray-200/50 dark:shadow-none overflow-hidden">
    <div class="px-4 pt-4 pb-1">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $label }}</h3>
    </div>
    <div class="p-4 pt-2">
        {{-- One-line engine: 100% width, no trailing gap --}}
        <div class="flex flex-wrap items-center gap-2 sm:gap-3 w-full">
            {{-- Period: flex so text is fully visible --}}
            <div class="flex-1 min-w-[5rem]">
                @if(!$readOnly)
                    <select name="{{ $n('period') }}" class="{{ $chipCls }} w-full" {{ $disabled ? 'disabled' : '' }} aria-label="Period">
                        @foreach($periodOptions as $opt)
                            <option value="{{ $opt['value'] }}" {{ (string)$periodRaw === (string)$opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="inline-flex items-center rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/60 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200">{{ collect($periodOptions)->firstWhere('value', $periodRaw)['label'] ?? $periodRaw ?? '—' }}</span>
                @endif
                @if($err('period'))<p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $err('period') }}</p>@endif
            </div>

            {{-- Value type: flex so "Prefer not to say" etc visible --}}
            <div class="flex-1 min-w-[8rem]">
                @if(!$readOnly)
                    <select name="{{ $n('value_type') }}" class="{{ $chipCls }} income-value-type-select w-full" data-engine="{{ $prefix }}" {{ $disabled ? 'disabled' : '' }} aria-label="Value type">
                        @foreach($valueTypeOptions as $opt)
                            <option value="{{ $opt['value'] }}" {{ (string)$valueTypeRaw === (string)$opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="inline-flex items-center rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/60 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200">{{ collect($valueTypeOptions)->firstWhere('value', $valueTypeRaw)['label'] ?? $valueTypeRaw ?? '—' }}</span>
                @endif
                @if($err('value_type'))<p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $err('value_type') }}</p>@endif
            </div>

            {{-- Amount: takes remaining space so line is 100% filled --}}
            <div class="income-amount-area flex flex-wrap items-center gap-2 min-w-0 flex-[2]" data-engine="{{ $prefix }}">
                <div class="income-amount-single flex-1 min-w-[6rem] transition-opacity duration-200" data-show="exact,approximate" style="{{ in_array($valueTypeRaw, ['exact','approximate'], true) ? '' : 'display:none!important' }}">
                    <input type="text" inputmode="numeric" name="{{ $n('amount') }}" value="{{ $amountDisplayFormatted }}" placeholder="Amount" class="{{ $amountCls }} w-full min-w-0 income-amount-indian" data-raw="{{ $amountDisplay($amountVal) }}" {{ $disabled ? 'disabled' : '' }} aria-label="Amount" autocomplete="off">
                </div>
                <div class="income-amount-range inline-flex items-center gap-2 flex-1 min-w-[10rem] transition-opacity duration-200" data-show="range" style="{{ $valueTypeRaw === 'range' ? '' : 'display:none!important' }}">
                    <input type="text" inputmode="numeric" name="{{ $n('min_amount') }}" value="{{ $minAmountDisplayFormatted }}" placeholder="50,000" class="{{ $amountCls }} flex-1 min-w-0 income-amount-indian" data-raw="{{ $amountDisplay($minAmountVal) }}" {{ $disabled ? 'disabled' : '' }} aria-label="Minimum amount" autocomplete="off">
                    <span class="text-gray-400 dark:text-gray-500 font-medium select-none flex-shrink-0" aria-hidden="true">—</span>
                    <input type="text" inputmode="numeric" name="{{ $n('max_amount') }}" value="{{ $maxAmountDisplayFormatted }}" placeholder="75,000" class="{{ $amountCls }} flex-1 min-w-0 income-amount-indian" data-raw="{{ $amountDisplay($maxAmountVal) }}" {{ $disabled ? 'disabled' : '' }} aria-label="Maximum amount" autocomplete="off">
                </div>
                <div class="income-amount-undisclosed flex-shrink-0 transition-opacity duration-200" data-show="undisclosed" style="{{ $valueTypeRaw === 'undisclosed' ? '' : 'display:none!important' }}">
                    <span class="text-sm text-gray-400 dark:text-gray-500 italic">No amount</span>
                </div>
            </div>
            @if($err('amount'))<p class="text-red-600 dark:text-red-400 text-xs mt-1 w-full">{{ $err('amount') }}</p>@endif
            @if($err('min_amount'))<p class="text-red-600 dark:text-red-400 text-xs mt-1 w-full">{{ $err('min_amount') }}</p>@endif
            @if($err('max_amount'))<p class="text-red-600 dark:text-red-400 text-xs mt-1 w-full">{{ $err('max_amount') }}</p>@endif

            {{-- Currency: fixed share so pill is visible --}}
            <div class="flex-1 min-w-[5.5rem]">
                @if(!$readOnly)
                    <select name="{{ $n('currency_id') }}" class="{{ $currencyChipCls }} w-full" {{ $disabled ? 'disabled' : '' }} aria-label="Currency">
                        @foreach($currencies as $c)
                            <option value="{{ $c->id }}" {{ (string)$defaultCurrencyId === (string)$c->id ? 'selected' : '' }}>{{ $c->symbol }} {{ $c->code }}</option>
                        @endforeach
                    </select>
                @else
                    @php $cur = collect($currencies)->firstWhere('id', $defaultCurrencyId); @endphp
                    <span class="inline-flex items-center rounded-full border-2 border-amber-300 dark:border-amber-600 bg-gradient-to-br from-amber-50 to-amber-100/80 dark:from-amber-900/40 dark:to-amber-800/30 px-4 py-2 text-sm font-semibold text-amber-800 dark:text-amber-200">{{ $cur ? $cur->symbol . ' ' . $cur->code : '—' }}</span>
                @endif
                @if($err('currency_id'))<p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $err('currency_id') }}</p>@endif
            </div>
        </div>

        {{-- Privacy: खालच्या ओळीत --}}
        @if($privacyEnabled)
        <div class="flex items-center gap-2 mt-3 w-full">
            @if(!$readOnly)
                <input type="hidden" name="{{ $n('private') }}" value="0">
                <input type="checkbox" name="{{ $n('private') }}" value="1" id="{{ $prefix }}_private_cb" {{ $privateRaw ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 text-rose-600 focus:ring-rose-500" {{ $disabled ? 'disabled' : '' }}>
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
    valueTypeSelect.addEventListener('change', toggle);
    toggle();

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
