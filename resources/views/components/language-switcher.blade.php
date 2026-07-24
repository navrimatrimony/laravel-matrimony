@props(['onRed' => false])

@php
    $isMr = \App\Support\LocalizedText::isMarathiLoose();
@endphp

<x-partials.language-toggle-core
    :is-mr="$isMr"
    :url-en="request()->fullUrlWithQuery(['locale' => 'en'])"
    :url-mr="request()->fullUrlWithQuery(['locale' => 'mr'])"
    :on-red="$onRed"
/>
