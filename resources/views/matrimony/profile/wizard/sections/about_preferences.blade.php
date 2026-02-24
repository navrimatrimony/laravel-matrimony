{{-- Phase-5B: About & preferences — preferences + extended_narrative --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">About & preferences</h2>

    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Partner preferences</h3>
        @php $pr = old('preferences', $preferences ?? new \stdClass()); @endphp
        @if(is_object($pr) && isset($pr->id))<input type="hidden" name="preferences[id]" value="{{ $pr->id }}">@endif
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="text" name="preferences[preferred_city]" value="{{ is_object($pr) ? ($pr->preferred_city ?? '') : ($pr['preferred_city'] ?? '') }}" placeholder="Preferred city" class="rounded border px-3 py-2 w-full">
            <input type="text" name="preferences[preferred_caste]" value="{{ is_object($pr) ? ($pr->preferred_caste ?? '') : ($pr['preferred_caste'] ?? '') }}" placeholder="Preferred caste" class="rounded border px-3 py-2 w-full">
            <input type="number" name="preferences[preferred_age_min]" value="{{ is_object($pr) ? ($pr->preferred_age_min ?? '') : ($pr['preferred_age_min'] ?? '') }}" placeholder="Age min" class="rounded border px-3 py-2 w-full">
            <input type="number" name="preferences[preferred_age_max]" value="{{ is_object($pr) ? ($pr->preferred_age_max ?? '') : ($pr['preferred_age_max'] ?? '') }}" placeholder="Age max" class="rounded border px-3 py-2 w-full">
            <input type="number" name="preferences[preferred_income_min]" value="{{ is_object($pr) ? ($pr->preferred_income_min ?? '') : ($pr['preferred_income_min'] ?? '') }}" step="0.01" placeholder="Income min" class="rounded border px-3 py-2 w-full">
            <input type="number" name="preferences[preferred_income_max]" value="{{ is_object($pr) ? ($pr->preferred_income_max ?? '') : ($pr['preferred_income_max'] ?? '') }}" step="0.01" placeholder="Income max" class="rounded border px-3 py-2 w-full">
            <input type="text" name="preferences[preferred_education]" value="{{ is_object($pr) ? ($pr->preferred_education ?? '') : ($pr['preferred_education'] ?? '') }}" placeholder="Preferred education" class="rounded border px-3 py-2 w-full md:col-span-2">
        </div>
    </div>

    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">About me & expectations</h3>
        @php $en = old('extended_narrative', $extendedAttrs ?? new \stdClass()); @endphp
        @if(is_object($en) && isset($en->id))<input type="hidden" name="extended_narrative[id]" value="{{ $en->id }}">@endif
        <div class="space-y-2">
            <label class="block text-sm text-gray-600 dark:text-gray-400">About me</label>
            <textarea name="extended_narrative[narrative_about_me]" rows="4" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ is_object($en) ? ($en->narrative_about_me ?? '') : ($en['narrative_about_me'] ?? '') }}</textarea>
            <label class="block text-sm text-gray-600 dark:text-gray-400">Expectations</label>
            <textarea name="extended_narrative[narrative_expectations]" rows="4" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ is_object($en) ? ($en->narrative_expectations ?? '') : ($en['narrative_expectations'] ?? '') }}</textarea>
            <label class="block text-sm text-gray-600 dark:text-gray-400">Additional notes</label>
            <textarea name="extended_narrative[additional_notes]" rows="2" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ is_object($en) ? ($en->additional_notes ?? '') : ($en['additional_notes'] ?? '') }}</textarea>
        </div>
    </div>
</div>
