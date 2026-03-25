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

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <aside class="lg:col-span-1">
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Inbox</p>
                        <div class="flex flex-wrap items-center gap-1">
                            @php $f = $activeFilter ?? 'all'; @endphp
                            @foreach ([
                                'all' => 'All',
                                'unread' => 'Unread',
                                'awaiting_me' => 'Awaiting Your Reply',
                                'awaiting_them' => 'Awaiting Their Reply',
                            ] as $key => $label)
                                <a
                                    href="{{ route('chat.index', ['filter' => $key]) }}"
                                    class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $f === $key ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' }}"
                                >
                                    {{ $label }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($items as $item)
                        @php
                            $c = $item['conversation'];
                            $other = $item['other'];
                            $unread = (int) ($item['unread'] ?? 0);
                            $title = $other?->full_name ?: ('Profile #' . ($other?->id ?? ''));
                            $photo = $other?->profile_photo_url ?? asset('images/placeholders/default-profile.svg');
                            $preview = trim((string) ($item['last_preview'] ?? ''));
                            $highlight = $unread > 0 ? 'bg-emerald-50/70 dark:bg-emerald-950/20' : '';
                            $label = '';
                            if (!empty($item['awaiting_me'])) $label = 'Reply pending';
                            elseif (!empty($item['awaiting_them'])) $label = 'Waiting';
                        @endphp
                        <a href="{{ route('chat.show', ['conversation' => $c->id, 'filter' => ($activeFilter ?? 'all')]) }}" class="block px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800/60 {{ $highlight }}">
                            <div class="flex min-w-0 items-center gap-3">
                                <img src="{{ $photo }}" alt="{{ $title }}" class="h-11 w-11 shrink-0 rounded-full object-cover ring-1 ring-black/10" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="truncate text-sm {{ $unread > 0 ? 'font-extrabold text-gray-900 dark:text-gray-100' : 'font-semibold text-gray-900 dark:text-gray-100' }}">{{ $title }}</p>
                                        <p class="shrink-0 text-[11px] text-gray-500 dark:text-gray-400 tabular-nums">
                                            @if ($c->last_message_at)
                                                {{ $c->last_message_at->isToday() ? $c->last_message_at->format('H:i') : $c->last_message_at->format('M j') }}
                                            @else
                                                —
                                            @endif
                                        </p>
                                    </div>
                                    <div class="mt-0.5 flex min-w-0 items-center justify-between gap-2">
                                        <p class="truncate text-xs {{ $unread > 0 ? 'font-semibold text-gray-700 dark:text-gray-200' : 'text-gray-500 dark:text-gray-400' }}">
                                            {{ $preview !== '' ? $preview : 'No messages yet' }}
                                        </p>
                                        @if ($label !== '')
                                            <span class="ml-2 shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $label }}</span>
                                        @endif
                                        @if ($unread > 0)
                                            <span class="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-rose-600 px-2 text-[11px] font-bold text-white">{{ $unread }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </a>
                    @empty
                        <div class="px-4 py-8 text-center">
                            @if (($activeFilter ?? 'all') === 'unread')
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">नवीन unread messages नाहीत.</p>
                            @elseif (($activeFilter ?? 'all') === 'awaiting_me')
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">सध्या तुमच्या reply ची वाट पाहणारा कोणताही chat नाही.</p>
                            @elseif (($activeFilter ?? 'all') === 'awaiting_them')
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">तुमच्या message च्या reply ची वाट पाहणारे chats येथे दिसतील.</p>
                            @else
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">अजून कोणताही chat सुरू झालेला नाही.</p>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">प्रोफाइल्स पहा आणि chat सुरू करा.</p>
                                <a href="{{ route('matrimony.profiles.index') }}" class="mt-4 inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">प्रोफाइल्स पहा</a>
                            @endif
                        </div>
                    @endforelse
                </div>
            </div>
        </aside>

        <section class="lg:col-span-2">
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <p class="text-sm font-semibold">Select a chat to start conversation</p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Your messages will appear here.</p>
            </div>
        </section>
    </div>
</div>
@endsection

