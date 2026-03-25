@props([
    'message',
    'isMine' => false,
    'senderPhotoUrl' => null,
])

@php
    $bubble = $isMine
        ? 'bg-emerald-600/10 text-emerald-950 ring-emerald-200/60 dark:bg-emerald-400/10 dark:text-emerald-50 dark:ring-emerald-900/40'
        : 'bg-white text-gray-900 ring-gray-200/70 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700/70';
    $wrap = $isMine ? 'justify-end' : 'justify-start';
    $avatarUrl = $senderPhotoUrl ?: asset('images/placeholders/default-profile.svg');
@endphp

<div class="flex {{ $wrap }}">
    @if (! $isMine)
        <img src="{{ $avatarUrl }}" alt="Sender photo" class="mr-2 h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-black/10" />
    @endif

    <div class="max-w-[85%] rounded-2xl px-4 py-2 shadow-sm ring-1 {{ $bubble }}">
        @if (($message->message_type ?? 'text') === \App\Models\Message::TYPE_IMAGE && $message->image_path)
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
                <p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $message->body_text }}</p>
            @endif
        @else
            <p class="whitespace-pre-wrap break-words text-sm">{{ $message->body_text }}</p>
        @endif

        <div class="mt-1 flex items-center justify-end gap-2 text-[11px] text-gray-500 dark:text-gray-400">
            <span class="tabular-nums">{{ optional($message->sent_at)->format('H:i') }}</span>
            @if ($isMine)
                <span>{{ ($message->delivery_status ?? 'sent') === \App\Models\Message::DELIVERY_READ ? 'Read' : 'Sent' }}</span>
            @endif
        </div>
    </div>
</div>

