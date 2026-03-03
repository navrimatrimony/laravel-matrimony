@php $marriage = $marriage ?? null; @endphp
<div class="border border-gray-200 dark:border-gray-600 p-4 rounded-lg bg-gray-50 dark:bg-gray-700/30 space-y-4">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Marriage details</h3>
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Marriage Year</label>
        <input type="number" name="marriages[0][marriage_year]" min="1901" max="2155"
               value="{{ old('marriages.0.marriage_year', $marriage->marriage_year ?? '') }}"
               class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
    </div>
</div>
