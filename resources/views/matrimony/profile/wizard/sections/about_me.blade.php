{{-- Phase-5B: About Me — section title is the wizard h1 only (no repeated “About me” subheading). --}}
@php $namePrefix = $namePrefix ?? 'extended_narrative'; @endphp
<div class="space-y-6">
    <x-profile.about-me-narrative
        :namePrefix="$namePrefix"
        :value="$extendedAttrs ?? null"
        :showAdditionalNotes="false"
        :showTemplates="true"
    />
</div>
