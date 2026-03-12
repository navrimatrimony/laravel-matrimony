{{-- Centralized About Me / Extended narrative engine. Use for wizard and intake; single source for narrative_about_me, narrative_expectations, optional additional_notes. --}}
@props([
    'namePrefix' => 'extended_narrative',
    'value' => null,
    'showAdditionalNotes' => false,
])
@php
    $en = old($namePrefix, $value ?? new \stdClass());
    if (is_object($en)) {
        $narrativeAboutMe = $en->narrative_about_me ?? '';
        $narrativeExpectations = $en->narrative_expectations ?? '';
        $additionalNotes = $en->additional_notes ?? '';
        $hasId = isset($en->id);
        $idVal = $hasId ? $en->id : null;
    } else {
        $narrativeAboutMe = $en['narrative_about_me'] ?? '';
        $narrativeExpectations = $en['narrative_expectations'] ?? '';
        $additionalNotes = $en['additional_notes'] ?? '';
        $hasId = isset($en['id']);
        $idVal = $hasId ? $en['id'] : null;
    }
    $baseName = $namePrefix !== '' ? $namePrefix . '[' : '';
    $endName = $namePrefix !== '' ? ']' : '';
@endphp
@if($hasId && $idVal)
    <input type="hidden" name="{{ $baseName }}id{{ $endName }}" value="{{ $idVal }}">
@endif
<div class="space-y-3">
    <div>
        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">{{ __('profile.about_me') }}</label>
        <textarea name="{{ $baseName }}narrative_about_me{{ $endName }}" rows="4" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" placeholder="{{ __('profile.about_me') }}">{{ e($narrativeAboutMe) }}</textarea>
    </div>
    <div>
        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">{{ __('profile.expectations') }}</label>
        <textarea name="{{ $baseName }}narrative_expectations{{ $endName }}" rows="4" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" placeholder="{{ __('profile.expectations') }}">{{ e($narrativeExpectations) }}</textarea>
    </div>
    @if($showAdditionalNotes)
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">{{ __('profile.additional_details') }}</label>
            <textarea name="{{ $baseName }}additional_notes{{ $endName }}" rows="2" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2" placeholder="{{ __('intake.additional_notes_placeholder') }}">{{ e($additionalNotes) }}</textarea>
        </div>
    @endif
</div>
