{{-- Lightweight nudge when distinct viewer count increases vs last acknowledged (localStorage). Syncs with member widgets poll. --}}
<div
    id="who-viewed-bubble-root"
    class="hidden fixed bottom-[4.5rem] left-4 z-[48] max-w-[min(20rem,calc(100vw-2rem))] md:bottom-24"
    role="status"
    aria-live="polite"
    aria-hidden="true"
    data-user-id="{{ (int) auth()->id() }}"
    data-suppress-route="{{ ($suppressWhoViewedBubble ?? false) ? '1' : '0' }}"
    data-who-viewed-url="{{ route('who-viewed.index') }}"
>
    <div class="pointer-events-auto flex gap-2 rounded-2xl border border-indigo-200/90 bg-white/95 px-3 py-2.5 shadow-[0_16px_40px_-18px_rgba(67,56,202,0.45)] backdrop-blur-sm dark:border-indigo-900/70 dark:bg-gray-900/95">
        <a
            href="{{ route('who-viewed.index') }}"
            class="flex min-w-0 flex-1 items-start gap-2 rounded-xl text-left hover:bg-indigo-50/80 dark:hover:bg-indigo-950/40"
            data-wv-see
        >
            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 text-lg text-white shadow-inner" aria-hidden="true">👁</span>
            <span class="min-w-0">
                <span class="block text-[13px] font-semibold leading-snug text-gray-900 dark:text-gray-100">{{ __('who_viewed.bubble_title') }}</span>
                <span class="mt-0.5 flex flex-wrap items-center gap-1 text-[11px] leading-snug text-gray-600 dark:text-gray-400">
                    <span>{{ __('who_viewed.bubble_intro') }}</span>
                    <span data-wv-count class="inline-flex min-w-[1.25rem] justify-center rounded-full bg-indigo-100 px-1.5 py-0.5 text-[11px] font-bold text-indigo-800 dark:bg-indigo-900/80 dark:text-indigo-100">0</span>
                    <span>{{ __('who_viewed.bubble_after_count') }}</span>
                </span>
            </span>
        </a>
        <button
            type="button"
            class="-mr-1 -mt-1 shrink-0 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
            data-wv-dismiss
            aria-label="{{ __('who_viewed.bubble_dismiss') }}"
        >×</button>
    </div>
</div>

@once
<script>
(function () {
    const root = document.getElementById('who-viewed-bubble-root');
    if (!root) return;

    const userId = root.dataset.userId || '0';
    const storageKey = 'wv_bubble_ack_v2_u' + userId;
    let lastPollCount = 0;

    function getAck() {
        try {
            const raw = localStorage.getItem(storageKey);
            return raw === null ? null : Number(raw);
        } catch (_e) {
            return null;
        }
    }

    function setAck(n, hideBubble) {
        try {
            localStorage.setItem(storageKey, String(Number(n)));
        } catch (_e) {}
        lastPollCount = Number(n);
        if (hideBubble !== false) {
            root.classList.add('hidden');
            root.setAttribute('aria-hidden', 'true');
        }
    }

    function revealBubble(count) {
        const badge = root.querySelector('[data-wv-count]');
        if (badge) {
            badge.textContent = count > 99 ? '99+' : String(count);
        }
        root.classList.remove('hidden');
        root.setAttribute('aria-hidden', 'false');
    }

    function evaluate(count) {
        lastPollCount = Number(count || 0);
        if (root.dataset.suppressRoute === '1') {
            setAck(lastPollCount, false);
            root.classList.add('hidden');
            root.setAttribute('aria-hidden', 'true');
            return;
        }
        let ack = getAck();
        if (ack === null) {
            setAck(lastPollCount, false);
            root.classList.add('hidden');
            root.setAttribute('aria-hidden', 'true');
            return;
        }
        if (lastPollCount > ack && lastPollCount > 0) {
            revealBubble(lastPollCount);
        } else {
            root.classList.add('hidden');
            root.setAttribute('aria-hidden', 'true');
        }
    }

    document.addEventListener('member-widget-counts-updated', function (e) {
        const detail = e.detail || {};
        evaluate(Number(detail.who_viewed_count || 0));
    });

    root.querySelector('[data-wv-dismiss]')?.addEventListener('click', function () {
        setAck(lastPollCount, true);
    });

    root.querySelector('[data-wv-see]')?.addEventListener('click', function () {
        setAck(lastPollCount, true);
    });
})();
</script>
@endonce
