{{-- Extended family: Paternal (incl. grandfather/grandmother), Maternal grandparents, Grandparents' family. --}}
<div class="space-y-8">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Extended Family</h2>

    {{-- 1. Paternal extended family: grandfather, grandmother, uncle, aunt, husband of aunt, cousin, other --}}
    <div class="space-y-3">
        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Paternal extended family</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">Add one row per person. Use "Add" to add more rows — e.g. 4 uncles = 4 rows, each with Relation "Paternal Uncle (chulte)".</p>
        <x-repeaters.relation-details
            namePrefix="relatives_parents_family"
            :relationOptions="$relationTypesParentsFamily ?? []"
            :showMarried="false"
            :items="$profileRelativesParentsFamily ?? collect()"
            :showPrimaryContact="true"
            addressOnlyRelationValue="native_place"
            addButtonLabel="Add"
            removeButtonLabel="Remove this entry"
        />
    </div>

    {{-- 2. Maternal Family details --}}
    <div class="space-y-3">
        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Maternal Family details</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">Add one row per person. Use "Add" to add more rows — e.g. 4 mamas = 4 rows (each Relation "Maternal Uncle (mama)"), 3 mavshis = 3 rows (each "Maternal Aunt (mavshi)").</p>
        <x-repeaters.relation-details
            namePrefix="relatives_maternal_family"
            :relationOptions="$relationTypesMaternalFamily ?? []"
            :showMarried="false"
            :items="$profileRelativesMaternalFamily ?? collect()"
            :showPrimaryContact="true"
            addressOnlyRelationValue="maternal_address_ajol"
            addButtonLabel="Add"
            removeButtonLabel="Remove this entry"
        />
    </div>

</div>
