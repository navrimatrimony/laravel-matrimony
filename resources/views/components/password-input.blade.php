@props([
    'id' => null,
    'name' => 'password',
    'autocomplete' => 'current-password',
    'required' => false,
    'autofocus' => false,
])

@php
    $inputId = $id ?? $name;
@endphp

<div
    class="relative mt-1"
    data-password-field
    data-label-show="{{ e(__('auth.password_show')) }}"
    data-label-hide="{{ e(__('auth.password_hide')) }}"
>
    <input
        id="{{ $inputId }}"
        name="{{ $name }}"
        type="password"
        @if ($required) required @endif
        @if ($autofocus) autofocus @endif
        autocomplete="{{ $autocomplete }}"
        {{ $attributes->class([
            'block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 pe-10',
        ]) }}
    />
    <button
        type="button"
        class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-gray-500 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded-e-md dark:text-gray-400 dark:hover:text-gray-200"
        aria-label="{{ __('auth.password_show') }}"
        aria-pressed="false"
        data-password-toggle
    >
        <span data-icon="show" class="block" aria-hidden="true">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
        </span>
        <span data-icon="hide" class="hidden" aria-hidden="true">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
            </svg>
        </span>
    </button>
</div>
