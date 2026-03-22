@props(['profile', 'namePrefix' => '', 'placeholderNotFound' => null, 'placeholderSelectRequired' => null])

@php
    $religions = \App\Models\Religion::where('is_active', true)->orderBy('label')->get()->map(fn ($r) => [
        'id' => $r->id,
        'label' => $r->display_label,
        'label_en' => $r->label_en ?? $r->label,
        'label_mr' => $r->label_mr,
    ])->values()->all();
    $nameRel = $namePrefix !== '' ? $namePrefix . '[religion_id]' : 'religion_id';
    $nameCaste = $namePrefix !== '' ? $namePrefix . '[caste_id]' : 'caste_id';
    $nameSub = $namePrefix !== '' ? $namePrefix . '[sub_caste_id]' : 'sub_caste_id';
    $profile = $profile ?? new \stdClass();
    $isIntake = ($namePrefix === 'snapshot[core]');
    $isPh = function($v) use ($placeholderNotFound, $placeholderSelectRequired) {
        return ($placeholderNotFound !== null && $v === $placeholderNotFound)
            || ($placeholderSelectRequired !== null && $v === $placeholderSelectRequired);
    };
    $relLabel = old('religion_label', $profile->religion?->label ?? $profile->religion_label ?? '');
    $casteLabel = old('caste_label', $profile->caste?->label ?? $profile->caste_label ?? '');
    $subLabel = old('subcaste_label', $profile->subCaste?->label ?? $profile->subcaste_label ?? '');
    $relDisplay = ($isIntake && $isPh($relLabel)) ? '' : $relLabel;
    $casteDisplay = ($isIntake && $isPh($casteLabel)) ? '' : $casteLabel;
    $subDisplay = ($isIntake && $isPh($subLabel)) ? '' : $subLabel;
    $relMissing = $isIntake && $relLabel !== '' && $relDisplay === '';
    $casteMissing = $isIntake && $casteLabel !== '' && $casteDisplay === '';
    $subMissing = $isIntake && $subLabel !== '' && $subDisplay === '';
@endphp

<div class="religion-caste-component grid md:grid-cols-3 gap-2 border-2 border-rose-500 dark:border-rose-400 rounded-lg p-4">
    <div class="religion-wrap {{ $relMissing ? 'ocr-field-missing-wrap' : '' }}">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Religion') }}</label>
        <div class="relative">
            <input type="hidden" name="{{ $nameRel }}" class="religion-hidden" value="{{ old($nameRel, $profile->religion_id ?? '') }}">
            <input type="text" class="religion-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px] {{ $relMissing ? 'ocr-field-missing' : '' }}" autocomplete="off" placeholder="{{ __('Type to search religion') }}"
                value="{{ $relDisplay }}"
                @if($relMissing) data-ocr-missing="1" data-placeholder-value="{{ e($placeholderNotFound) }}" @endif>
            <script type="application/json" class="religion-options-data">@json($religions)</script>
            <div class="religion-dropdown absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 border rounded-md shadow-lg max-h-48 overflow-y-auto hidden z-50"></div>
        </div>
    </div>
    <div class="caste-wrap {{ $casteMissing ? 'ocr-field-missing-wrap' : '' }}">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Caste') }}</label>
        <div class="relative">
            <input type="hidden" name="{{ $nameCaste }}" class="caste-hidden" value="{{ old($nameCaste, $profile->caste_id ?? '') }}">
            <input type="text" class="caste-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px] {{ $casteMissing ? 'ocr-field-missing' : '' }}" autocomplete="off" placeholder="{{ __('Select religion first, then type to search') }}"
                value="{{ $casteDisplay }}" @if($casteMissing) data-ocr-missing="1" data-placeholder-value="{{ e($placeholderNotFound) }}" @endif disabled>
            <div class="caste-dropdown absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 border rounded-md shadow-lg max-h-48 overflow-y-auto hidden z-50"></div>
        </div>
    </div>
    <div class="subcaste-wrap {{ $subMissing ? 'ocr-field-missing-wrap' : '' }}">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Sub caste') }}</label>
        <div class="relative">
            <input type="hidden" name="{{ $nameSub }}" class="subcaste-hidden" value="{{ old($nameSub, $profile->sub_caste_id ?? '') }}">
            <input type="text" class="subcaste-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px] {{ $subMissing ? 'ocr-field-missing' : '' }}" autocomplete="off" placeholder="{{ __('Type to search or add new') }}"
                value="{{ $subDisplay }}"
                @if($subMissing) data-ocr-missing="1" data-placeholder-value="{{ e($placeholderNotFound) }}" @endif>
            <div class="subcaste-dropdown absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 border rounded-md shadow-lg max-h-48 overflow-y-auto hidden z-50"></div>
        </div>
    </div>
</div>
