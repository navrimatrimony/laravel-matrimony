@auth
{{-- Right-edge vertical tab (matches chat dock “Communication” handle style); avoids covering bottom content. --}}
<div id="help-centre-widget-root" class="pointer-events-none fixed right-0 top-[min(13rem,22vh)] z-[54] -translate-y-1/2">
    <div class="relative flex flex-row-reverse items-start">
        <button
            type="button"
            id="help-centre-widget-toggle"
            aria-expanded="false"
            aria-controls="help-centre-widget-panel"
            aria-label="{{ __('help_centre.widget_toggle_open') }}"
            class="pointer-events-auto flex w-9 max-w-[2.25rem] flex-col items-center justify-center gap-1 rounded-l-lg border border-r-0 border-gray-200 bg-gradient-to-b from-indigo-600 to-violet-700 py-3 text-[9px] font-bold uppercase leading-tight tracking-wide text-white shadow-lg transition hover:from-indigo-500 hover:to-violet-600 dark:border-gray-700"
        >
            <span class="block max-h-[5.5rem] overflow-hidden text-center [writing-mode:vertical-rl] [text-orientation:mixed]">{{ __('help_centre.title') }}</span>
        </button>

    <section
        id="help-centre-widget-panel"
        class="pointer-events-auto absolute right-full top-0 mr-2 hidden w-[min(22rem,calc(100vw-3rem))] overflow-hidden rounded-2xl rounded-r-none border border-indigo-100/90 bg-white shadow-2xl dark:border-indigo-900/70 dark:bg-gray-900 sm:w-[24rem]"
        role="dialog"
        aria-label="{{ __('help_centre.title') }}"
    >
        <header class="bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-3 text-white">
            <p class="text-sm font-bold">{{ __('help_centre.widget_title') }}</p>
            <p class="mt-0.5 text-xs text-indigo-100">{{ __('help_centre.widget_subtitle') }}</p>
        </header>

        <div id="help-centre-widget-thread" class="max-h-72 space-y-2 overflow-y-auto bg-gray-50 px-3 py-3 dark:bg-gray-950">
            <div class="max-w-[92%] rounded-2xl bg-white px-3 py-2 text-sm text-gray-800 shadow-sm dark:bg-gray-800 dark:text-gray-100">
                {{ __('help_centre.greeting') }}
            </div>
        </div>

        <div class="border-t border-gray-100 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-2 flex flex-wrap gap-1.5">
                <button type="button" class="hc-widget-prompt rounded-full border border-indigo-200 px-2.5 py-1 text-[11px] font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-200 dark:hover:bg-indigo-950/40" data-prompt="{{ __('help_centre.quick_payment') }}">{{ __('help_centre.quick_payment') }}</button>
                <button type="button" class="hc-widget-prompt rounded-full border border-indigo-200 px-2.5 py-1 text-[11px] font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-200 dark:hover:bg-indigo-950/40" data-prompt="{{ __('help_centre.quick_contact_unlock') }}">{{ __('help_centre.quick_contact_unlock') }}</button>
            </div>

            <form id="help-centre-widget-form" class="flex items-end gap-2">
                @csrf
                <label for="help-centre-widget-input" class="sr-only">{{ __('help_centre.placeholder') }}</label>
                <textarea
                    id="help-centre-widget-input"
                    name="message"
                    rows="2"
                    maxlength="500"
                    placeholder="{{ __('help_centre.placeholder') }}"
                    class="min-h-[42px] flex-1 resize-none rounded-xl border border-gray-300 px-3 py-2 text-xs text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                ></textarea>
                <button type="submit" id="help-centre-widget-send" class="inline-flex h-[42px] items-center justify-center rounded-xl bg-indigo-600 px-3 text-xs font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">{{ __('help_centre.send') }}</button>
            </form>
        </div>
    </section>
    </div>
</div>

<script>
(function () {
    const root = document.getElementById('help-centre-widget-root');
    if (!root) return;
    const toggle = document.getElementById('help-centre-widget-toggle');
    const panel = document.getElementById('help-centre-widget-panel');
    const form = document.getElementById('help-centre-widget-form');
    const input = document.getElementById('help-centre-widget-input');
    const sendBtn = document.getElementById('help-centre-widget-send');
    const thread = document.getElementById('help-centre-widget-thread');
    const prompts = root.querySelectorAll('.hc-widget-prompt');
    const csrf = form?.querySelector('input[name="_token"]')?.value || '';
    if (!toggle || !panel || !form || !input || !sendBtn || !thread) return;

    function setOpen(open) {
        panel.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? @json(__('help_centre.widget_toggle_close')) : @json(__('help_centre.widget_toggle_open')));
        if (open) input.focus();
    }

    function addBubble(text, mine) {
        const row = document.createElement('div');
        row.className = mine ? 'flex justify-end' : 'flex justify-start';
        const bubble = document.createElement('div');
        bubble.className = mine
            ? 'max-w-[92%] rounded-2xl bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm'
            : 'max-w-[92%] rounded-2xl bg-white px-3 py-2 text-sm text-gray-800 shadow-sm dark:bg-gray-800 dark:text-gray-100';
        bubble.textContent = text;
        row.appendChild(bubble);
        thread.appendChild(row);
        thread.scrollTop = thread.scrollHeight;
    }

    async function ask(message) {
        addBubble(message, true);
        sendBtn.disabled = true;
        input.disabled = true;
        try {
            const response = await fetch(@json(route('help-centre.ask')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ message }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                addBubble('Sorry, something went wrong. Please try again.', false);
            } else {
                addBubble(data.reply || 'Please try again.', false);
            }
        } catch (e) {
            addBubble('Network issue. Please retry.', false);
        } finally {
            sendBtn.disabled = false;
            input.disabled = false;
            input.value = '';
            input.focus();
        }
    }

    toggle.addEventListener('click', function () {
        const open = panel.classList.contains('hidden');
        setOpen(open);
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const message = (input.value || '').trim();
        if (!message) return;
        ask(message);
    });

    prompts.forEach((btn) => {
        btn.addEventListener('click', function () {
            const message = (btn.getAttribute('data-prompt') || '').trim();
            if (!message) return;
            setOpen(true);
            ask(message);
        });
    });
})();
</script>
@endauth
