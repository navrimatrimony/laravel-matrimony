{{-- Other Relatives — इतर नातेवाईक / गाव-आडनाव. --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Other Relatives</h2>
    <p class="text-sm text-gray-500 dark:text-gray-400">इतर नातेवाईक — आडनाव / गाव एकाच ओळीत लिहा.</p>

    <x-profile.one-line-extra-info
        name="other_relatives_text"
        :value="$otherRelativesText ?? ''"
        label=""
        placeholder="जाधव-कोल्हापूर, भोसले-सातारा"
        :rows="4"
    />
</div>
