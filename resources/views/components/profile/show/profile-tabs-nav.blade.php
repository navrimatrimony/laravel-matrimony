@props([])

<nav class="sticky top-0 z-20 -mx-1 mb-6 flex gap-1 rounded-xl border border-stone-200/80 bg-stone-50/95 p-1 shadow-sm backdrop-blur-sm dark:border-gray-700/80 dark:bg-gray-900/90 sm:mb-7" aria-label="{{ __('profile.show_profile_tabs_label') }}">
    <a
        href="#profile-detailed"
        class="flex min-h-[2.75rem] flex-1 items-center justify-center rounded-lg px-3 py-2 text-center text-sm font-semibold text-stone-800 transition hover:bg-white hover:shadow-sm dark:text-stone-100 dark:hover:bg-gray-800"
    >
        {{ __('profile.show_tab_detailed_profile') }}
    </a>
    <a
        href="#partner-preferences"
        class="flex min-h-[2.75rem] flex-1 items-center justify-center rounded-lg px-3 py-2 text-center text-sm font-semibold text-stone-800 transition hover:bg-white hover:shadow-sm dark:text-stone-100 dark:hover:bg-gray-800"
    >
        {{ __('profile.show_tab_partner_preferences') }}
    </a>
</nav>
