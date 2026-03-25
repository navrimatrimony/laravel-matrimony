@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Communication & Contact Request Policy (Day-32)</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Configure contact request behaviour. All changes are logged to admin audit with your reason. Existing grants/requests are not modified.</p>

    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 text-sm mb-4">{{ session('success') }}</p>
    @endif
    @if (session('info'))
        <p class="text-blue-600 dark:text-blue-400 text-sm mb-4">{{ session('info') }}</p>
    @endif
    @if ($errors->any())
        <ul class="text-red-600 dark:text-red-400 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('admin.communication-policy.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Reason for change (required, min 10 chars) <span class="text-red-500">*</span></label>
            <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="e.g. Relax contact request to allow direct requests for pilot"
                class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
        </div>

        <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Messaging policy (Chat)</h2>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="space-y-3">
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="allow_messaging" value="0">
                        <input type="checkbox" name="allow_messaging" value="1" {{ ($current['allow_messaging'] ?? true) ? 'checked' : '' }}>
                        <span class="text-sm font-medium">Messaging enabled</span>
                    </label>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Messaging Mode</label>
                        <select name="messaging_mode" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                            <option value="free_chat_with_reply_gate" {{ ($current['messaging_mode'] ?? 'free_chat_with_reply_gate') === 'free_chat_with_reply_gate' ? 'selected' : '' }}>Free chat with reply gate</option>
                            <option value="contact_request_required" {{ ($current['messaging_mode'] ?? '') === 'contact_request_required' ? 'selected' : '' }}>Contact request required</option>
                        </select>
                    </div>

                    <label class="flex items-center gap-2">
                        <input type="hidden" name="allow_image_messages" value="0">
                        <input type="checkbox" name="allow_image_messages" value="1" {{ ($current['allow_image_messages'] ?? true) ? 'checked' : '' }}>
                        <span class="text-sm font-medium">Allow image messages</span>
                    </label>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Image messages available for</label>
                        <select name="image_messages_audience" class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                            <option value="paid_only" {{ ($current['image_messages_audience'] ?? 'paid_only') === 'paid_only' ? 'selected' : '' }}>Paid users only (default)</option>
                            <option value="all" {{ ($current['image_messages_audience'] ?? '') === 'all' ? 'selected' : '' }}>Free + Paid (everyone)</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Paid check is via entitlement key <code>chat_image_messages</code>.</p>
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Consecutive message limit without reply</label>
                        <input type="number" name="max_consecutive_messages_without_reply" value="{{ old('max_consecutive_messages_without_reply', $current['max_consecutive_messages_without_reply'] ?? 2) }}" min="1" max="20"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cooling period (hours)</label>
                        <input type="number" name="reply_gate_cooling_hours" value="{{ old('reply_gate_cooling_hours', $current['reply_gate_cooling_hours'] ?? 24) }}" min="1" max="720"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                    </div>
                </div>
            </div>

            <div class="mt-6 grid gap-6 md:grid-cols-2">
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sender daily limit</label>
                        <input type="number" name="max_messages_per_day_per_sender" value="{{ old('max_messages_per_day_per_sender', $current['max_messages_per_day_per_sender'] ?? 20) }}" min="1" max="500"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sender weekly limit</label>
                        <input type="number" name="max_messages_per_week_per_sender" value="{{ old('max_messages_per_week_per_sender', $current['max_messages_per_week_per_sender'] ?? 100) }}" min="1" max="5000"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sender monthly limit</label>
                        <input type="number" name="max_messages_per_month_per_sender" value="{{ old('max_messages_per_month_per_sender', $current['max_messages_per_month_per_sender'] ?? 300) }}" min="1" max="20000"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New conversations per day</label>
                        <input type="number" name="max_new_conversations_per_day" value="{{ old('max_new_conversations_per_day', $current['max_new_conversations_per_day'] ?? 10) }}" min="1" max="500"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Contact request mode</h2>
                <div class="space-y-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="contact_request_mode" value="mutual_only" {{ ($current['contact_request_mode'] ?? '') === 'mutual_only' ? 'checked' : '' }}>
                        <span class="text-sm">After accepted interest — request allowed only after the receiver accepts your interest</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="contact_request_mode" value="direct_allowed" {{ ($current['contact_request_mode'] ?? '') === 'direct_allowed' ? 'checked' : '' }}>
                        <span class="text-sm">After accepted interest — legacy option kept for backward compatibility</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="contact_request_mode" value="disabled" {{ ($current['contact_request_mode'] ?? '') === 'disabled' ? 'checked' : '' }}>
                        <span class="text-sm">Disabled — contact request system off</span>
                    </label>
                </div>
            </div>

            <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Cooldown & expiry</h2>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reject cooldown (days)</label>
                        <input type="number" name="reject_cooldown_days" value="{{ old('reject_cooldown_days', $current['reject_cooldown_days'] ?? 90) }}" min="7" max="365"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                        <p class="text-xs text-gray-500 mt-1">7–365. Days before sender can request same receiver again after reject.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Pending expiry (days)</label>
                        <input type="number" name="pending_expiry_days" value="{{ old('pending_expiry_days', $current['pending_expiry_days'] ?? 7) }}" min="1" max="30"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                        <p class="text-xs text-gray-500 mt-1">1–30. Days after which an unanswered request expires.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Max requests per sender per day (0 = no limit)</label>
                        <input type="number" name="max_requests_per_day_per_sender" value="{{ old('max_requests_per_day_per_sender', $current['max_requests_per_day_per_sender'] ?? '') }}" min="0"
                            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" placeholder="0 = no limit">
                    </div>
                </div>
            </div>
        </div>

        <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Grant duration options (receiver can choose)</h2>
            <div class="flex flex-wrap gap-6">
                <label class="flex items-center gap-2">
                    <input type="hidden" name="grant_approve_once" value="0">
                    <input type="checkbox" name="grant_approve_once" value="1" {{ ($current['grant_approve_once'] ?? true) ? 'checked' : '' }}>
                    <span class="text-sm">Approve once (24h)</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="hidden" name="grant_approve_7_days" value="0">
                    <input type="checkbox" name="grant_approve_7_days" value="1" {{ ($current['grant_approve_7_days'] ?? true) ? 'checked' : '' }}>
                    <span class="text-sm">7 days</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="hidden" name="grant_approve_30_days" value="0">
                    <input type="checkbox" name="grant_approve_30_days" value="1" {{ ($current['grant_approve_30_days'] ?? true) ? 'checked' : '' }}>
                    <span class="text-sm">30 days</span>
                </label>
            </div>
        </div>

        <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Allowed contact scopes</h2>
            <div class="flex flex-wrap gap-6">
                <label class="flex items-center gap-2">
                    <input type="hidden" name="scope_email" value="0">
                    <input type="checkbox" name="scope_email" value="1" {{ ($current['scope_email'] ?? true) ? 'checked' : '' }}>
                    <span class="text-sm">Email</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="hidden" name="scope_phone" value="0">
                    <input type="checkbox" name="scope_phone" value="1" {{ ($current['scope_phone'] ?? true) ? 'checked' : '' }}>
                    <span class="text-sm">Phone</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="hidden" name="scope_whatsapp" value="0">
                    <input type="checkbox" name="scope_whatsapp" value="1" {{ ($current['scope_whatsapp'] ?? true) ? 'checked' : '' }}>
                    <span class="text-sm">WhatsApp</span>
                </label>
            </div>
        </div>

        <div class="pt-2">
            <button type="submit" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-md font-semibold">
                Save &amp; log to audit
            </button>
        </div>
    </form>
</div>
@endsection
