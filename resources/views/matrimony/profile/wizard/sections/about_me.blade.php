{{-- Phase-5B: About Me — centralized extended narrative engine (about me + expectations). Partner filters in Partner preferences tab. --}}
@php $namePrefix = $namePrefix ?? 'extended_narrative'; @endphp
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">About me</h2>
    <x-profile.about-me-narrative
        :namePrefix="$namePrefix"
        :value="$extendedAttrs ?? null"
        :showAdditionalNotes="false"
    />
</div>
