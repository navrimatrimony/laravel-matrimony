{{-- Extended family: Paternal only. Maternal Family details are in the Relatives tab. --}}
<div class="space-y-8">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('wizard.extended_family') }}</h2>

    {{-- Paternal extended family: grandfather, grandmother, uncle, aunt, husband of aunt, cousin, other --}}
    <div class="space-y-3">
        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ __('wizard.paternal_extended_family') }}</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('wizard.paternal_extended_family_help') }}</p>
        @php $namePrefix = $namePrefix ?? 'relatives_parents_family'; @endphp
        <x-repeaters.relation-details
            namePrefix="{{ $namePrefix }}"
            :relationOptions="$relationTypesParentsFamily ?? []"
            :showMarried="false"
            :items="$profileRelativesParentsFamily ?? collect()"
            :notesPlaceholder="__('components.relation.notes_placeholder_extended_family')"
            addButtonLabel="{{ __('wizard.add') }}"
            removeButtonLabel="{{ __('wizard.remove_entry') }}"
        />
    </div>
</div>
