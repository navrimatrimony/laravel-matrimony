{{--
    Centralized marital status dropdown: MasterMaritalStatus options, same look everywhere.
    Use at: wizard basic_info, wizard marriages, (later: intake preview core, admin edit).
--}}
@props([
    'name' => 'marital_status_id',
    'value' => null,
    'statuses' => [],
    'id' => 'wizard_marital_status_id',
    'required' => true,
    'label' => 'Marital Status',
    'showSummary' => false,
    'summaryText' => '',
])
@php
    $statuses = $statuses instanceof \Illuminate\Support\Collection ? $statuses->all() : (is_array($statuses) ? $statuses : []);
    $selected = old($name, $value);
    $selected = $selected === null || $selected === '' ? '' : (string) $selected;
@endphp
<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
        {{ $label }} @if($required)<span class="text-red-500">*</span>@endif
    </label>
    <select id="{{ $id }}"
            name="{{ $name }}"
            class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2"
            {{ $required ? 'required' : '' }}>
        <option value="">{{ __('components.marital.select_marital_status') }}</option>
        @foreach($statuses as $status)
            @php $s = is_object($status) ? $status : (object) $status; @endphp
            <option value="{{ $s->id ?? '' }}" {{ $selected === (string)($s->id ?? '') ? 'selected' : '' }}>💍 {{ $s->label ?? '' }}</option>
        @endforeach
    </select>
    @if($showSummary && $summaryText !== '')
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $summaryText }}</p>
    @endif
</div>
