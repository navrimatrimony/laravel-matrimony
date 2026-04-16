@extends('layouts.admin-showcase')

@section('showcase_content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex items-center justify-between gap-4 mb-6">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Showcase Chat — {{ $profile->full_name ?: ('Showcase #' . $profile->id) }}</h1>
            <p class="text-gray-500 dark:text-gray-400 text-sm">Profile #{{ $profile->id }} • User UI marker: <span class="font-semibold">AI Assisted Replies</span></p>
        </div>
        <a href="{{ route('admin.showcase-chat-settings.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700">← Back</a>
    </div>

    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('admin.showcase-chat-settings.update', ['profile' => $profile->id]) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-200 dark:border-gray-600 space-y-3">
            <label class="flex items-center gap-2">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" {{ ($setting->enabled ?? false) ? 'checked' : '' }}>
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Enable orchestration for this showcase profile</span>
            </label>
            <label class="flex items-center gap-2">
                <input type="hidden" name="ai_assisted_replies_enabled" value="0">
                <input type="checkbox" name="ai_assisted_replies_enabled" value="1" {{ ($setting->ai_assisted_replies_enabled ?? false) ? 'checked' : '' }}>
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Show “AI Assisted Replies” tag in chat UI</span>
            </label>
            <label class="flex items-center gap-2">
                <input type="hidden" name="admin_takeover_enabled" value="0">
                <input type="checkbox" name="admin_takeover_enabled" value="1" {{ ($setting->admin_takeover_enabled ?? true) ? 'checked' : '' }}>
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Allow admin takeover</span>
            </label>
        </div>

        <div class="p-4 rounded-lg border border-gray-200 dark:border-gray-700 space-y-3">
            <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200">Reply personality &amp; style</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">Shapes automated reply tone and length. Does not add labels to outgoing messages.</p>
            <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Personality preset</label>
                    <select name="personality_preset" class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm">
                        @foreach (['warm' => 'Warm', 'balanced' => 'Balanced', 'selective' => 'Selective', 'reserved' => 'Reserved'] as $val => $label)
                            <option value="{{ $val }}" {{ old('personality_preset', $setting->personality_preset ?? 'balanced') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Min words</label>
                    <input type="number" name="reply_length_min_words" min="1" max="200" value="{{ old('reply_length_min_words', $setting->reply_length_min_words ?? 4) }}" class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Max words</label>
                    <input type="number" name="reply_length_max_words" min="1" max="200" value="{{ old('reply_length_max_words', $setting->reply_length_max_words ?? 18) }}" class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm">
                </div>
            </div>
            <label class="flex items-center gap-2">
                <input type="hidden" name="style_variation_enabled" value="0">
                <input type="checkbox" name="style_variation_enabled" value="1" {{ old('style_variation_enabled', ($setting->style_variation_enabled ?? true) ? '1' : '0') === '1' ? 'checked' : '' }}>
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Style variation (greeting / wording rotates lightly)</span>
            </label>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">Business hours</h2>
                <label class="flex items-center gap-2 mb-3">
                    <input type="hidden" name="business_hours_enabled" value="0">
                    <input type="checkbox" name="business_hours_enabled" value="1" {{ ($setting->business_hours_enabled ?? true) ? 'checked' : '' }}>
                    <span class="text-sm font-semibold">Business hours enabled</span>
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Start</label>
                        <input type="time" name="business_hours_start" value="{{ old('business_hours_start', $setting->business_hours_start) }}" class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">End</label>
                        <input type="time" name="business_hours_end" value="{{ old('business_hours_end', $setting->business_hours_end) }}" class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Business days JSON (ISO 1..7)</label>
                    <input type="text" name="business_days_json" value="{{ old('business_days_json', json_encode($setting->business_days_json ?? [1,2,3,4,5,6,7])) }}" class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm" placeholder="[1,2,3,4,5]">
                </div>
            </div>

            <div class="p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">Off-hours permissions</h2>
                <label class="flex items-center gap-2 mb-2">
                    <input type="hidden" name="off_hours_online_allowed" value="0">
                    <input type="checkbox" name="off_hours_online_allowed" value="1" {{ ($setting->off_hours_online_allowed ?? false) ? 'checked' : '' }}>
                    <span class="text-sm font-semibold">Allow online outside business hours</span>
                </label>
                <label class="flex items-center gap-2 mb-2">
                    <input type="hidden" name="off_hours_read_allowed" value="0">
                    <input type="checkbox" name="off_hours_read_allowed" value="1" {{ ($setting->off_hours_read_allowed ?? false) ? 'checked' : '' }}>
                    <span class="text-sm font-semibold">Allow reads outside business hours</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="hidden" name="off_hours_reply_allowed" value="0">
                    <input type="checkbox" name="off_hours_reply_allowed" value="1" {{ ($setting->off_hours_reply_allowed ?? false) ? 'checked' : '' }}>
                    <span class="text-sm font-semibold">Allow replies outside business hours</span>
                </label>
            </div>
        </div>

        <div class="p-4 rounded-lg border border-gray-200 dark:border-gray-700">
            <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-4">Timing</h2>
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ([
                    ['Online session (min/max minutes)', 'online_session_min_minutes', 'online_session_max_minutes'],
                    ['Offline gap (min/max minutes)', 'offline_gap_min_minutes', 'offline_gap_max_minutes'],
                    ['Read delay (min/max minutes)', 'read_delay_min_minutes', 'read_delay_max_minutes'],
                    ['Reply delay (min/max minutes)', 'reply_delay_min_minutes', 'reply_delay_max_minutes'],
                    ['Reply after read (min/max minutes)', 'reply_after_read_min_minutes', 'reply_after_read_max_minutes'],
                    ['Typing duration (min/max seconds)', 'typing_duration_min_seconds', 'typing_duration_max_seconds'],
                ] as $row)
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-700/30 border border-gray-200 dark:border-gray-600 p-3">
                        <p class="text-xs font-bold text-gray-700 dark:text-gray-200 mb-2">{{ $row[0] }}</p>
                        <div class="flex gap-2">
                            <input type="number" name="{{ $row[1] }}" value="{{ old($row[1], $setting->{$row[1]}) }}" class="w-1/2 rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                            <input type="number" name="{{ $row[2] }}" value="{{ old($row[2], $setting->{$row[2]}) }}" class="w-1/2 rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Reply probability percent (0-100)</label>
                    <input type="number" name="reply_probability_percent" min="0" max="100" value="{{ old('reply_probability_percent', $setting->reply_probability_percent) }}" class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Initiate probability percent (0-100)</label>
                    <input type="number" name="initiate_probability_percent" min="0" max="100" value="{{ old('initiate_probability_percent', $setting->initiate_probability_percent) }}" class="w-full rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm">
                </div>
                <div class="flex items-end gap-3">
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="typing_enabled" value="0">
                        <input type="checkbox" name="typing_enabled" value="1" {{ ($setting->typing_enabled ?? true) ? 'checked' : '' }}>
                        <span class="text-sm font-semibold">Typing enabled</span>
                    </label>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-6">
                <label class="flex items-center gap-2">
                    <input type="hidden" name="pause_on_sensitive_keywords" value="0">
                    <input type="checkbox" name="pause_on_sensitive_keywords" value="1" {{ ($setting->pause_on_sensitive_keywords ?? true) ? 'checked' : '' }}>
                    <span class="text-sm font-semibold">Pause on sensitive keywords</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="hidden" name="is_paused" value="0">
                    <input type="checkbox" name="is_paused" value="1" {{ ($setting->is_paused ?? false) ? 'checked' : '' }}>
                    <span class="text-sm font-semibold">Paused</span>
                </label>
                <input type="hidden" name="read_only_when_online" value="1">
                <input type="hidden" name="batch_read_enabled" value="1">
                <input type="hidden" name="online_before_read_min_seconds" value="{{ $setting->online_before_read_min_seconds }}">
                <input type="hidden" name="online_before_read_max_seconds" value="{{ $setting->online_before_read_max_seconds }}">
                <input type="hidden" name="online_linger_after_reply_min_seconds" value="{{ $setting->online_linger_after_reply_min_seconds }}">
                <input type="hidden" name="online_linger_after_reply_max_seconds" value="{{ $setting->online_linger_after_reply_max_seconds }}">
                <input type="hidden" name="force_read_by_max_hours" value="{{ $setting->force_read_by_max_hours }}">
                <input type="hidden" name="batch_read_window_min_minutes" value="{{ $setting->batch_read_window_min_minutes }}">
                <input type="hidden" name="batch_read_window_max_minutes" value="{{ $setting->batch_read_window_max_minutes }}">
                <input type="hidden" name="max_replies_per_day" value="{{ $setting->max_replies_per_day }}">
                <input type="hidden" name="max_replies_per_conversation_per_day" value="{{ $setting->max_replies_per_conversation_per_day }}">
                <input type="hidden" name="cooldown_after_last_outgoing_min_minutes" value="{{ $setting->cooldown_after_last_outgoing_min_minutes }}">
                <input type="hidden" name="cooldown_after_last_outgoing_max_minutes" value="{{ $setting->cooldown_after_last_outgoing_max_minutes }}">
                <input type="hidden" name="no_reply_after_unanswered_count" value="{{ $setting->no_reply_after_unanswered_count }}">
                <input type="hidden" name="online_before_read_min_seconds" value="{{ $setting->online_before_read_min_seconds }}">
                <input type="hidden" name="online_before_read_max_seconds" value="{{ $setting->online_before_read_max_seconds }}">
            </div>
        </div>

        <div class="pt-2 flex items-center justify-end gap-3">
            <a href="{{ route('admin.showcase-conversations.index') }}" class="text-sm font-semibold text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">View showcase conversations</a>
            <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Save</button>
        </div>
    </form>
</div>
@endsection

