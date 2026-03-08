{{-- Siblings — engine with Yes/No toggle inside the block. --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Siblings</h2>
    <x-repeaters.sibling-details :siblings="$profileSiblings ?? collect()" :hasSiblings="$hasSiblings ?? null" />
</div>
