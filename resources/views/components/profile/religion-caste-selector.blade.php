@props(['profile', 'namePrefix' => ''])

@php
    $religions = \App\Models\Religion::where('is_active', true)->orderBy('label')->get(['id', 'label'])->toArray();
    $nameRel = $namePrefix !== '' ? $namePrefix . '[religion_id]' : 'religion_id';
    $nameCaste = $namePrefix !== '' ? $namePrefix . '[caste_id]' : 'caste_id';
    $nameSub = $namePrefix !== '' ? $namePrefix . '[sub_caste_id]' : 'sub_caste_id';
    $profile = $profile ?? new \stdClass();
@endphp

<div class="religion-caste-component grid md:grid-cols-3 gap-2 border-2 border-rose-500 dark:border-rose-400 rounded-lg p-4">
    <div class="religion-wrap">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Religion</label>
        <div class="relative">
            <input type="hidden" name="{{ $nameRel }}" class="religion-hidden" value="{{ old($nameRel, $profile->religion_id ?? '') }}">
            <input type="text" class="religion-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px]" autocomplete="off" placeholder="Type to search religion"
                value="{{ old('religion_label', $profile->religion?->label ?? $profile->religion_label ?? '') }}">
            <script type="application/json" class="religion-options-data">@json($religions)</script>
            <div class="religion-dropdown absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 border rounded-md shadow-lg max-h-48 overflow-y-auto hidden z-50"></div>
        </div>
    </div>
    <div class="caste-wrap">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Caste</label>
        <div class="relative">
            <input type="hidden" name="{{ $nameCaste }}" class="caste-hidden" value="{{ old($nameCaste, $profile->caste_id ?? '') }}">
            <input type="text" class="caste-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px]" autocomplete="off" placeholder="Select religion first, then type to search"
                value="{{ old('caste_label', $profile->caste?->label ?? $profile->caste_label ?? '') }}" disabled>
            <div class="caste-dropdown absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 border rounded-md shadow-lg max-h-48 overflow-y-auto hidden z-50"></div>
        </div>
    </div>
    <div class="subcaste-wrap">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sub caste</label>
        <div class="relative">
            <input type="hidden" name="{{ $nameSub }}" class="subcaste-hidden" value="{{ old($nameSub, $profile->sub_caste_id ?? '') }}">
            <input type="text" class="subcaste-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 h-[42px]" autocomplete="off" placeholder="Type to search or add new"
                value="{{ old('subcaste_label', $profile->subCaste?->label ?? $profile->subcaste_label ?? '') }}">
            <div class="subcaste-dropdown absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 border rounded-md shadow-lg max-h-48 overflow-y-auto hidden z-50"></div>
        </div>
    </div>
</div>
