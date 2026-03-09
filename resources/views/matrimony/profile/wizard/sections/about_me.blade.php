{{-- Phase-5B: About Me — extended narrative (about me + expectations). Partner filters are in Partner preferences tab. --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">About me</h2>

    @php $en = old('extended_narrative', $extendedAttrs ?? new \stdClass()); @endphp
    @if(is_object($en) && isset($en->id))<input type="hidden" name="extended_narrative[id]" value="{{ $en->id }}">@endif
    <div class="space-y-3">
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">About me</label>
            <textarea name="extended_narrative[narrative_about_me]" rows="4" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ is_object($en) ? ($en->narrative_about_me ?? '') : ($en['narrative_about_me'] ?? '') }}</textarea>
        </div>
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">Expectations</label>
            <textarea name="extended_narrative[narrative_expectations]" rows="4" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">{{ is_object($en) ? ($en->narrative_expectations ?? '') : ($en['narrative_expectations'] ?? '') }}</textarea>
        </div>
    </div>
</div>

