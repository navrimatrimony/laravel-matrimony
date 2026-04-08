@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-6">
    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">Chat</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Your conversations</p>
        </div>
        <a href="{{ route('matrimony.profiles.index') }}" class="shrink-0 rounded-xl border border-indigo-200 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50 dark:border-indigo-800/60 dark:bg-gray-900 dark:text-indigo-200 dark:hover:bg-indigo-950/30">Discover profiles</a>
    </div>

    @php
        $active = $tab ?? 'all';
        $counts = $counts ?? ['all' => 0, 'unread' => 0, 'requests' => 0];
        $tabs = [
            'all' => ['label' => 'All', 'count' => (int) ($counts['all'] ?? 0)],
            'unread' => ['label' => 'Unread', 'count' => (int) ($counts['unread'] ?? 0)],
            'requests' => ['label' => 'Requests', 'count' => (int) ($counts['requests'] ?? 0)],
        ];
    @endphp

    <div class="mt-6">
        <div class="inline-flex rounded-xl border border-gray-200 bg-white p-1 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            @foreach ($tabs as $key => $t)
                <a
                    href="{{ route('chat.index', ['tab' => $key]) }}"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition {{ $active === $key ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800' }}"
                >
                    <span>{{ $t['label'] }}</span>
                    <span class="inline-flex min-w-[1.5rem] items-center justify-center rounded-full px-2 py-0.5 text-xs font-bold {{ $active === $key ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' }}">
                        {{ $t['count'] }}
                    </span>
                </a>
            @endforeach
        </div>

        <div class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @php $rows = $conversations ?? collect(); @endphp
                @forelse ($rows as $c)
                    @php
                        /** @var \App\Models\MatrimonyProfile|null $other */
                        $other = $c->getRelation('other_profile');
                        $unreadCount = (int) ($c->getAttribute('unread_count') ?? 0);
                        $title = $other?->full_name ?: ('Profile #' . ($other?->id ?? ''));
                        $photo = $other?->profile_photo_url ?? asset('images/placeholders/default-profile.svg');
                        $last = $c->lastMessage;
                        $preview = '';
                        if ($last) {
                            $preview = (string) ($last->body_text ?? '');
                            if (($last->message_type ?? \App\Models\Message::TYPE_TEXT) === \App\Models\Message::TYPE_IMAGE) {
                                $cap = trim((string) ($last->body_text ?? ''));
                                $preview = $cap !== '' ? ('📷 ' . $cap) : '📷 Image';
                            }
                        }
                    @endphp

                    <a href="{{ route('chat.show', ['conversation' => $c->id, 'tab' => $active]) }}" class="block px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/60 {{ $unreadCount > 0 ? 'bg-emerald-50/70 dark:bg-emerald-950/20' : '' }}">
                        <div class="flex min-w-0 items-center gap-3">
                            <img src="{{ $photo }}" alt="{{ $title }}" class="h-11 w-11 shrink-0 rounded-full object-cover ring-1 ring-black/10" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="truncate text-sm {{ $unreadCount > 0 ? 'font-extrabold text-gray-900 dark:text-gray-100' : 'font-semibold text-gray-900 dark:text-gray-100' }}">{{ $title }}</p>
                                    <p class="shrink-0 text-[11px] text-gray-500 dark:text-gray-400 tabular-nums">
                                        @if ($c->last_message_at)
                                            {{ $c->last_message_at->isToday() ? $c->last_message_at->format('H:i') : $c->last_message_at->format('M j') }}
                                        @else
                                            —
                                        @endif
                                    </p>
                                </div>
                                <div class="mt-0.5 flex min-w-0 items-center justify-between gap-2">
                                    <p class="truncate text-xs {{ $unreadCount > 0 ? 'font-semibold text-gray-700 dark:text-gray-200' : 'text-gray-500 dark:text-gray-400' }}">
                                        {{ $preview !== '' ? $preview : 'No messages yet' }}
                                    </p>
                                    @if ($unreadCount > 0)
                                        <span class="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-rose-600 px-2 text-[11px] font-bold text-white">{{ $unreadCount }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-4 py-10 text-center">
                        @if ($active === 'unread')
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">No unread chats.</p>
                        @elseif ($active === 'requests')
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">No chat requests.</p>
                        @else
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">No chats yet.</p>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Discover profiles and start a conversation.</p>
                            <a href="{{ route('matrimony.profiles.index') }}" class="mt-4 inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Discover profiles</a>
                        @endif
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

