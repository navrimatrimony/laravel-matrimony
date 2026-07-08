@props([
    'value' => null,
    'namePrefix' => '',
    'label' => 'Height',
    'required' => false,
    'wrapperClass' => null,
    'allowFreeText' => false,
    'inputName' => null,
    'hiddenName' => null,
    'freeTextValue' => null,
    'compact' => false,
])

@php
    $resolvedHiddenName = $hiddenName ?? ($namePrefix !== '' ? $namePrefix . '[height_cm]' : 'height_cm');
    $resolvedInputName = $inputName ?? ($namePrefix !== '' ? $namePrefix . '[height]' : 'height');
    $heightCm = old($namePrefix !== '' ? str_replace('[', '.', str_replace(']', '', $namePrefix)) . '.height_cm' : 'height_cm', $value);
    $heightCm = $heightCm !== null && $heightCm !== '' ? (int) $heightCm : null;
    $freeTextOldKey = str_replace(']', '', str_replace('[', '.', $resolvedInputName));
    $freeTextDisplay = old($freeTextOldKey, $freeTextValue ?? ($heightCm ? ((string) $heightCm).' cm' : ''));

    // Range: first option "Below 4 feet 6 inch", then 4'6" through 7'0", then "Above 7 feet". No options below 4'6" or above 7'.
    $options = [];
    $options[] = ['label' => 'Below 4 feet 6 inch', 'value' => 136];
    for ($inches = 54; $inches <= 84; $inches++) {
        $cm = (int) round($inches * 2.54);
        $feet = (int) ($inches / 12);
        $inc = $inches % 12;
        $options[] = ['label' => $feet . "' " . $inc . '"', 'value' => $cm];
    }
    $options[] = ['label' => 'Above 7 feet', 'value' => 214];

    // Default when unset: 5'4" (64 in → 163 cm). "Below 4'6"" (136) only when stored value is in that bucket.
    $defaultCm = 163;
    $selectedCm = $defaultCm;
    if ($heightCm === null || $heightCm === '') {
        $selectedCm = $defaultCm;
    } elseif ($heightCm < 137) {
        $selectedCm = 136;
    } elseif ($heightCm > 213) {
        $selectedCm = 214;
    } else {
        $inRange = array_filter($options, fn ($o) => $o['value'] >= 137 && $o['value'] <= 213);
        $minDiff = min(array_map(fn ($o) => abs($o['value'] - $heightCm), $inRange));
        foreach ($inRange as $o) {
            if (abs($o['value'] - $heightCm) === $minDiff) {
                $selectedCm = $o['value'];
                break;
            }
        }
    }
    $wrap = $wrapperClass ?? ('height-picker w-full border border-gray-200 dark:border-gray-600 rounded-lg '.($compact ? 'p-0' : 'p-3'));
@endphp
<div class="{{ $wrap }}" x-data="heightPickerState({{ $selectedCm }})">
    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $label }} @if($required)<span class="text-red-500">*</span>@endif</label>

    <div class="flex items-center gap-3 w-full">
        <select class="flex-1 min-w-0 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2"
                x-model.number="heightCm">
            @foreach($options as $opt)
                <option value="{{ $opt['value'] }}" {{ $opt['value'] == $selectedCm ? 'selected' : '' }}>{{ $opt['label'] }}</option>
            @endforeach
        </select>
        <span class="text-sm text-gray-600 dark:text-gray-400 shrink-0" x-text="formatDisplay()"></span>
    </div>
    @if ($allowFreeText)
        <input
            type="text"
            name="{{ $resolvedInputName }}"
            value="{{ $freeTextDisplay }}"
            placeholder="165 cm or 5'5&quot;"
            class="mt-2 w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
            data-testid="bulk-correction-height-free-text"
        >
    @endif
    <input type="hidden" name="{{ $resolvedHiddenName }}" :value="heightCm">

<script>
document.addEventListener('alpine:init', function() {
    function heightPickerState(initialCm) {
        return {
            heightCm: initialCm,
            formatDisplay() {
                var cm = parseInt(this.heightCm, 10);
                if (!cm) return '—';
                var totalIn = cm / 2.54;
                var ft = Math.floor(totalIn / 12);
                var inch = Math.round(totalIn - ft * 12);
                if (inch === 12) { ft++; inch = 0; }
                if (inch < 0) inch = 0;
                return ft + "' " + inch + '" (' + cm + ' cm)';
            }
        };
    }
    window.heightPickerState = heightPickerState;
});
</script>
</div>
