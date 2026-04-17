@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-6">
    <div class="rounded-3xl border border-indigo-100/80 bg-gradient-to-br from-white via-indigo-50/40 to-violet-50/40 p-5 shadow-[0_18px_46px_-32px_rgba(79,70,229,0.45)] dark:border-indigo-900/60 dark:from-gray-900 dark:via-indigo-950/20 dark:to-violet-950/20">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">{{ __('nav.connect') }}</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-gray-900 dark:text-gray-100">{{ __('help_centre.title') }}</h1>
                <p class="mt-1 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ __('help_centre.subtitle') }}</p>
            </div>
        </div>

        <div id="help-centre-thread" class="mt-5 space-y-3 rounded-2xl border border-white/70 bg-white/85 p-4 shadow-inner dark:border-gray-700/70 dark:bg-gray-900/70">
            <div class="max-w-3xl rounded-2xl bg-indigo-50 px-4 py-3 text-sm text-indigo-950 dark:bg-indigo-950/30 dark:text-indigo-100">
                {{ __('help_centre.greeting') }}
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            @foreach (($quickPrompts ?? []) as $prompt)
                <button type="button" class="help-centre-prompt rounded-full border border-indigo-200 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50 dark:border-indigo-800/70 dark:bg-gray-900 dark:text-indigo-200 dark:hover:bg-indigo-950/40" data-prompt="{{ $prompt }}">
                    {{ $prompt }}
                </button>
            @endforeach
        </div>

        <form id="help-centre-form" class="mt-4 flex items-end gap-2">
            @csrf
            <label for="help-centre-input" class="sr-only">{{ __('help_centre.placeholder') }}</label>
            <textarea
                id="help-centre-input"
                name="message"
                rows="2"
                maxlength="500"
                class="min-h-[52px] flex-1 resize-y rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                placeholder="{{ __('help_centre.placeholder') }}"
                required
            ></textarea>
            <button type="submit" id="help-centre-send" class="inline-flex h-[52px] items-center justify-center rounded-2xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">
                {{ __('help_centre.send') }}
            </button>
        </form>

        <div class="mt-6 rounded-2xl border border-gray-200/80 bg-white/80 p-4 dark:border-gray-700 dark:bg-gray-900/70">
            <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ __('help_centre.my_requests_title') }}</h2>
            @php($rows = $recentTickets ?? collect())
            @if ($rows->isEmpty())
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('help_centre.my_requests_empty') }}</p>
            @else
                <div class="mt-3 space-y-2">
                    @foreach ($rows as $ticket)
                        <div class="rounded-xl border border-gray-200/80 bg-white px-3 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-800/70">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-semibold text-gray-900 dark:text-gray-100">
                                    @if ($ticket->ticket_code)
                                        {{ __('help_centre.ticket_prefix') }}: {{ $ticket->ticket_code }}
                                    @else
                                        {{ __('help_centre.intent_prefix') }}: {{ str_replace('_', ' ', (string) $ticket->intent) }}
                                    @endif
                                </p>
                                @php($statusKey = 'help_centre.status_' . $ticket->status)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $ticket->status === 'open' ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' : 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100' }}">
                                    {{ __($statusKey) }}
                                </span>
                            </div>
                            <p class="mt-1 text-gray-700 dark:text-gray-200">{{ $ticket->query_text }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $ticket->created_at?->diffForHumans() }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
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

    function addBubble(text, mine) {
        const row = document.createElement('div');
        row.className = mine ? 'flex justify-end' : 'flex justify-start';
        const bubble = document.createElement('div');
        bubble.className = mine
            ? 'max-w-3xl rounded-2xl bg-indigo-600 px-4 py-3 text-sm text-white'
            : 'max-w-3xl rounded-2xl bg-gray-100 px-4 py-3 text-sm text-gray-900 dark:bg-gray-800 dark:text-gray-100';
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
            const res = await fetch('{{ route('help-centre.ask') }}', {
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
            if (!res.ok || !data.ok) {
                addBubble('Sorry, something went wrong. Please try again.', false);
                return;
            }

            addBubble(data.reply || 'Please try again.', false);
        } catch (e) {
            addBubble('Network issue. Please retry in a moment.', false);
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
