@props(['onRed' => false])

@php
    $isMr = str_starts_with((string) app()->getLocale(), 'mr');
@endphp

<x-partials.language-toggle-core
    :is-mr="$isMr"
    :url-en="request()->fullUrlWithQuery(['locale' => 'en'])"
    :url-mr="request()->fullUrlWithQuery(['locale' => 'mr'])"
    :on-red="$onRed"
/>
