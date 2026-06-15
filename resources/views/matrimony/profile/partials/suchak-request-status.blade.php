@php
    $requestMessage = trim((string) ($existingSuchakRequest->message ?? ''));
    $replyMessage = trim((string) ($existingSuchakRequest->chatMessage?->body_text ?? ''));
    $statusKey = 'profile.suchak_request_status_'.$existingSuchakRequest->request_status;
    $statusLabel = \Illuminate\Support\Facades\Lang::has($statusKey)
        ? __($statusKey)
        : \Illuminate\Support\Str::headline((string) $existingSuchakRequest->request_status);
@endphp

<div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-stone-700 dark:border-amber-900/60 dark:bg-amber-950/25 dark:text-stone-200">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="font-semibold text-amber-900 dark:text-amber-100">{{ __('profile.suchak_contact_pending_help') }}</p>
            <p class="mt-1 text-xs text-stone-500 dark:text-stone-400">
                {{ __('profile.suchak_request_sent_at', ['date' => $existingSuchakRequest->created_at?->format('d M Y, h:i A') ?: '-']) }}
            </p>
        </div>
        <span class="inline-flex w-fit items-center justify-center rounded-full bg-white px-3 py-1 text-xs font-semibold text-amber-900 shadow-sm dark:bg-amber-900/35 dark:text-amber-100">
            {{ $statusLabel }}
        </span>
    </div>

    @if ($requestMessage !== '')
        <div class="mt-3 rounded-lg border border-amber-100 bg-white/80 px-3 py-2 dark:border-amber-900/50 dark:bg-gray-900/50">
            <p class="text-xs font-semibold uppercase text-stone-500 dark:text-stone-400">{{ __('profile.suchak_request_your_message') }}</p>
            <p class="mt-1 whitespace-pre-line leading-relaxed">{{ $requestMessage }}</p>
        </div>
    @endif

    @if ($replyMessage !== '')
        <div class="mt-3 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 dark:border-emerald-900/60 dark:bg-emerald-950/25">
            <p class="text-xs font-semibold uppercase text-emerald-700 dark:text-emerald-200">
                {{ __('profile.suchak_request_reply_heading') }}
                @if ($existingSuchakRequest->replied_at)
                    <span class="font-normal normal-case text-stone-500 dark:text-stone-400">
                        {{ __('profile.suchak_request_replied_at', ['date' => $existingSuchakRequest->replied_at->format('d M Y, h:i A')]) }}
                    </span>
                @endif
            </p>
            <p class="mt-1 whitespace-pre-line leading-relaxed text-stone-800 dark:text-stone-100">{{ $replyMessage }}</p>
            @if ($existingSuchakRequest->chatConversation)
                <a href="{{ route('chat.show', $existingSuchakRequest->chatConversation) }}" class="mt-3 inline-flex rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                    {{ __('profile.suchak_request_open_chat') }}
                </a>
            @endif
        </div>
    @endif
</div>
