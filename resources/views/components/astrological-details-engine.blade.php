{{--
  Astrological details engine: Rashi, Nakshatra, Charan (model-based, no DOB API).
  Use with namePrefix e.g. "horoscope" => horoscope[rashi_id], horoscope[nakshatra_id], horoscope[charan].
--}}
@props([
    'namePrefix' => 'horoscope',
    'values' => [],
    'profile' => null,
    'rashis' => [],
    'nakshatras' => [],
    'readOnly' => false,
    'errors' => [],
])
@php
    $namePrefix = $namePrefix ?? 'horoscope';
    $rashis = $rashis ?? collect();
    $nakshatras = $nakshatras ?? collect();
    $profile = $profile ?? new \stdClass();
    $errorsArray = is_array($errors) ? $errors : [];
    if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
        $bag = $errors->getBag('default');
        foreach ($bag->getMessages() as $k => $msgs) {
            $errorsArray[$k] = is_array($msgs) ? ($msgs[0] ?? null) : $msgs;
        }
    }
    $n = fn($key) => $namePrefix ? $namePrefix . '[' . $key . ']' : $key;
    $oldKey = fn($key) => $namePrefix ? str_replace(']', '', str_replace('[', '.', $namePrefix . '[' . $key . ']')) : $key;
    $v = function($key) use ($values, $profile) {
        if (is_array($values) && array_key_exists($key, $values)) {
            return $values[$key];
        }
        return $profile->$key ?? null;
    };
    $err = function($key) use ($errorsArray, $namePrefix) {
        $k = $namePrefix ? $namePrefix . '.' . $key : $key;
        return $errorsArray[$k] ?? $errorsArray[$key] ?? null;
    };
    $rashiIdRaw = old($oldKey('rashi_id'), $v('rashi_id'));
    $nakshatraIdRaw = old($oldKey('nakshatra_id'), $v('nakshatra_id'));
    $charanRaw = old($oldKey('charan'), $v('charan'));
    $cardCls = 'rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/50 p-4';
    $labelCls = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
    $inputCls = 'w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm';
@endphp
<div class="astrological-details-engine space-y-4">
    <div class="{{ $cardCls }}">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Rashi, Nakshatra & Charan</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="astrological-rashi" class="{{ $labelCls }}">Rashi</label>
                @if($readOnly)
                    @php $rashiLabel = $rashiIdRaw ? (collect($rashis)->firstWhere('id', $rashiIdRaw)?->label ?? '—') : '—'; @endphp
                    <p class="py-2 text-gray-900 dark:text-gray-100">{{ $rashiLabel }}</p>
                @else
                    <select name="{{ $n('rashi_id') }}" id="astrological-rashi" class="{{ $inputCls }}">
                        <option value="">— Select —</option>
                        @foreach($rashis as $item)
                            <option value="{{ $item->id }}" {{ (string)($rashiIdRaw ?? '') === (string)$item->id ? 'selected' : '' }}>{{ $item->label ?? $item->name ?? '' }}</option>
                        @endforeach
                    </select>
                    @if($err('rashi_id'))<p class="text-red-600 text-xs mt-1">{{ $err('rashi_id') }}</p>@endif
                @endif
            </div>
            <div>
                <label for="astrological-nakshatra" class="{{ $labelCls }}">Nakshatra</label>
                @if($readOnly)
                    @php $nakshatraLabel = $nakshatraIdRaw ? (collect($nakshatras)->firstWhere('id', $nakshatraIdRaw)?->label ?? '—') : '—'; @endphp
                    <p class="py-2 text-gray-900 dark:text-gray-100">{{ $nakshatraLabel }}</p>
                @else
                    <select name="{{ $n('nakshatra_id') }}" id="astrological-nakshatra" class="{{ $inputCls }}">
                        <option value="">— Select —</option>
                        @foreach($nakshatras as $item)
                            <option value="{{ $item->id }}" {{ (string)($nakshatraIdRaw ?? '') === (string)$item->id ? 'selected' : '' }}>{{ $item->label ?? $item->name ?? '' }}</option>
                        @endforeach
                    </select>
                    @if($err('nakshatra_id'))<p class="text-red-600 text-xs mt-1">{{ $err('nakshatra_id') }}</p>@endif
                @endif
            </div>
            <div>
                <label for="astrological-charan" class="{{ $labelCls }}">Charan</label>
                @if($readOnly)
                    <p class="py-2 text-gray-900 dark:text-gray-100">{{ $charanRaw !== null && $charanRaw !== '' ? $charanRaw : '—' }}</p>
                @else
                    <select name="{{ $n('charan') }}" id="astrological-charan" class="{{ $inputCls }}">
                        <option value="">— Select —</option>
                        @foreach([1, 2, 3, 4] as $c)
                            <option value="{{ $c }}" {{ (string)($charanRaw ?? '') === (string)$c ? 'selected' : '' }}>{{ $c }}</option>
                        @endforeach
                    </select>
                    @if($err('charan'))<p class="text-red-600 text-xs mt-1">{{ $err('charan') }}</p>@endif
                @endif
            </div>
        </div>
    </div>
</div>
