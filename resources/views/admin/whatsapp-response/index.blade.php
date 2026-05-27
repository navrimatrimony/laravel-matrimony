@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">WhatsApp Response Requests</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Manual simulation controls for WhatsApp Response delivery states. No WhatsApp API messages are sent from this page.
                </p>
            </div>
            <form method="POST" action="{{ route('admin.whatsapp-response.run-pipeline-update') }}">
                @csrf
                <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-900 dark:bg-gray-700 dark:hover:bg-gray-600">
                    Run due pipeline update
                </button>
            </form>
        </div>

        @if (session('success'))
            <p class="mt-4 text-sm text-green-600 dark:text-green-400">{{ session('success') }}</p>
        @endif
        @if (session('error'))
            <p class="mt-4 text-sm text-red-600 dark:text-red-400">{{ session('error') }}</p>
        @endif
        @if ($errors->any())
            <ul class="mt-4 space-y-1 text-sm text-red-600 dark:text-red-400">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Provider status</h2>
        <div class="mt-3 grid gap-3 text-sm md:grid-cols-3">
            <div class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                <div class="text-xs font-semibold uppercase text-gray-500">Configured provider</div>
                <div class="mt-1 text-gray-900 dark:text-gray-100">{{ $providerStatus['configured_provider'] ?? 'null' }}</div>
            </div>
            <div class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                <div class="text-xs font-semibold uppercase text-gray-500">Active provider</div>
                <div class="mt-1 text-gray-900 dark:text-gray-100">{{ $providerStatus['active_provider'] ?? 'null' }}</div>
            </div>
            <div class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                <div class="text-xs font-semibold uppercase text-gray-500">Live send enabled</div>
                <div class="mt-1 text-gray-900 dark:text-gray-100">{{ ! empty($providerStatus['live_send_enabled']) ? 'yes' : 'no' }}</div>
            </div>
            <div class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                <div class="text-xs font-semibold uppercase text-gray-500">Meta core config</div>
                <div class="mt-1 text-gray-900 dark:text-gray-100">{{ ! empty($providerStatus['meta_core_configured']) ? 'present' : 'missing' }}</div>
            </div>
            <div class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                <div class="text-xs font-semibold uppercase text-gray-500">Template config</div>
                <div class="mt-1 text-gray-900 dark:text-gray-100">{{ ! empty($providerStatus['engagement_template_configured']) ? 'present' : 'missing' }}</div>
            </div>
            <div class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                <div class="text-xs font-semibold uppercase text-gray-500">Send action</div>
                <div class="mt-1 text-gray-900 dark:text-gray-100">not available on this page</div>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Provider status does not print tokens or send WhatsApp messages.</p>
    </div>

    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">WhatsApp Response Settings</h2>
        <form method="POST" action="{{ route('admin.whatsapp-response.settings.update') }}" class="mt-4 space-y-5">
            @csrf
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/20">
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200">Reason for change</label>
                <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
                    <input type="checkbox" name="whatsapp_response_enabled" value="1" @checked($settings['enabled'])>
                    <span>WhatsApp Response enabled</span>
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
                    <input type="checkbox" name="whatsapp_response_allow_manual_send" value="1" @checked($settings['allow_manual_send'])>
                    <span>Allow manual mark as sent</span>
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
                    <input type="checkbox" name="whatsapp_response_allow_manual_reminder" value="1" @checked($settings['allow_manual_reminder'])>
                    <span>Allow manual reminder sent</span>
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
                    <input type="checkbox" name="whatsapp_response_photo_in_summary" value="1" @checked($settings['photo_in_summary'])>
                    <span>Photo in summary</span>
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100">
                    <input type="checkbox" name="whatsapp_response_profile_link_enabled" value="1" @checked($settings['profile_link_enabled'])>
                    <span>Profile link enabled</span>
                </label>
            </div>

            <div class="grid gap-4 md:grid-cols-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Channel mode</label>
                    <select name="whatsapp_response_channel_mode" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        @foreach ($channelModes as $mode)
                            <option value="{{ $mode }}" @selected($settings['channel_mode'] === $mode)>{{ $mode }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">First reminder after hours</label>
                    <input type="number" name="whatsapp_response_first_reminder_hours" min="1" max="168" value="{{ $settings['first_reminder_hours'] }}" class="mt-1 w-32 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Expire after hours</label>
                    <input type="number" name="whatsapp_response_expire_hours" min="1" max="720" value="{{ $settings['expire_hours'] }}" class="mt-1 w-32 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Same profile cooldown days</label>
                    <input type="number" name="whatsapp_response_request_cooldown_days" min="0" max="365" value="{{ $settings['request_cooldown_days'] }}" class="mt-1 w-32 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Same sender cannot request the same profile again until this window ends. Default 30 days.</p>
                </div>
            </div>

            <button type="submit" class="rounded-md bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Save settings
            </button>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Filters</h2>
        <form method="GET" action="{{ route('admin.whatsapp-response.index') }}" class="mt-4 grid gap-3 md:grid-cols-6">
            <select name="delivery_status" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">Delivery status</option>
                @foreach ($deliveryStatuses as $status)
                    <option value="{{ $status }}" @selected(($filters['delivery_status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">Business status</option>
                @foreach ($businessStatuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <select name="channel_mode" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">Channel mode</option>
                @foreach ($channelModes as $mode)
                    <option value="{{ $mode }}" @selected(($filters['channel_mode'] ?? '') === $mode)>{{ $mode }}</option>
                @endforeach
            </select>
            <select name="quick" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <option value="">Quick filter</option>
                @foreach (['pending', 'reminder_due', 'expired', 'responded'] as $quick)
                    <option value="{{ $quick }}" @selected(($filters['quick'] ?? '') === $quick)>{{ $quick }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <div class="flex gap-2">
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <button type="submit" class="rounded-md bg-gray-800 px-3 py-2 text-sm font-semibold text-white">Filter</button>
            </div>
        </form>
    </div>

    <div class="overflow-hidden bg-white dark:bg-gray-800 shadow rounded-lg">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Request</th>
                        <th class="px-4 py-3">People</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Timeline</th>
                        <th class="px-4 py-3">Response metadata</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($requests as $requestRow)
                        @php
                            $effective = $requestRow->effectiveDeliveryStatus();
                            $metadata = $requestRow->meta['matchmaking'] ?? [];
                            $senderName = $requestRow->senderProfile?->full_name ?? $requestRow->sender?->matrimonyProfile?->full_name ?? $requestRow->sender?->name ?? 'Unknown';
                            $receiverName = $requestRow->receiverProfile?->full_name ?? $requestRow->receiver?->matrimonyProfile?->full_name ?? $requestRow->receiver?->name ?? 'Unknown';
                            $isClosed = $requestRow->hasResponded() || $requestRow->isDeliveryExpired() || $requestRow->delivery_status === \App\Models\MediationRequest::DELIVERY_CANCELLED;
                            $payloadPreview = $payloadPreviews[$requestRow->id] ?? [];
                        @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3 text-gray-800 dark:text-gray-100">
                                <div class="font-semibold">#{{ $requestRow->id }}</div>
                                <div class="text-xs text-gray-500">{{ $requestRow->created_at?->format('Y-m-d H:i') }}</div>
                                <div class="mt-1 text-xs text-gray-500">{{ $requestRow->channel_mode ?? 'manual_simulation' }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                                <div><span class="text-xs text-gray-500">Sender:</span> {{ $senderName }}</div>
                                <div><span class="text-xs text-gray-500">Receiver:</span> {{ $receiverName }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-gray-800 dark:text-gray-100">Business: {{ $requestRow->status }}</div>
                                <div class="mt-1 inline-flex rounded-full bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                                    {{ __('mediation.delivery_'.$effective) }}
                                </div>
                                <div class="mt-1 text-xs text-gray-500">Stored: {{ $requestRow->delivery_status ?? 'pending' }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
                                <div>sent_at: {{ $requestRow->sent_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                <div>reminder_due: {{ $requestRow->first_reminder_due_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                <div>reminder_sent: {{ $requestRow->first_reminder_sent_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                <div>expires_at: {{ $requestRow->expires_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                <div>expired_at: {{ $requestRow->expired_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                <div>responded_at: {{ $requestRow->responded_at?->format('Y-m-d H:i') ?? '-' }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
                                <div>choice: {{ $metadata['receiver_choice'] ?? '-' }}</div>
                                <div>reason: {{ $metadata['receiver_decline_reason'] ?? '-' }}</div>
                                <div>reason note: {{ $metadata['receiver_decline_reason_note'] ?? '-' }}</div>
                                <div>next action: {{ $metadata['receiver_next_action'] ?? '-' }}</div>
                                <details class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-2 dark:border-gray-700 dark:bg-gray-900/40">
                                    <summary class="cursor-pointer font-semibold text-gray-700 dark:text-gray-200">Future WhatsApp message preview</summary>
                                    <p class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">Preview only. No WhatsApp API call is made.</p>
                                    <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-white p-2 text-[11px] text-gray-700 dark:bg-gray-950 dark:text-gray-200">{{ json_encode($payloadPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex min-w-44 flex-col gap-2">
                                    @if (! $isClosed && $settings['allow_manual_send'])
                                        <form method="POST" action="{{ route('admin.whatsapp-response.action', $requestRow) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="mark_sent">
                                            <button type="submit" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Mark as sent</button>
                                        </form>
                                    @endif
                                    @if (! $isClosed && $settings['allow_manual_reminder'])
                                        <form method="POST" action="{{ route('admin.whatsapp-response.action', $requestRow) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="mark_reminder_sent">
                                            <button type="submit" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Mark reminder sent</button>
                                        </form>
                                    @endif
                                    @if (! $requestRow->hasResponded() && ! $requestRow->isDeliveryExpired())
                                        <form method="POST" action="{{ route('admin.whatsapp-response.action', $requestRow) }}" onsubmit="return confirm('Expire this WhatsApp Response request?');">
                                            @csrf
                                            <input type="hidden" name="action" value="expire">
                                            <button type="submit" class="w-full rounded-md border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/30">Expire</button>
                                        </form>
                                    @endif
                                    @if (! $isClosed)
                                        <form method="POST" action="{{ route('admin.whatsapp-response.action', $requestRow) }}" onsubmit="return confirm('Cancel this WhatsApp Response request?');">
                                            @csrf
                                            <input type="hidden" name="action" value="cancel">
                                            <button type="submit" class="w-full rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                                        </form>
                                    @endif
                                    @if ($isClosed)
                                        <span class="text-xs text-gray-500">No manual action available.</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">No WhatsApp Response requests found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
            {{ $requests->links() }}
        </div>
    </div>
</div>
@endsection
