@extends('layouts.app')

@section('content')
@php
    $statusKey = 'help_centre.status_' . $ticket->status;
    $ticketLabel = $ticket->ticket_code
        ? __('help_centre.ticket_prefix') . ': ' . $ticket->ticket_code
        : __('help_centre.intent_prefix') . ': ' . str_replace('_', ' ', (string) $ticket->intent);
    $statusClass = $ticket->status === 'open'
        ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100'
        : ($ticket->status === 'resolved'
            ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100'
            : 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-100');
@endphp

<div class="mx-auto max-w-3xl px-4 py-6">
    <div class="mb-3">
        <a href="{{ route('help-centre.index') }}" class="text-sm font-semibold text-indigo-700 hover:underline dark:text-indigo-300">
            {{ __('help_centre.back_to_help_centre') }}
        </a>
    </div>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <header class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-950 dark:text-gray-50">{{ $ticketLabel }}</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $ticket->created_at?->toDayDateTimeString() }}</p>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">
                    {{ __($statusKey) }}
                </span>
            </div>
        </header>

        <div class="space-y-4 px-5 py-5">
            @if ($ticket->status === 'open')
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                    {{ __('help_centre.open_request_note') }}
                </div>
            @elseif ($ticket->status === 'auto_resolved')
                <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm leading-6 text-sky-950 dark:border-sky-900/60 dark:bg-sky-950/30 dark:text-sky-100">
                    {{ __('help_centre.answered_by_bot_note') }}
                </div>
            @endif

            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('help_centre.your_question') }}</p>
                <div class="mt-2 rounded-2xl rounded-tr-md bg-indigo-600 px-4 py-3 text-sm leading-6 text-white">
                    {{ $ticket->query_text }}
                </div>
            </div>

            @if (filled($ticket->bot_reply))
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('help_centre.reply') }}</p>
                    <div class="mt-2 rounded-2xl rounded-tl-md bg-gray-50 px-4 py-3 text-sm leading-6 text-gray-800 ring-1 ring-gray-200 dark:bg-gray-950 dark:text-gray-100 dark:ring-gray-800">
                        {{ $ticket->bot_reply }}
                    </div>
                </div>
            @endif

            <dl class="grid gap-3 rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-800 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('help_centre.status_label') }}</dt>
                    <dd class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ __($statusKey) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('help_centre.created_label') }}</dt>
                    <dd class="mt-1 text-gray-700 dark:text-gray-200">{{ $ticket->created_at?->diffForHumans() }}</dd>
                </div>
                @if ($ticket->workflow?->first_response_due_at && $ticket->status === 'open')
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('help_centre.expected_response_label') }}</dt>
                        <dd class="mt-1 text-gray-700 dark:text-gray-200">{{ $ticket->workflow->first_response_due_at->diffForHumans() }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </section>
</div>
@endsection
