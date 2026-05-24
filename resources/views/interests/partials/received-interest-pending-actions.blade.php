@php
    $acceptLocked = (bool) ($acceptLocked ?? false);
    $containerClass = $containerClass ?? 'mt-4';
    $showLockedUpgradeHint = ($showLockedUpgradeHint ?? true) !== false;
@endphp
<div class="{{ $containerClass }} w-full min-w-0">
    <div class="grid w-full min-w-0 grid-cols-2 gap-2 sm:gap-3">
        <form method="POST" action="{{ route('interests.accept', $interest) }}" class="min-w-0">
            @csrf
            <button type="submit" @disabled($acceptLocked)
                class="inline-flex w-full min-h-[2.75rem] items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-2 py-2.5 text-xs font-semibold text-white shadow-md shadow-emerald-600/20 transition hover:from-emerald-700 hover:to-teal-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-80 dark:focus:ring-offset-gray-900 sm:gap-2 sm:px-3 sm:text-sm">
                @if ($acceptLocked)
                    <svg class="h-3.5 w-3.5 shrink-0 text-amber-200 sm:h-4 sm:w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                @endif
                <span class="whitespace-nowrap">{{ __('interests.accept') }}</span>
            </button>
        </form>
        <form method="POST" action="{{ route('interests.reject', $interest) }}" class="min-w-0">
            @csrf
            <button type="submit"
                class="inline-flex w-full min-h-[2.75rem] items-center justify-center rounded-xl border-2 border-rose-200/90 bg-white px-2 py-2.5 text-xs font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50 dark:border-rose-900/50 dark:bg-gray-800 dark:text-rose-300 dark:hover:bg-rose-950/30 sm:px-3 sm:text-sm">
                <span class="whitespace-nowrap">{{ __('interests.reject') }}</span>
            </button>
        </form>
    </div>
    @if ($acceptLocked && $showLockedUpgradeHint)
        <p class="mt-2 text-[11px] leading-snug text-gray-600 dark:text-gray-400">
            {{ __('interests.locked_accept_hint') }}
            <a href="{{ $plansUrl }}" class="font-semibold text-rose-600 hover:underline dark:text-rose-400">{{ __('interests.locked_actions_cta') }}</a>
        </p>
    @endif
</div>
