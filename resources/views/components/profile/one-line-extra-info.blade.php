{{-- Centralized one-line extra info (e.g. इतर नातेवाईक / गाव-आडनाव). Single row: label + input. --}}
@props([
    'name' => '',
    'value' => '',
    'label' => 'Additional info',
    'placeholder' => 'e.g. गाव, आडनाव',
])
<div class="flex items-center gap-2 flex-nowrap">
    <label for="{{ $name }}" class="text-xs font-medium text-gray-600 dark:text-gray-400 shrink-0 whitespace-nowrap">{{ $label }}</label>
    <input type="text"
           id="{{ $name }}"
           name="{{ $name }}"
           value="{{ old($name, $value) }}"
           placeholder="{{ $placeholder }}"
           class="flex-1 min-w-0 h-10 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm">
</div>
