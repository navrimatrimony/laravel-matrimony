{{--
    Centralized address row: one row with Address (raw/line) + Type. Use in wizard location addresses, intake preview addresses.
    Snapshot key addresses: raw (or line1), type (or address_type).
--}}
@props([
    'namePrefix' => 'addresses[0]',
    'valueRaw' => '',
    'valueType' => 'current',
    'rawPlaceholder' => 'Full address',
    'typePlaceholder' => 'current/permanent',
])
<div class="flex gap-4 mb-3 items-end address-row">
    <div class="flex-1 min-w-0">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address</label>
        <input type="text"
               name="{{ $namePrefix }}[raw]"
               value="{{ $valueRaw }}"
               placeholder="{{ $rawPlaceholder }}"
               class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100">
    </div>
    <div class="w-32 shrink-0">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
        <input type="text"
               name="{{ $namePrefix }}[type]"
               value="{{ $valueType }}"
               placeholder="{{ $typePlaceholder }}"
               class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100">
    </div>
    {{ $slot ?? null }}
</div>
