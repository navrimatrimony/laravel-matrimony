{{-- Family details only (separate tab): Parent engine + Family overview. Education & Career are in education-career section. --}}
@php
    $namePrefix = $namePrefix ?? '';
@endphp
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('wizard.family_details') }}</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
            <x-parent-engine
                :profile="$profile"
                :currencies="$currencies ?? []"
                :errors="$errors ?? []"
                :read-only="false"
                :addressTypes="$addressTypes ?? []"
                :wizardParentsAddresses="$wizardParentsAddresses ?? []"
                :namePrefix="$namePrefix"
            />
        </div>
        <div class="md:col-span-2">
            <x-family-overview
                :profile="$profile"
                :currencies="$currencies ?? []"
                :errors="$errors ?? []"
                :namePrefix="$namePrefix"
            />
        </div>
    </div>
</div>
