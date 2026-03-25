<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 lg:h-12 items-center">
            <div class="flex items-center min-h-0">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto lg:h-8 fill-current text-gray-800 dark:text-gray-200" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 md:-my-px md:ms-10 md:flex md:items-center">

                    {{-- Dashboard --}}
                    
                  {{-- 
|--------------------------------------------------------------------------
| Matrimony Main Navigation (SSOT Day 13)
|--------------------------------------------------------------------------
| PURPOSE:
|   Logged-in user ला Matrimony related सगळे actions
|   top menu मधूनच accessible असावेत.
|
| IMPORTANT:
|   User ने URL लक्षात ठेवायची गरज नसावी.
|   "कुठे आहे?" हा प्रश्न कधीही येऊ नये.
|
| SSOT RULES:
|   - Rule 15: All matrimony actions MUST be visible in TOP MENU
|   - Rule 13: UI-first, no hidden flows
|--------------------------------------------------------------------------
--}}

@auth
    @if (!auth()->user()->matrimonyProfile)
        <x-nav-link :href="route('matrimony.profile.wizard.section', ['section' => 'basic-info'])" 
                    :active="request()->routeIs('matrimony.profile.wizard*')">
            {{ __('Create Profile') }}
        </x-nav-link>
    @else
    {{-- Matrimony Profile View Link --}}
   
@if(auth()->check() && auth()->user()->matrimonyProfile)
    <x-nav-link 
        :href="route('matrimony.profile.show', auth()->user()->matrimonyProfile->id)" 
        :active="request()->routeIs('matrimony.profile.show')"
    >
        {{ __('My Profile') }}
    </x-nav-link>
@endif

    <x-nav-link :href="route('matrimony.profile.edit')"
                :active="request()->routeIs('matrimony.profile.edit')">
        {{ __('Edit Profile') }}
    </x-nav-link>

    <x-nav-link :href="route('matrimony.profile.upload-photo')"
                :active="request()->routeIs('matrimony.profile.upload-photo')">
        {{ __('Upload Photos') }}
    </x-nav-link>
    @endif
@endauth



<x-nav-link :href="route('matrimony.profiles.index')" 
            :active="request()->routeIs('matrimony.profiles.index')">
    {{ __('Search Profiles') }}
</x-nav-link>

