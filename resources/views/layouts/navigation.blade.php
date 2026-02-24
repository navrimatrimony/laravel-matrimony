<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800 dark:text-gray-200" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 md:-my-px md:ms-10 md:flex">

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
    @endif
@endauth



<x-nav-link :href="route('matrimony.profiles.index')" 
            :active="request()->routeIs('matrimony.profiles.index')">
    {{ __('Search Profiles') }}
</x-nav-link>

@auth
    <x-nav-link :href="route('interests.sent')" 
                :active="request()->routeIs('interests.sent')">
        {{ __('Interests Sent') }}
    </x-nav-link>

    <x-nav-link :href="route('interests.received')" 
                :active="request()->routeIs('interests.received')">
        {{ __('Interests Received') }}
    </x-nav-link>

    <x-nav-link :href="route('shortlist.index')" 
                :active="request()->routeIs('shortlist.index')">
        {{ __('Shortlist') }}
    </x-nav-link>

    <x-nav-link :href="route('intake.upload')" 
                :active="request()->routeIs('intake.upload')">
        {{ __('Upload Biodata') }}
    </x-nav-link>

    <x-nav-link :href="route('blocks.index')" 
                :active="request()->routeIs('blocks.index')">
        {{ __('Blocked') }}
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
        Admin
    </x-nav-link>
@endif

                </div>
            </div>

            <!-- Settings Dropdown -->
            @auth
            <div class="hidden md:flex md:items-center md:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
              

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
            </div>
            @endauth

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
            Create Profile
        </x-responsive-nav-link>
    @else
        <x-responsive-nav-link 
            :href="route('matrimony.profile.show', auth()->user()->matrimonyProfile->id)" 
            :active="request()->routeIs('matrimony.profile.show')"
        >
            {{ __('My Profile') }}
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('matrimony.profile.edit')">
            Edit Profile
        </x-responsive-nav-link>
    @endif
@endauth


    <x-responsive-nav-link :href="route('matrimony.profiles.index')">
        Search Profiles
    </x-responsive-nav-link>


@auth
    <x-responsive-nav-link :href="route('interests.sent')">
        Sent Interests
    </x-responsive-nav-link>

    <x-responsive-nav-link :href="route('interests.received')">
        Received Interests
    </x-responsive-nav-link>

    <x-responsive-nav-link :href="route('shortlist.index')">
        Shortlist
    </x-responsive-nav-link>

    <x-responsive-nav-link :href="route('intake.upload')">
        Upload Biodata
    </x-responsive-nav-link>

    <x-responsive-nav-link :href="route('blocks.index')">
        Blocked
    </x-responsive-nav-link>

    {{-- Notifications with unread badge (mobile) --}}
    <x-responsive-nav-link :href="route('notifications.index')" class="relative inline-flex items-center">
        Notifications
        @if($unreadNotificationCount > 0)
            <span 
                id="notification-badge-mobile" 
                class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-red-600 rounded-full"
            >{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>
        @endif
    </x-responsive-nav-link>
@endauth

@if (auth()->check() && auth()->user()->is_admin === true)
    <x-responsive-nav-link :href="route('admin.dashboard')">
        Admin
    </x-responsive-nav-link>
@endif

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
    if (badge || badgeMobile) {
        setInterval(updateNotificationCount, POLL_INTERVAL);
    }
})();
</script>
@endauth
