@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Showcase chat debug</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Conversation #{{ $conversation->id }} • Runtime snapshot (not stored)</p>
        </div>
        <a href="{{ route('admin.showcase-conversations.show', ['conversation' => $conversation->id]) }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700">← Back to conversation</a>
    </div>

    @if (!empty($snapshot['error']))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
            {{ $snapshot['error'] }}
        </div>
    @else
        <div class="space-y-6">
            <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">1) Conversation info</h2>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr><td class="py-1 text-gray-500 w-48">Conversation ID</td><td class="font-mono">{{ $snapshot['conversation_id'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Showcase profile ID</td><td class="font-mono">{{ $snapshot['profile_id'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Showcase</td><td>{{ $showcase->full_name ?: ('#'.$showcase->id) }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Other participant</td><td>{{ $other?->full_name ?: ('#'.$other?->id) }}</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">2) Timeline state</h2>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr><td class="py-1 text-gray-500 w-48">Pending read</td><td class="font-mono text-xs">{{ $snapshot['pending_read_at'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Pending typing</td><td class="font-mono text-xs">{{ $snapshot['pending_typing_at'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Pending reply</td><td class="font-mono text-xs">{{ $snapshot['pending_reply_at'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Pending offline</td><td class="font-mono text-xs">{{ $snapshot['pending_offline_at'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Unanswered incoming</td><td>{{ $snapshot['unanswered_incoming_count'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Last incoming</td><td class="font-mono text-xs">{{ $snapshot['last_incoming_at'] ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">3) Probability breakdown</h2>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr><td class="py-1 text-gray-500 w-48">Base (settings)</td><td>{{ $snapshot['base_probability'] ?? '—' }}%</td></tr>
                        <tr><td class="py-1 text-gray-500">Fatigue stack (count rules)</td><td>{{ $snapshot['fatigue_penalty'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Spam gap (&lt; 2 min)</td><td>{{ $snapshot['spam_penalty'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">Personality modifier</td><td>{{ $snapshot['personality_modifier'] ?? '—' }}</td></tr>
                        <tr><td class="py-1 text-gray-500">6+ cap</td><td>
                            @if (!empty($snapshot['blocked_by_unanswered_cap']))
                                <span class="text-red-600 font-semibold">Yes</span>
                            @else
                                <span class="text-gray-600">No</span>
                            @endif
                        </td></tr>
                        <tr><td class="py-1 text-gray-500 font-semibold">Final (before random roll)</td><td class="font-semibold">{{ $snapshot['final_probability'] ?? '—' }}%</td></tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Automated replies still apply a random roll against final probability when scheduling.</p>
            </section>

            <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">4) Lock status</h2>
                <p class="text-sm mb-2">
                    Active lock:
                    @if (!empty($snapshot['is_active_lock']))
                        <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Yes</span>
                    @else
                        <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-700 dark:bg-gray-600/40 dark:text-gray-200">No</span>
                    @endif
                </p>
                <p class="text-xs font-mono text-gray-700 dark:text-gray-300">{{ $snapshot['active_lock_until'] ?? '—' }}</p>
            </section>

            <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">5) Decision</h2>
                <p class="text-sm mb-2">
                    Can reply now (gates + final &gt; 0):
                    @if (!empty($snapshot['can_reply_now']))
                        <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Yes</span>
                    @else
                        <span class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-800 dark:bg-red-900/40 dark:text-red-200">No</span>
                    @endif
                </p>
                @if (!empty($snapshot['blocked_reason']))
                    <p class="text-sm text-red-700 dark:text-red-300">{{ $snapshot['blocked_reason'] }}</p>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-400">No blocking reasons recorded for this snapshot.</p>
                @endif
            </section>
        </div>
    @endif
</div>
@endsection
