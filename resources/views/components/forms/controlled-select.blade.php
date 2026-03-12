@props([])
@php
    /** @var array $meta */
    $meta = $meta ?? [];
    $options = $meta['options'] ?? [];
    $selected = $meta['multiple'] ?? false
        ? ($meta['normalized_selected'] ?? [])
        : (($meta['normalized_selected'][0] ?? null));
    $selectId = $id ?? $name;
@endphp

<select
    name="{{ $name }}"
    id="{{ $selectId }}"
    @if(!empty($meta['multiple'])) multiple @endif
    @class([
        'w-full rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm',
        'required' => $required,
    ])
    @if($required) required @endif
    @if($disabled) disabled @endif
    {{ $attributes }}
>
    @if(!empty($placeholder))
        <option value="">{{ $placeholder }}</option>
    @endif
    @foreach($options as $opt)
        <option value="{{ $opt['id'] }}" @selected(is_array($selected) ? in_array($opt['id'], $selected) : (string)$opt['id'] === (string)$selected)>
            {{ $opt['label'] }}
        </option>
    @endforeach
</select>

