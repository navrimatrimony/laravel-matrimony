<form method="POST" action="{{ route('admin.teaser-settings.chat.update') }}" class="space-y-6">
    @csrf

    <div class="rounded-lg border border-gray-200 bg-gray-50/80 p-4 dark:border-gray-600 dark:bg-gray-900/40">
        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-100">
            <input type="checkbox" name="locked_message_teaser_enabled" value="1" @checked(! empty($policy['locked_message_teaser_enabled'] ?? true)) class="mt-0.5 rounded border-gray-300 dark:border-gray-600">
            <span><strong>Chat teaser settings</strong> for locked chat/message surfaces.</span>
        </label>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">This controls preview presentation only. Actual chat permission, reply gates and limits remain in Communication policy. Stored in <code class="text-xs">chat_teaser_policy_json</code>.</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Locked message style</label>
        <select name="locked_message_style" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="anonymous" @selected(($policy['locked_message_style'] ?? 'anonymous') === 'anonymous')>Anonymous</option>
            <option value="soft_context" @selected(($policy['locked_message_style'] ?? '') === 'soft_context')>Soft context</option>
            <option value="upgrade_focused" @selected(($policy['locked_message_style'] ?? '') === 'upgrade_focused')>Upgrade focused</option>
        </select>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Use anonymous for the safest locked notification copy.</p>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Preview line</label>
        <select name="preview_line_mode" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="generic" @selected(($policy['preview_line_mode'] ?? 'generic') === 'generic')>Generic message received</option>
            <option value="relationship_safe" @selected(($policy['preview_line_mode'] ?? '') === 'relationship_safe')>Relationship-safe context</option>
            <option value="hidden" @selected(($policy['preview_line_mode'] ?? '') === 'hidden')>Hide preview line</option>
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Locked chat CTA</label>
        <select name="locked_chat_cta" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="upgrade" @selected(($policy['locked_chat_cta'] ?? 'upgrade') === 'upgrade')>Upgrade to view chat</option>
            <option value="request_contact" @selected(($policy['locked_chat_cta'] ?? '') === 'request_contact')>Request contact access</option>
            <option value="open_plans" @selected(($policy['locked_chat_cta'] ?? '') === 'open_plans')>Open plans page</option>
        </select>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Message time line</label>
        <select name="locked_message_time" class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
            <option value="human" @selected(($policy['locked_message_time'] ?? 'human') === 'human')>Relative time</option>
            <option value="bucket" @selected(($policy['locked_message_time'] ?? '') === 'bucket')>Coarse buckets</option>
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
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Maximum locked threads shown</label>
        <input type="number" name="max_locked_threads" min="1" max="50" value="{{ old('max_locked_threads', (int) ($policy['max_locked_threads'] ?? 10)) }}" class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm shadow-sm">
    </div>

    <div class="space-y-3">
        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Optional locked chat details</p>
        <p class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-100">
            Sender name is always shown clearly in chat. Only the incoming message content is locked for users without read access.
        </p>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="show_unread_count" value="1" @checked(! empty($policy['show_unread_count'] ?? true)) class="rounded border-gray-300 dark:border-gray-600"> Show unread count
        </label>
    </div>

    <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">{{ __('admin.save_changes') }}</button>
</form>
