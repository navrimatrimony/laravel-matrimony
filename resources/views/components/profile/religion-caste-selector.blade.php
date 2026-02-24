@props(['profile'])

@php
    $religions = \App\Models\Religion::where('is_active', true)->orderBy('label')->get(['id', 'label'])->toArray();
@endphp

<div class="religion-caste-component grid md:grid-cols-3 gap-4">
    <div class="religion-wrap">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Religion</label>
        <div class="relative">
            <input type="hidden" name="religion_id" class="religion-hidden" value="{{ old('religion_id', $profile->religion_id ?? '') }}">
            <input type="text" class="religion-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" autocomplete="off" placeholder="Type to search religion"
                value="{{ old('religion_label', $profile->religion ? $profile->religion->label : '') }}">
            <script type="application/json" class="religion-options-data">@json($religions)</script>
            <div class="religion-dropdown absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 border rounded-md shadow-lg max-h-48 overflow-y-auto hidden z-50"></div>
        </div>
    </div>
    <div class="caste-wrap">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Caste</label>
        <div class="relative">
            <input type="hidden" name="caste_id" class="caste-hidden" value="{{ old('caste_id', $profile->caste_id ?? '') }}">
            <input type="text" class="caste-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" autocomplete="off" placeholder="Select religion first, then type to search"
                value="{{ old('caste_label', $profile->caste ? $profile->caste->label : '') }}" disabled>
            <div class="caste-dropdown absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 border rounded-md shadow-lg max-h-48 overflow-y-auto hidden z-50"></div>
        </div>
    </div>
    <div class="subcaste-wrap">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sub caste</label>
        <div class="relative">
            <input type="hidden" name="sub_caste_id" class="subcaste-hidden" value="{{ old('sub_caste_id', $profile->sub_caste_id ?? '') }}">
            <input type="text" class="subcaste-input w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" autocomplete="off" placeholder="Type to search or add new"
                value="{{ old('subcaste_label', $profile->subCaste ? $profile->subCaste->label : '') }}">
            <div class="subcaste-dropdown absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 border rounded-md shadow-lg max-h-48 overflow-y-auto hidden z-50"></div>
        </div>
    </div>
</div>
