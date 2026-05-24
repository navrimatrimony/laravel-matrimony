@extends('layouts.app')

@section('content')
@php
    $rows = $recentTickets ?? collect();
@endphp

<div class="mx-auto max-w-3xl px-4 py-6">
    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <header class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
            <h1 class="text-xl font-bold text-gray-950 dark:text-gray-50">{{ __('help_centre.title') }}</h1>
            <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('help_centre.subtitle') }}</p>
        </header>

        <div class="px-5 py-4">
            <div id="help-centre-thread" class="min-h-[14rem] space-y-3 rounded-xl bg-gray-50 p-3 dark:bg-gray-950/60">
                <div class="flex justify-start">
                    <div class="max-w-[88%] rounded-2xl rounded-tl-md bg-white px-4 py-3 text-sm leading-6 text-gray-800 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-800">
                        {{ __('help_centre.greeting') }}
                    </div>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                @foreach (($quickPrompts ?? []) as $prompt)
                    <button
                        type="button"
                        class="help-centre-prompt rounded-full border border-indigo-200 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50 dark:border-indigo-800 dark:bg-gray-900 dark:text-indigo-200 dark:hover:bg-indigo-950/40"
                        data-prompt="{{ $prompt }}"
                    >
                        {{ $prompt }}
                    </button>
                @endforeach
            </div>

            <form id="help-centre-form" class="mt-4 flex gap-2">
                @csrf
                <label for="help-centre-input" class="sr-only">{{ __('help_centre.placeholder') }}</label>
                <textarea
                    id="help-centre-input"
                    name="message"
                    rows="1"
                    maxlength="500"
                    class="min-h-[48px] flex-1 resize-none rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                    placeholder="{{ __('help_centre.placeholder') }}"
                    required
                ></textarea>
                <button
                    type="submit"
                    id="help-centre-send"
                    class="inline-flex h-12 shrink-0 items-center justify-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {{ __('help_centre.send') }}
                </button>
            </form>
        </div>
    </section>

    @if ($rows->isNotEmpty())
        <section class="mt-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-sm font-bold text-gray-950 dark:text-gray-50">{{ __('help_centre.my_requests_title') }}</h2>
            <div class="mt-3 space-y-2">
                @foreach ($rows as $ticket)
                    @php
                        $statusKey = 'help_centre.status_' . $ticket->status;
                        $ticketLabel = $ticket->ticket_code
                            ? __('help_centre.ticket_prefix') . ': ' . $ticket->ticket_code
                            : __('help_centre.intent_prefix') . ': ' . str_replace('_', ' ', (string) $ticket->intent);
                    @endphp
                    <article class="rounded-xl border border-gray-200 px-3 py-2.5 text-sm dark:border-gray-800">
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 font-semibold text-gray-900 dark:text-gray-100">{{ $ticketLabel }}</p>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold {{ $ticket->status === 'open' ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' : ($ticket->status === 'resolved' ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100' : 'bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-100') }}">
                                {{ __($statusKey) }}
                            </span>
                        </div>
                        <p class="mt-1 line-clamp-2 text-gray-600 dark:text-gray-300">{{ $ticket->query_text }}</p>
                        <div class="mt-2 flex items-center justify-between gap-3">
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $ticket->created_at?->diffForHumans() }}</p>
                            <a href="{{ route('help-centre.requests.show', $ticket) }}" class="shrink-0 rounded-lg border border-indigo-200 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-200 dark:hover:bg-indigo-950/40">
                                {{ __('help_centre.view_request') }}
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</div>

<script>
(function () {
    const form = document.getElementById('help-centre-form');
    const input = document.getElementById('help-centre-input');
    const sendBtn = document.getElementById('help-centre-send');
    const thread = document.getElementById('help-centre-thread');
    const promptButtons = document.querySelectorAll('.help-centre-prompt');
    if (!form || !input || !thread || !sendBtn) return;

    const csrf = form.querySelector('input[name="_token"]')?.value || '';
    const genericMsg = @json($helpCentreGenericMsg ?? \App\Support\ErrorFactory::generic()->message);
    const networkMsg = @json($helpCentreNetworkMsg ?? \App\Support\ErrorFactory::helpCentreNetwork()->message);

    function addBubble(text, mine) {
        const row = document.createElement('div');
        row.className = mine ? 'flex justify-end' : 'flex justify-start';

        const bubble = document.createElement('div');
        bubble.className = mine
            ? 'max-w-[88%] rounded-2xl rounded-tr-md bg-indigo-600 px-4 py-3 text-sm leading-6 text-white shadow-sm'
            : 'max-w-[88%] rounded-2xl rounded-tl-md bg-white px-4 py-3 text-sm leading-6 text-gray-800 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-800';
        bubble.textContent = text;

        row.appendChild(bubble);
        thread.appendChild(row);
        thread.scrollTop = thread.scrollHeight;
    }

    async function askBot(message) {
        addBubble(message, true);
        sendBtn.disabled = true;
        input.disabled = true;

        try {
            const res = await fetch(@json(route('help-centre.ask')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ message }),
            });

            const data = await res.json();
            addBubble((res.ok && data.ok && data.reply) ? data.reply : genericMsg, false);
        } catch (e) {
            addBubble(networkMsg, false);
        } finally {
            sendBtn.disabled = false;
            input.disabled = false;
            input.value = '';
            input.focus();
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const message = (input.value || '').trim();
        if (!message) return;
        askBot(message);
    });

    promptButtons.forEach((btn) => {
        btn.addEventListener('click', function () {
            const message = (btn.getAttribute('data-prompt') || '').trim();
            if (!message) return;
            askBot(message);
        });
    });
})();
</script>
@endsection
