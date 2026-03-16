{{-- Extended family: Paternal only. Maternal Family details are in the Relatives tab. --}}
<div class="space-y-8">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Extended Family</h2>

    {{-- Paternal extended family: grandfather, grandmother, uncle, aunt, husband of aunt, cousin, other --}}
    <div class="space-y-3">
        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Paternal extended family</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">Add one row per person. Use "Add" to add more rows — e.g. 4 uncles = 4 rows, each with Relation "Paternal Uncle (chulte)". Only these relations and Native Place. For all other relatives (आडनाव/गाव) use the "Other Relatives" section in the Relatives tab.</p>
        @php $namePrefix = $namePrefix ?? 'relatives_parents_family'; @endphp
        <x-repeaters.relation-details
            namePrefix="{{ $namePrefix }}"
            :relationOptions="$relationTypesParentsFamily ?? []"
            :showMarried="false"
            :items="$profileRelativesParentsFamily ?? collect()"
            :showPrimaryContact="true"
            addressOnlyRelationValue="native_place"
            :notesPlaceholder="__('components.relation.notes_placeholder_extended_family')"
            addButtonLabel="Add"
            removeButtonLabel="Remove this entry"
        />
    </div>
</div>
