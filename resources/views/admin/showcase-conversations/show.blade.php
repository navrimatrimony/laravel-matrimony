@extends('layouts.admin-showcase')

@section('showcase_content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Conversation #{{ $conversation->id }}</h1>
            <p class="text-gray-500 dark:text-gray-400 text-sm">
                Showcase: <span class="font-semibold">{{ $showcase->full_name ?: ('Showcase #' . $showcase->id) }}</span>
                • Other: <span class="font-semibold">{{ $other->full_name ?: ('Profile #' . $other->id) }}</span>
            </p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
            <a href="{{ route('admin.showcase-chat.debug', ['conversation' => $conversation->id]) }}" class="text-sm font-semibold text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400">Debug</a>
            <a href="{{ route('admin.showcase-conversations.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700">← Back</a>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
        <span class="inline-flex items-center gap-2 rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700 ring-1 ring-indigo-200 dark:bg-indigo-950/30 dark:text-indigo-200 dark:ring-indigo-800/60">
            AI Assisted Replies
        </span>
        <span class="text-gray-600 dark:text-gray-300">Presence: <span class="font-semibold">{{ ($presenceStatus['online'] ?? false) ? 'Online' : 'Offline' }}</span></span>
        <span class="text-gray-600 dark:text-gray-300">Typing: <span class="font-semibold">{{ ($presenceStatus['typing'] ?? false) ? 'Yes' : 'No' }}</span></span>
        <span class="text-gray-600 dark:text-gray-300">Automation: <span class="font-semibold">{{ $state?->automation_status ?? '—' }}</span></span>
    </div>

    <div class="mt-4 grid gap-6 lg:grid-cols-3">
        <section class="lg:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">Recent messages</h2>
                <div class="space-y-2 max-h-[60vh] overflow-y-auto">
                    @foreach ($messages as $m)
                        @php
                            $adminDisp = $chatModeration->bodyTextForViewer($m, 0, true);
                            if (($m->message_type ?? '') === 'image') {
                                $adminLine = (($m->body_text ?? '') !== '') ? $adminDisp['text'] : '[Image]';
                            } else {
                                $adminLine = ($adminDisp['text'] !== '') ? $adminDisp['text'] : '—';
                            }
                        @endphp
                        <div class="rounded-xl border border-gray-100 px-3 py-2 dark:border-gray-800">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-bold text-gray-700 dark:text-gray-200">
                                    {{ ($m->sender_profile_id == $showcase->id) ? 'Showcase' : 'User' }}
                                </p>
                                <div class="flex items-center gap-2">
                                    @if (!empty($adminDisp['show_filtered_badge']))
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">{{ $chatModeration->adminBadgeLabel() }}</span>
                                    @endif
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $m->sent_at?->diffForHumans() ?? '' }}</p>
                                </div>
                            </div>
                            <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $adminLine }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <aside class="lg:col-span-1 space-y-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-2">Message display</h2>
                <div class="text-xs text-gray-600 dark:text-gray-300 space-y-1">
                    <p>Safety filter applied: <span class="font-semibold">{{ !empty($conversationHasFiltered) ? 'Yes' : 'No' }}</span></p>
                    <p>Display masked for end-users: <span class="font-semibold">{{ !empty($conversationHasFiltered) ? 'Yes' : 'No' }}</span></p>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">Pending events</h2>
                <div class="text-xs text-gray-600 dark:text-gray-300 space-y-1">
                    <p>Pending read: <span class="font-semibold">{{ $state?->pending_read_at?->toDateTimeString() ?? '—' }}</span></p>
                    <p>Pending typing: <span class="font-semibold">{{ $state?->pending_typing_at?->toDateTimeString() ?? '—' }}</span></p>
                    <p>Pending reply: <span class="font-semibold">{{ $state?->pending_reply_at?->toDateTimeString() ?? '—' }}</span></p>
                    <p>Admin takeover until: <span class="font-semibold">{{ $state?->admin_takeover_until?->toDateTimeString() ?? '—' }}</span></p>
                </div>
                <div class="mt-4 flex flex-col gap-2">
                    <form method="POST" action="{{ route('admin.showcase-conversations.pause', ['conversation' => $conversation->id]) }}">
                        @csrf
                        <input type="hidden" name="showcase_profile_id" value="{{ $showcase->id }}">
                        <button class="w-full rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Pause (admin takeover)</button>
                    </form>
                    <form method="POST" action="{{ route('admin.showcase-conversations.resume', ['conversation' => $conversation->id]) }}">
                        @csrf
                        <input type="hidden" name="showcase_profile_id" value="{{ $showcase->id }}">
                        <button class="w-full rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Resume automation</button>
                    </form>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">Reply as showcase</h2>
                <form method="POST" action="{{ route('admin.showcase-conversations.reply', ['conversation' => $conversation->id]) }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="showcase_profile_id" value="{{ $showcase->id }}">
                    <textarea name="body_text" rows="3" required class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="Type message..."></textarea>
                    <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                        <input type="hidden" name="apply_personality_tone" value="0">
                        <input type="checkbox" name="apply_personality_tone" value="1" class="rounded border-gray-300">
                        Apply showcase tone (preset from settings; optional)
                    </label>
                    <button class="w-full rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Send</button>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">This will pause pending automation for safety. Messages are stored normally (no source metadata).</p>
                </form>
            </div>
        </aside>
    </div>
</div>
@endsection

