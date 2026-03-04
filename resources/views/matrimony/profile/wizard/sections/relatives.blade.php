{{-- Relatives & Family Network — same engine as siblings (relation-details), showMarried=false, primary contact. One line: इतर नातेवाईक (गाव / आडनाव). --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Relatives & Family Network</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400">Add extended family members. All fields are optional.</p>

    <x-repeaters.relation-details
        namePrefix="relatives"
        :relationOptions="$relationTypes ?? []"
        :showMarried="false"
        :items="$profileRelatives ?? collect()"
        :showPrimaryContact="true"
        addButtonLabel="Add Relative"
        removeButtonLabel="Remove this relative"
    />
</div>
