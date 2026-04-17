@auth
@if (! ($hideMemberMainNav ?? false))
<nav class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 backdrop-blur md:hidden dark:border-gray-800 dark:bg-gray-950/95">
    <div class="mx-auto grid max-w-7xl grid-cols-4">
        <a href="{{ route('chat.index') }}" class="relative flex flex-col items-center justify-center gap-0.5 px-2 py-2.5 text-[11px] font-semibold text-gray-700 dark:text-gray-200">
            <span>💬</span>
            <span>Chats</span>
            <span id="sticky-chat-badge" class="absolute right-5 top-1 inline-flex min-w-[1.1rem] items-center justify-center rounded-full bg-red-600 px-1.5 py-0.5 text-[9px] font-bold leading-none text-white hidden">0</span>
        </a>
        <a href="{{ route('interests.index') }}" class="relative flex flex-col items-center justify-center gap-0.5 px-2 py-2.5 text-[11px] font-semibold text-gray-700 dark:text-gray-200">
            <span>💝</span>
            <span>Interests</span>
            <span id="sticky-interests-badge" class="absolute right-4 top-1 inline-flex min-w-[1.1rem] items-center justify-center rounded-full bg-red-600 px-1.5 py-0.5 text-[9px] font-bold leading-none text-white hidden">0</span>
        </a>
        <a href="{{ route('who-viewed.index') }}" class="relative flex flex-col items-center justify-center gap-0.5 px-2 py-2.5 text-[11px] font-semibold text-gray-700 dark:text-gray-200">
            <span>👁️</span>
            <span>Activity</span>
            <span id="sticky-activity-badge" class="absolute right-4 top-1 inline-flex min-w-[1.1rem] items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[9px] font-bold leading-none text-white hidden">0</span>
        </a>
        <a href="{{ route('help-centre.index') }}" class="flex flex-col items-center justify-center gap-0.5 px-2 py-2.5 text-[11px] font-semibold text-gray-700 dark:text-gray-200">
            <span>🛟</span>
            <span>Help</span>
        </a>
    </div>
</nav>
@endif
@endauth
