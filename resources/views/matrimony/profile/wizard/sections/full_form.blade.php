{{--
    Centralized full form: single source of truth for section order and structure.
    Used by: wizard full (section=full) and intake preview.
    Set $corePrefix, $horoscopePrefix, etc. when rendering for intake; leave empty for wizard.
--}}
@php
    $corePrefix = $corePrefix ?? '';
    $horoscopePrefix = $horoscopePrefix ?? 'horoscope';
    $siblingsPrefix = $siblingsPrefix ?? 'siblings';
    $relativesPaternalPrefix = $relativesPaternalPrefix ?? 'relatives_parents_family';
    $relativesMaternalPrefix = $relativesMaternalPrefix ?? 'relatives_maternal_family';
    $propertyPrefix = $propertyPrefix ?? '';
    $narrativePrefix = $narrativePrefix ?? 'extended_narrative';
@endphp
@include('matrimony.profile.wizard.sections.basic_info', ['namePrefix' => $corePrefix])
@include('matrimony.profile.wizard.sections.physical', ['namePrefix' => $corePrefix])
@include('matrimony.profile.wizard.sections.personal_family', ['namePrefix' => $corePrefix])

@include('matrimony.profile.wizard.sections.siblings', ['namePrefix' => $siblingsPrefix])
@include('matrimony.profile.wizard.sections.relatives', ['namePrefix' => $relativesPaternalPrefix])
@include('matrimony.profile.wizard.sections.alliance', ['namePrefix' => $relativesMaternalPrefix])
@include('matrimony.profile.wizard.sections.property', ['namePrefix' => $propertyPrefix])
@include('matrimony.profile.wizard.sections.horoscope', ['namePrefix' => $horoscopePrefix])
@include('matrimony.profile.wizard.sections.contacts')
@include('matrimony.profile.wizard.sections.about_me', ['namePrefix' => $narrativePrefix])
@include('matrimony.profile.wizard.sections.about_preferences')
{{-- Photo is managed via the dedicated photo upload engine (upload-photo page). --}}
