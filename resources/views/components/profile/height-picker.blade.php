@props(['value' => null, 'namePrefix' => '', 'label' => 'Height', 'required' => false, 'wrapperClass' => null])

@php
    $inputName = $namePrefix !== '' ? $namePrefix . '[height_cm]' : 'height_cm';
    $heightCm = old($namePrefix !== '' ? str_replace('[', '.', str_replace(']', '', $namePrefix)) . '.height_cm' : 'height_cm', $value);
    $heightCm = $heightCm !== null && $heightCm !== '' ? (int) $heightCm : null;

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

    $selectedCm = 136;
    if ($heightCm === null || $heightCm === '' || $heightCm < 137) {
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
    $wrap = $wrapperClass ?? 'height-picker w-full border-2 border-rose-500 dark:border-rose-400 rounded-lg p-3';
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
    <input type="hidden" name="{{ $inputName }}" :value="heightCm">

<script>
document.addEventListener('alpine:init', function() {
    function heightPickerState(initialCm) {
        return {
            heightCm: initialCm,
            formatDisplay() {
                var cm = parseInt(this.heightCm, 10);
                if (!cm) return '—';
                return cm + ' cm';
            }
        };
    }
    window.heightPickerState = heightPickerState;
});
</script>
</div>
