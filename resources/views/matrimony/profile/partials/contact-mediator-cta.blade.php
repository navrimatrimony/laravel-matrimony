{{-- Mediator / matchmaking request CTA — used beside locked contact (needs_upgrade, show_no_one, etc.) --}}
<div class="rounded-2xl border border-violet-200/90 bg-gradient-to-br from-violet-50/95 via-white to-indigo-50/70 px-3 py-3 shadow-[0_14px_34px_-24px_rgba(109,40,217,0.55)] ring-1 ring-violet-100/80 backdrop-blur-sm dark:border-violet-800/60 dark:from-violet-950/35 dark:via-gray-900 dark:to-indigo-950/20 dark:ring-violet-900/40 md:col-span-3 md:flex md:h-full md:flex-col md:px-3.5 md:py-3.5">
    <div class="flex items-start gap-2">
        <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300" aria-hidden="true">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12.04 2.002c-5.523 0-9.999 4.305-9.999 9.613 0 1.864.56 3.604 1.526 5.071L2 22l5.56-1.456a10.19 10.19 0 0 0 4.48 1.03h.004c5.52 0 9.996-4.305 9.996-9.613s-4.477-9.959-10-9.959Zm5.84 13.784c-.24.665-1.2 1.227-1.66 1.3-.425.067-.95.095-1.534-.09-.355-.113-.812-.262-1.404-.51-2.47-1.032-4.08-3.59-4.205-3.758-.122-.168-.998-1.324-.998-2.526 0-1.203.64-1.792.868-2.036.229-.244.5-.305.665-.305.168 0 .334.002.48.01.154.008.36-.058.563.415.208.498.707 1.722.769 1.846.063.124.104.272.02.44-.083.168-.125.272-.25.417-.124.145-.262.324-.374.434-.126.124-.257.259-.111.507.146.248.65 1.053 1.394 1.707.957.84 1.765 1.102 2.013 1.227.249.125.394.105.54-.063.146-.168.624-.706.79-.95.166-.243.333-.204.562-.122.229.083 1.45.672 1.699.794.248.124.414.186.477.29.062.104.062.604-.178 1.269Z"/>
            </svg>
        </span>
        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-violet-700 dark:text-violet-300 sm:text-[11px]">{{ __('contact_access.mediator_heading') }}</p>
    </div>
    <p class="mt-1.5 text-[11px] leading-4 text-violet-900/90 dark:text-violet-100 sm:text-xs sm:leading-5">
        {{ __('contact_access.mediator_side_note') }}
    </p>
    @if (auth()->check())
        @if ($contactAccess['needs_upgrade_for_mediator'] ?? false)
            <button
                type="button"
                class="mt-auto inline-flex w-full items-center justify-center rounded-xl border border-amber-200/90 bg-amber-50 px-2.5 py-2 text-[11px] font-semibold text-amber-900 transition hover:bg-amber-100 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-100 dark:hover:bg-amber-950/60 sm:text-xs"
                @click="$root.showContactUpgradeModal = true"
            >
                {{ __('contact_access.upgrade_plans') }}
            </button>
        @else
            <form method="POST" action="{{ route('matrimony.profile.mediator-request', $profile) }}" class="mt-auto">
                @csrf
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-violet-600 px-2.5 py-2 text-[13px] font-semibold text-white shadow-sm transition hover:bg-violet-700 sm:text-sm">
                    <span class="block text-center leading-4 sm:leading-5">Send matchmaking<br>request</span>
                </button>
            </form>
        @endif
    @else
        <a href="{{ route('login') }}" class="mt-auto inline-flex w-full items-center justify-center rounded-xl bg-violet-600 px-2.5 py-2 text-[11px] font-semibold text-white shadow-sm transition hover:bg-violet-700 sm:text-xs">
            {{ __('Login') }}
        </a>
    @endif
</div>
