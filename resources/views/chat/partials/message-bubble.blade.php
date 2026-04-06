@props([
    'message',
    'isMine' => false,
    'senderPhotoUrl' => null,
    'viewerProfileId' => 0,
    'readLockedForIncoming' => false,
])

@php
    $bubble = $isMine
        ? 'bg-emerald-600/10 text-emerald-950 ring-emerald-200/60 dark:bg-emerald-400/10 dark:text-emerald-50 dark:ring-emerald-900/40'
        : 'bg-white text-gray-900 ring-gray-200/70 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700/70';
    $wrap = $isMine ? 'justify-end' : 'justify-start';
    $avatarUrl = $senderPhotoUrl ?: asset('images/placeholders/default-profile.svg');
    $mod = app(\App\Services\Chat\ChatMessageModerationService::class);
    $lockedIncoming = $readLockedForIncoming && ! $isMine;
    $display = $lockedIncoming
        ? ['text' => (string) __('chat_ui.read_locked_body')]
        : $mod->bodyTextForViewer($message, (int) $viewerProfileId, false);
@endphp

<div class="flex {{ $wrap }}" data-message-id="{{ (int) ($message->id ?? 0) }}">
    @if (! $isMine)
        <img src="{{ $avatarUrl }}" alt="Sender photo" class="mr-2 h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-black/10" />
    @endif

    <div class="max-w-[85%] rounded-2xl px-4 py-2 shadow-sm ring-1 {{ $bubble }}">
        @if ($lockedIncoming)
            <div
                class="chat-read-lock-card rounded-xl border border-amber-200/90 bg-gradient-to-br from-amber-50 via-white to-indigo-50/60 p-3 shadow-sm dark:border-amber-800/50 dark:from-amber-950/40 dark:via-gray-900 dark:to-indigo-950/30"
                role="group"
                aria-label="{{ __('chat_ui.read_locked_new_message') }}"
            >
                <div class="flex items-start gap-2">
                    <span class="text-lg leading-none" aria-hidden="true">🔒</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold uppercase tracking-wide text-amber-800 dark:text-amber-200">
                            <span class="mr-1.5 inline-flex rounded-md bg-amber-500/15 px-1.5 py-0.5 text-[10px] text-amber-900 dark:bg-amber-400/20 dark:text-amber-100">{{ __('chat_ui.read_lock_premium_badge') }}</span>
                            {{ __('chat_ui.read_locked_new_message') }}
                        </p>
                        <div class="chat-read-lock-blur mt-2 select-none rounded-lg border border-dashed border-gray-200/80 bg-gray-100/80 px-2 py-2 dark:border-gray-600/60 dark:bg-gray-800/50">
                            <div class="space-y-1.5 opacity-60">
                                <div class="h-2 w-full rounded bg-gray-300/90 blur-[3px] dark:bg-gray-600/80"></div>
                                <div class="h-2 w-4/5 rounded bg-gray-300/90 blur-[3px] dark:bg-gray-600/80"></div>
                                <div class="h-2 w-3/5 rounded bg-gray-300/90 blur-[3px] dark:bg-gray-600/80"></div>
                            </div>
                        </div>
                        <p class="mt-2 text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('chat_ui.read_locked_subline') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <a
                                href="{{ route('plans.index') }}"
                                class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-3 py-2 text-xs font-bold text-white shadow-md ring-1 ring-indigo-500/30 transition hover:from-indigo-500 hover:to-violet-500"
                            >
                                {{ __('chat_ui.read_locked_upgrade_now') }}
                            </a>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-800 shadow-sm transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:hover:bg-gray-800"
                                data-open-upgrade-lock-modal="upgrade-modal-chat-read"
                                aria-label="{{ __('upgrade_nudge.chat_read_aria') }}"
                            >
                                {{ __('chat_ui.read_locked_compare_plans') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @elseif (($message->message_type ?? 'text') === \App\Models\Message::TYPE_IMAGE && $message->image_path)
            <a href="{{ route('chat.messages.image.show', ['message' => $message->id]) }}" target="_blank" rel="noopener" class="block">
                <img
                    src="{{ route('chat.messages.image.show', ['message' => $message->id]) }}"
                    alt="Image message"
                    class="max-h-64 w-full max-w-[18rem] rounded-xl object-cover"
                    onerror="this.style.display='none'; const n=this.nextElementSibling; if(n){ n.classList.remove('hidden'); }"
                />
                <div class="hidden mt-2 text-xs text-gray-500 dark:text-gray-400">प्रतिमा उपलब्ध नाही</div>
            </a>
            @if (($message->body_text ?? '') !== '')
                <p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $display['text'] }}</p>
            @endif
        @else
            <p class="whitespace-pre-wrap break-words text-sm">{{ $display['text'] }}</p>
        @endif

        <div class="mt-1 flex items-center justify-end gap-2 text-[11px] text-gray-500 dark:text-gray-400">
            <span class="tabular-nums" title="{{ $message->sent_at?->toIso8601String() }}">{{ $message->sent_at?->timezone(config('app.timezone'))->format('H:i') }}</span>
            @if ($isMine)
                <span>{{ ($message->delivery_status ?? 'sent') === \App\Models\Message::DELIVERY_READ ? 'Read' : 'Sent' }}</span>
            @endif
        </div>
    </div>
</div>
