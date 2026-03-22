<div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 p-3 space-y-2">
    <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('wizard.partner_pref_partner_diet_heading') }}</h4>
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('wizard.partner_pref_partner_diet_hint') }}</p>
    <input type="search" id="partner-pref-diet-filter" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 px-2 py-1.5 text-sm" placeholder="{{ __('wizard.filter_locations') }}" autocomplete="off">
    <div class="max-h-32 overflow-y-auto rounded border border-gray-200 dark:border-gray-600 p-2 bg-white dark:bg-gray-800/60">
        <div id="partner-pref-diet-chips" class="flex flex-wrap gap-2 content-start">
            @foreach(($partnerDietOptions ?? collect()) as $diet)
                @php
                    $dLabel = $diet->label ?? '';
                    $dKey = $diet->key ?? null;
                    if ($dKey) {
                        $tKey = 'components.options.diet.' . $dKey;
                        $t = __($tKey);
                        $dLabel = $t !== $tKey ? $t : $dLabel;
                    }
                @endphp
                <label class="partner-pref-diet-chip inline-flex items-center gap-1.5 rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-0.5 text-xs cursor-pointer hover:border-indigo-400 dark:hover:border-indigo-500" data-chip-label="{{ $dLabel }}">
                    <input type="checkbox" name="preferred_diet_ids[]" value="{{ $diet->id }}" class="rounded border-gray-300 dark:border-gray-600 text-indigo-600"
                        @if(in_array((int) $diet->id, $selectedDietIds ?? [], true)) checked @endif>
                    <span class="text-gray-800 dark:text-gray-100">{{ $dLabel }}</span>
                </label>
            @endforeach
        </div>
    </div>
</div>

<script>
    (function () {
        function applyDietFilter() {
            var q = ((document.getElementById('partner-pref-diet-filter') || {}).value || '').trim().toLowerCase();
            document.querySelectorAll('.partner-pref-diet-chip').forEach(function (el) {
                var lab = (el.getAttribute('data-chip-label') || '').toLowerCase();
                el.style.display = !q || lab.indexOf(q) !== -1 ? '' : 'none';
            });
        }
        document.addEventListener('DOMContentLoaded', function () {
            var f = document.getElementById('partner-pref-diet-filter');
            if (f) f.addEventListener('input', applyDietFilter);
        });
    })();
</script>
