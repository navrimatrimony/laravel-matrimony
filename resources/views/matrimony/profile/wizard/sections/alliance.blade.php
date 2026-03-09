{{-- Relatives tab: Maternal Family details + Other Relatives (आडनाव/गाव). --}}
<div class="space-y-8">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Relatives</h2>

    {{-- Maternal Family details (moved from Extended family) --}}
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

    {{-- Other Relatives — इतर नातेवाईक / गाव-आडनाव. --}}
    <div class="space-y-3">
        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Other Relatives</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">इतर नातेवाईक — आडनाव / गाव एकाच ओळीत लिहा.</p>
        <x-profile.one-line-extra-info
            name="other_relatives_text"
            :value="$otherRelativesText ?? ''"
            label=""
            placeholder="जाधव-कोल्हापूर, भोसले-सातारा"
            :rows="4"
        />
    </div>
</div>
