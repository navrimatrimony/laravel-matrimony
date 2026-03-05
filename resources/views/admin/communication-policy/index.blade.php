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

        <div class="grid gap-6 md:grid-cols-2">
            <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Contact request mode</h2>
                <div class="space-y-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="contact_request_mode" value="mutual_only" {{ ($current['contact_request_mode'] ?? '') === 'mutual_only' ? 'checked' : '' }}>
                        <span class="text-sm">Mutual only — request allowed only after mutual interest</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="contact_request_mode" value="direct_allowed" {{ ($current['contact_request_mode'] ?? '') === 'direct_allowed' ? 'checked' : '' }}>
                        <span class="text-sm">Direct allowed — request allowed without mutual interest</span>
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
