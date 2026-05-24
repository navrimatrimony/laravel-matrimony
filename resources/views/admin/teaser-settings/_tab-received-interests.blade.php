<form method="POST" action="{{ route('admin.teaser-settings.received-interests.update') }}" class="space-y-6">
    @csrf

    <div class="rounded-lg border border-gray-200 bg-gray-50/80 p-4 dark:border-gray-600 dark:bg-gray-900/40">
        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-100">
            <input type="checkbox" name="rich_teaser_enabled" value="1" @checked(! empty($policy['rich_teaser_enabled'] ?? true)) class="mt-0.5 rounded border-gray-300 dark:border-gray-600">
            <span><strong>Rich locked card</strong> when reveal quota is exhausted (blurred photo + courtesy lines + same layout family as who-viewed teasers).</span>
        </label>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">If unchecked, the inbox shows a simple locked placeholder instead. Stored in <code class="text-xs">received_interest_teaser_policy_json</code>. <strong>Accept</strong> requires a revealed sender; <strong>Reject</strong> is always allowed.</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">{{ __('admin.received_card_layout_label') }}</label>
        <select name="card_layout" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="horizontal" @selected(($policy['card_layout'] ?? 'horizontal') === 'horizontal')>{{ __('admin.received_card_layout_horizontal') }}</option>
            <option value="vertical" @selected(($policy['card_layout'] ?? '') === 'vertical')>{{ __('admin.received_card_layout_vertical') }}</option>
            <option value="two_column" @selected(($policy['card_layout'] ?? '') === 'two_column')>{{ __('admin.received_card_layout_two_column') }}</option>
            <option value="photo_overlay" @selected(($policy['card_layout'] ?? '') === 'photo_overlay')>{{ __('admin.received_card_layout_photo_overlay') }}</option>
        </select>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.received_card_layout_help') }}</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">{{ __('admin.received_inbox_row_order_label') }}</label>
        <select name="received_inbox_row_order" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="priority_then_recent" @selected(($policy['received_inbox_row_order'] ?? 'priority_then_recent') === 'priority_then_recent')>{{ __('admin.received_inbox_row_order_priority') }}</option>
            <option value="newest_first" @selected(($policy['received_inbox_row_order'] ?? '') === 'newest_first')>{{ __('admin.received_inbox_row_order_newest') }}</option>
            <option value="unlocked_first_recent" @selected(($policy['received_inbox_row_order'] ?? '') === 'unlocked_first_recent')>{{ __('admin.received_inbox_row_order_unlocked') }}</option>
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">{{ __('admin.received_inbox_per_page_label') }}</label>
        <input type="number" name="received_inbox_per_page" min="5" max="50" value="{{ old('received_inbox_per_page', (int) ($policy['received_inbox_per_page'] ?? 15)) }}" class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.received_inbox_per_page_help') }}</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Location detail (never shows village / exact place)</label>
        <select name="location_granularity" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="state_only" @selected(($policy['location_granularity'] ?? '') === 'state_only')>State only</option>
            <option value="district_and_above" @selected(($policy['location_granularity'] ?? '') === 'district_and_above')>District + state</option>
            <option value="taluka_and_above" @selected(($policy['location_granularity'] ?? '') === 'taluka_and_above')>Taluka + district + state</option>
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Age on teaser</label>
        <select name="show_age_mode" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="off" @selected(($policy['show_age_mode'] ?? '') === 'off')>Hidden</option>
            <option value="decade" @selected(($policy['show_age_mode'] ?? '') === 'decade')>Decade band</option>
            <option value="exact" @selected(($policy['show_age_mode'] ?? 'exact') === 'exact')>Single age in years</option>
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Name</label>
        <select name="name_display" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="hidden" @selected(($policy['name_display'] ?? '') === 'hidden')>Hidden</option>
            <option value="masked" @selected(($policy['name_display'] ?? 'masked') === 'masked')>Masked</option>
            <option value="courtesy_from_place" @selected(($policy['name_display'] ?? '') === 'courtesy_from_place')>A girl / woman from place</option>
            <option value="first_only" @selected(($policy['name_display'] ?? '') === 'first_only')>First name only</option>
            <option value="full" @selected(($policy['name_display'] ?? '') === 'full')>Full name (highest risk)</option>
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Masked name — dots after first letter</label>
        <input type="number" name="masked_name_dots" min="3" max="10" value="{{ old('masked_name_dots', (int) ($policy['masked_name_dots'] ?? 5)) }}" class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
    </div>

    <div class="space-y-3">
        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Optional detail lines</p>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="show_occupation" value="1" @checked(! empty($policy['show_occupation'])) class="rounded border-gray-300 dark:border-gray-600"> Occupation
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="show_education" value="1" @checked(! empty($policy['show_education'])) class="rounded border-gray-300 dark:border-gray-600"> Education
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="show_marital_status" value="1" @checked(! empty($policy['show_marital_status'])) class="rounded border-gray-300 dark:border-gray-600"> Marital status
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="show_match_teaser" value="1" @checked(! empty($policy['show_match_teaser'] ?? true)) class="rounded border-gray-300 dark:border-gray-600"> Compatibility line (match score)
        </label>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Minimum match score (%) for compatibility line</label>
        <input type="number" name="match_teaser_min_score" min="50" max="95" value="{{ old('match_teaser_min_score', (int) ($policy['match_teaser_min_score'] ?? 75)) }}" class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Teaser avatar</label>
        <select name="teaser_avatar_style" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="silhouette" @selected(($policy['teaser_avatar_style'] ?? '') === 'silhouette')>Silhouette only</option>
            <option value="blur" @selected(($policy['teaser_avatar_style'] ?? 'blur') === 'blur')>Blurred approved photo</option>
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Blur strength</label>
        <select name="teaser_blur_strength" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="light" @selected(($policy['teaser_blur_strength'] ?? '') === 'light')>Light</option>
            <option value="soft" @selected(($policy['teaser_blur_strength'] ?? '') === 'soft')>Soft</option>
            <option value="gentle" @selected(($policy['teaser_blur_strength'] ?? '') === 'gentle')>Gentle</option>
            <option value="medium" @selected(($policy['teaser_blur_strength'] ?? 'medium') === 'medium')>Medium</option>
            <option value="strong" @selected(($policy['teaser_blur_strength'] ?? '') === 'strong')>Strong</option>
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">“Interest received …” time line</label>
        <select name="teaser_viewed_time" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="human" @selected(($policy['teaser_viewed_time'] ?? 'human') === 'human')>Relative time</option>
            <option value="bucket" @selected(($policy['teaser_viewed_time'] ?? '') === 'bucket')>Coarse buckets</option>
        </select>
    </div>

    <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">{{ __('admin.save_changes') }}</button>
</form>
