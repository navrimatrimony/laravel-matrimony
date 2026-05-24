{{-- Education & Career only (separate tab). Family details are in family-details section. --}}
@php
    $namePrefix = $namePrefix ?? '';
@endphp
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Education & Career</h2>
    @php
        $eduHistory = [];
    @endphp
    <x-education-occupation-income-engine
        :profile="$profile"
        :currencies="$currencies ?? []"
        mode="compact"
        :educationHistory="$eduHistory"
        :namePrefix="$namePrefix"
    />
</div>
