{{-- Centralized Sibling Engine. Brother/Sister details; no separate Brothers/Sisters count fields. --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Siblings</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400">Add sibling details (brothers &amp; sisters). All fields optional.</p>
    <x-repeaters.sibling-details :siblings="$profileSiblings ?? collect()" />
</div>
