@props(['value' => null, 'namePrefix' => '', 'label' => 'Height', 'required' => false])

@php
    $inputName = $namePrefix !== '' ? $namePrefix . '[height_cm]' : 'height_cm';
    $heightCm = old($namePrefix !== '' ? str_replace('[', '.', str_replace(']', '', $namePrefix)) . '.height_cm' : 'height_cm', $value);
    $heightCm = $heightCm !== null && $heightCm !== '' ? (int) $heightCm : null;
    $options = [];
    foreach ([4, 5, 6, 7] as $feet) {
        foreach (range(0, 11) as $inches) {
            $cm = (int) round(($feet * 12 + $inches) * 2.54);
            $options[] = ['label' => $feet . "' " . $inches . '"', 'value' => $cm];
        }
    }
    if ($heightCm === null || $heightCm < 50 || $heightCm > 250) {
        $heightCm = 170;
    }
    $minDiff = collect($options)->min(fn ($o) => abs($o['value'] - $heightCm));
    $selectedCm = 170;
    foreach ($options as $o) {
        if (abs($o['value'] - $heightCm) === $minDiff) {
            $selectedCm = $o['value'];
            break;
        }
    }
@endphp
<div class="height-picker w-full" x-data="heightPickerState({{ $selectedCm }})">
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
