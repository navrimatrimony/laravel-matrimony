@php
    $managedByKeys = [
        'self' => 'registering_for_self',
        'parent_guardian' => 'registering_for_parent_guardian',
        'sibling' => 'registering_for_sibling',
        'relative' => 'registering_for_relative',
        'friend' => 'registering_for_friend',
        'other' => 'registering_for_other',
    ];
    $pmBy = $selectedProfileManagedBy ?? null;
    $pmByStr = $pmBy === null || $pmBy === '' ? '' : (string) $pmBy;
@endphp

<div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 p-3 space-y-2">
    <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.partner_pref_managed_by_heading') }}</h4>
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.partner_pref_managed_by_hint') }}</p>
    <div class="flex flex-wrap gap-2">
        <label class="inline-flex items-center gap-2 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2.5 py-1.5 text-xs cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-950/40 dark:has-[:checked]:border-indigo-400">
            <input type="radio" name="preferred_profile_managed_by" value="" class="text-indigo-600 focus:ring-indigo-500" {{ $pmByStr === '' ? 'checked' : '' }}>
            <span class="text-gray-800 dark:text-gray-100">{{ __('wizard.partner_pref_managed_by_any') }}</span>
        </label>
        @foreach($managedByKeys as $mbKey => $onbKey)
            <label class="inline-flex items-center gap-2 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2.5 py-1.5 text-xs cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-950/40 dark:has-[:checked]:border-indigo-400">
                <input type="radio" name="preferred_profile_managed_by" value="{{ $mbKey }}" class="text-indigo-600 focus:ring-indigo-500" {{ $pmByStr === $mbKey ? 'checked' : '' }}>
                <span class="text-gray-800 dark:text-gray-100">{{ __('onboarding.' . $onbKey) }}</span>
            </label>
        @endforeach
    </div>
</div>