@auth
    <x-dropdown align="right" width="56">
        <x-slot name="trigger">
            <button class="inline-flex items-center px-3 py-2 lg:py-1.5 lg:px-2.5 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                <span>Interests</span>
                <svg class="fill-current h-4 w-4 ms-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </button>
        </x-slot>

        <x-slot name="content">
            <x-dropdown-link :href="route('interests.sent')">
                {{ __('Interests Sent') }}
            </x-dropdown-link>

            <x-dropdown-link :href="route('interests.received')">
                {{ __('Interests Received') }}
            </x-dropdown-link>
        </x-slot>
    </x-dropdown>

    <x-nav-link :href="route('who-viewed.index')" 
                :active="request()->routeIs('who-viewed.index')">
        {{ __('Who viewed me') }}
    </x-nav-link>

    <x-nav-link :href="route('contact-inbox.index')" 
                :active="request()->routeIs('contact-inbox.index')">
        {{ __('Contact Requests') }}
    </x-nav-link>

    <x-nav-link :href="route('shortlist.index')" 
                :active="request()->routeIs('shortlist.index')">
        {{ __('Shortlist') }}
    </x-nav-link>

    <x-nav-link :href="route('intake.index')" 
                :active="request()->routeIs('intake.index')">
        {{ __('My biodata uploads') }}
    </x-nav-link>

    <x-nav-link :href="route('blocks.index')" 
                :active="request()->routeIs('blocks.index')">
        {{ __('Blocked') }}
    </x-nav-link>

    @php
        $chatUnreadCount = 0;
        $mp = auth()->user()->matrimonyProfile;
        if ($mp) {
            $chatUnreadCount = \Illuminate\Support\Facades\DB::table('messages')
                ->where('receiver_profile_id', $mp->id)
                ->whereNull('read_at')
                ->count();
        }
    @endphp

    <x-nav-link :href="route('chat.index')"
                :active="request()->routeIs('chat.*')"
                class="relative">
        <span class="inline-flex items-center gap-2">
            <svg class="h-4 w-4 text-gray-500 dark:text-gray-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.95 2.63 3.217.42.074.797.31 1.046.66l.85 1.19c.34.477.99.596 1.48.272l2.155-1.43c.33-.219.73-.29 1.11-.2 1.04.246 2.17.246 3.21 0 .38-.09.78-.02 1.11.2l2.155 1.43c.49.324 1.14.205 1.48-.272l.85-1.19c.249-.35.626-.586 1.046-.66 1.507-.267 2.63-1.618 2.63-3.217V6.99c0-1.86-1.51-3.37-3.37-3.37H5.62c-1.86 0-3.37 1.51-3.37 3.37v5.77Z"/></svg>
            <span>{{ __('Chat') }}</span>
        </span>
        <span
            id="chat-badge"
            class="absolute -top-1 -right-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-red-600 rounded-full {{ $chatUnreadCount > 0 ? 'animate-pulse' : 'hidden' }}"
        >{{ $chatUnreadCount > 99 ? '99+' : $chatUnreadCount }}</span>
    </x-nav-link>

    {{-- Notifications with unread badge --}}
    <x-nav-link :href="route('notifications.index')" 
                :active="request()->routeIs('notifications.*')"
                class="relative">
        {{ __('Notifications') }}
        <span 
            id="notification-badge" 
            class="absolute -top-1 -right-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-red-600 rounded-full {{ $unreadNotificationCount > 0 ? '' : 'hidden' }}"
        >{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>
    </x-nav-link>
@endauth

@if (auth()->check() && auth()->user()->is_admin === true)
    <x-nav-link :href="route('admin.dashboard')" 
                :active="request()->routeIs('admin.*')">
        {{ __('common.admin') }}
    </x-nav-link>
@endif

                </div>
            </div>

            <!-- Language switcher + Settings Dropdown -->
            <div class="hidden md:flex md:items-center md:ms-6 md:gap-2 lg:ms-4 lg:gap-1.5">
                <x-language-switcher />
                @auth
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 lg:py-1.5 lg:px-2.5 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="block px-4 py-2 text-xs text-gray-500 dark:text-gray-400 font-semibold">
                            {{ __('Settings') }}
                        </div>

                        <x-dropdown-link :href="route('user.settings.privacy')">
                            {{ __('Privacy & Visibility') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('user.settings.communication')">
                            {{ __('Communication Preferences') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('user.settings.security')">
                            {{ __('Account & Security') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('notifications.index')">
                            {{ __('Manage Notifications') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('blocks.index')">
                            {{ __('Manage Blocked Profiles') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
                @endauth
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center md:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-900 focus:outline-none transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden md:hidden">
        <div class="pt-2 pb-3 space-y-1">

            

            
        @auth
    @if (!auth()->user()->matrimonyProfile)
        <x-responsive-nav-link :href="route('matrimony.profile.wizard.section', ['section' => 'basic-info'])">
            {{ __('Create Profile') }}
        </x-responsive-nav-link>
    @else
        <x-responsive-nav-link 
            :href="route('matrimony.profile.show', auth()->user()->matrimonyProfile->id)" 
            :active="request()->routeIs('matrimony.profile.show')"
        >
            {{ __('My Profile') }}
        </x-responsive-nav-link>

        <div class="mt-2 px-3 text-xs font-semibold text-gray-500 dark:text-gray-400">
            Settings
        </div>

        <x-responsive-nav-link :href="route('user.settings.privacy')">
            {{ __('Privacy & Visibility') }}
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('user.settings.communication')">
            {{ __('Communication Preferences') }}
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('user.settings.security')">
            {{ __('Account & Security') }}
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('notifications.index')">
            {{ __('Manage Notifications') }}
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('blocks.index')">
            {{ __('Manage Blocked Profiles') }}
        </x-responsive-nav-link>
    @endif
@endauth


    <x-responsive-nav-link :href="route('matrimony.profiles.index')">
        {{ __('Search Profiles') }}
    </x-responsive-nav-link>


@auth
    <details class="px-2">
        <summary class="cursor-pointer select-none px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300">
            Interests
        </summary>
        <div class="ml-2 space-y-1">
            <x-responsive-nav-link :href="route('interests.sent')">
                {{ __('Interests Sent') }}
            </x-responsive-nav-link>

            <x-responsive-nav-link :href="route('interests.received')">
                {{ __('Interests Received') }}
            </x-responsive-nav-link>
        </div>
    </details>

    <x-responsive-nav-link :href="route('who-viewed.index')">
        {{ __('Who viewed me') }}
    </x-responsive-nav-link>
    <x-responsive-nav-link :href="route('contact-inbox.index')">
        {{ __('Contact Requests') }}
    </x-responsive-nav-link>

    <x-responsive-nav-link :href="route('shortlist.index')">
        {{ __('Shortlist') }}
    </x-responsive-nav-link>

    <x-responsive-nav-link :href="route('intake.index')">
        {{ __('My biodata uploads') }}
    </x-responsive-nav-link>

    <x-responsive-nav-link :href="route('blocks.index')">
        {{ __('Blocked') }}
    </x-responsive-nav-link>

    {{-- Notifications with unread badge (mobile) --}}
    <x-responsive-nav-link :href="route('notifications.index')" class="relative inline-flex items-center">
        {{ __('Notifications') }}
        @if($unreadNotificationCount > 0)
            <span 
                id="notification-badge-mobile" 
                class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-red-600 rounded-full"
            >{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>
        @endif
    </x-responsive-nav-link>

    <x-responsive-nav-link :href="route('chat.index')" class="relative inline-flex items-center">
        {{ __('Chat') }}
        @if($chatUnreadCount > 0)
            <span
                id="chat-badge-mobile"
                class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-red-600 rounded-full"
            >{{ $chatUnreadCount > 99 ? '99+' : $chatUnreadCount }}</span>
        @endif
    </x-responsive-nav-link>
@endauth

@if (auth()->check() && auth()->user()->is_admin === true)
    <x-responsive-nav-link :href="route('admin.dashboard')">
        {{ __('common.admin') }}
    </x-responsive-nav-link>
@endif

        </div>

        <!-- Language switcher (mobile) -->
        <div class="px-4 pt-2 pb-2 border-t border-gray-200 dark:border-gray-600">
            <x-language-switcher />
        </div>

        <!-- Responsive Settings Options -->
        @auth
        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800 dark:text-gray-200">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-gray-500">{{ Auth::user()->email }}</div>
            </div>
            @endauth
            <div class="mt-3 space-y-1">
               

                @auth
                <x-responsive-nav-link :href="route('user.settings.privacy')">
                    {{ __('Privacy & Visibility') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('user.settings.communication')">
                    {{ __('Communication Preferences') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('user.settings.security')">
                    {{ __('Account & Security') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('notifications.index')">
                    {{ __('Manage Notifications') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('blocks.index')">
                    {{ __('Manage Blocked Profiles') }}
                </x-responsive-nav-link>
                @endauth

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')"
                        onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>

{{-- Notification count polling (no WebSockets, no push) --}}
@auth
<script>
(function() {
    const POLL_INTERVAL = 30000; // 30 seconds
    const badge = document.getElementById('notification-badge');
    const badgeMobile = document.getElementById('notification-badge-mobile');
    const chatBadge = document.getElementById('chat-badge');
    const chatBadgeMobile = document.getElementById('chat-badge-mobile');

    function updateNotificationCount() {
        fetch('{{ route("notifications.unread-count") }}', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            const count = data.count || 0;
            const displayCount = count > 99 ? '99+' : count;

            // Update desktop badge
            if (badge) {
                badge.textContent = displayCount;
                if (count > 0) {
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            }

            // Update mobile badge
            if (badgeMobile) {
                badgeMobile.textContent = displayCount;
                if (count > 0) {
                    badgeMobile.style.display = 'inline-flex';
                } else {
                    badgeMobile.style.display = 'none';
                }
            }
        })
        .catch(err => {
            // Silent fail - don't disrupt user experience
            console.warn('Notification poll failed:', err);
        });
    }

    // Start polling after page load
    function updateChatCount() {
        fetch('{{ route("chat.index") }}?unread_only=1', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            const count = data.count || 0;
            const displayCount = count > 99 ? '99+' : count;
            if (chatBadge) {
                chatBadge.textContent = displayCount;
                if (count > 0) chatBadge.classList.remove('hidden'); else chatBadge.classList.add('hidden');
            }
            if (chatBadgeMobile) {
                chatBadgeMobile.textContent = displayCount;
                chatBadgeMobile.style.display = count > 0 ? 'inline-flex' : 'none';
            }
        })
        .catch(() => {});
    }

    if (badge || badgeMobile || chatBadge || chatBadgeMobile) {
        setInterval(updateNotificationCount, POLL_INTERVAL);
        setInterval(updateChatCount, POLL_INTERVAL);
    }
})();
</script>
@endauth
