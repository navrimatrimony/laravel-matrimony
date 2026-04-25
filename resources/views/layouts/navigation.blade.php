<nav x-data="{ open: false }" class="bg-red-600 border-b border-red-800 shadow-sm dark:bg-red-800 dark:border-red-950 overflow-visible">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 lg:h-12 items-center">
            <div class="flex min-h-0 items-center self-stretch md:min-w-0">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('dashboard') }}">
                        <x-application-logo class="block h-9 w-auto lg:h-8 fill-current text-white drop-shadow-sm" />
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden min-w-0 space-x-8 md:-my-px md:ms-10 md:flex md:h-full md:items-center">

@php
    $mpNav = auth()->check() ? auth()->user()->matrimonyProfile : null;
    $chatUnreadCount = 0;
    if ($mpNav) {
        $chatUnreadCount = (int) \Illuminate\Support\Facades\DB::table('messages')
            ->where('receiver_profile_id', $mpNav->id)
            ->whereNull('read_at')
            ->count();
    }
    $isOwnProfileShow = request()->routeIs('matrimony.profile.show')
        && $mpNav
        && (int) request()->route('matrimony_profile_id') === (int) $mpNav->id;

    $navMainSection = 'none';
    if (request()->routeIs('plans.*')) {
        $navMainSection = 'plans';
    } elseif (request()->routeIs('who-viewed.*')
        || request()->routeIs('notifications.index', 'notifications.show')) {
        $navMainSection = 'activity';
    } elseif (request()->routeIs('interests.*')
        || request()->routeIs('chat.*')
        || request()->routeIs('mediation-inbox.*')
        || request()->routeIs('mediation-requests.*')
        || request()->routeIs('help-centre.*')) {
        $navMainSection = 'connect';
    } elseif (request()->routeIs('matrimony.profiles.index')
        || request()->routeIs('matches.*')
        || (request()->routeIs('matrimony.profile.show') && ! $isOwnProfileShow)) {
        $navMainSection = 'discover';
    } elseif (request()->routeIs('dashboard')
        || request()->routeIs('matrimony.profile.edit')
        || request()->routeIs('matrimony.profile.edit-full')
        || request()->routeIs('matrimony.profile.create')
        || request()->routeIs('matrimony.profile.contacts.*')
        || request()->routeIs('matrimony.profile.photos.*')
        || request()->routeIs('matrimony.profile.upload-photo')
        || request()->routeIs('matrimony.profile.wizard*')
        || request()->routeIs('user.settings.*')
        || request()->routeIs('user.settings.my-plan', 'user.my-plan', 'user.plan-history')
        || request()->routeIs('intake.*')
        || request()->routeIs('blocks.index')
        || request()->routeIs('contact-inbox.*')
        || request()->routeIs('shortlist.*')
        || request()->routeIs('matrimony.verification.*')
        || request()->routeIs('matrimony.profile.verification.*')
        || $isOwnProfileShow) {
        $navMainSection = 'home';
    }
@endphp

{{-- =========================
    NEW SMART NAV — tier 1 (Shaadi-style main row; tier 2 below)
========================= --}}
<div class="flex h-full items-center gap-8">

    @php
        /* Tip points up; sit on bottom edge of red row so base meets white sub-bar */
        $navMainCaret = 'pointer-events-none absolute bottom-0 left-1/2 z-30 h-0 w-0 -translate-x-1/2 translate-y-px border-x-[9px] border-x-transparent border-b-[10px] border-b-white drop-shadow-sm';
        $navMainLink = static function (bool $active): string {
            $base = 'relative flex h-full items-center px-1 leading-none transition';

            return $active
                ? $base.' text-base font-black text-yellow-300 dark:text-yellow-200'
                : $base.' text-sm font-medium text-white/90 hover:text-white';
        };
    @endphp

    {{-- Home → opens dashboard; submenu shows profile & account links --}}
    <a href="{{ route('dashboard') }}" class="{{ $navMainLink($navMainSection === 'home') }}">
        <span class="whitespace-nowrap">{{ __('nav.home') }}</span>
        @if ($navMainSection === 'home')
            <span class="{{ $navMainCaret }}" aria-hidden="true"></span>
        @endif
    </a>

    <a href="{{ route('matrimony.profiles.index') }}" class="{{ $navMainLink($navMainSection === 'discover') }}">
        <span class="whitespace-nowrap">{{ __('nav.discover') }}</span>
        @if ($navMainSection === 'discover')
            <span class="{{ $navMainCaret }}" aria-hidden="true"></span>
        @endif
    </a>

    <a href="{{ route('interests.index') }}" class="{{ $navMainLink($navMainSection === 'connect') }}">
        <span class="whitespace-nowrap">{{ __('nav.connect') }}</span>
        @if ($navMainSection === 'connect')
            <span class="{{ $navMainCaret }}" aria-hidden="true"></span>
        @endif
    </a>

    <a href="{{ route('who-viewed.index') }}" class="{{ $navMainLink($navMainSection === 'activity') }}">
        <span class="whitespace-nowrap">{{ __('nav.activity') }}</span>
        @php($activityMainCount = (int) (($memberActivityCounts['interests_pending'] ?? 0) + ($memberActivityCounts['who_viewed_count'] ?? 0)))
        <span
            id="activity-main-badge"
            class="ml-2 inline-flex min-w-[1.2rem] items-center justify-center rounded-full bg-yellow-300 px-1.5 py-0.5 text-[10px] font-black leading-none text-red-700 {{ $activityMainCount > 0 ? '' : 'hidden' }}"
        >{{ $activityMainCount > 99 ? '99+' : $activityMainCount }}</span>
        @if ($navMainSection === 'activity')
            <span class="{{ $navMainCaret }}" aria-hidden="true"></span>
        @endif
    </a>

    <a href="{{ route('plans.index') }}"
       class="relative flex h-full items-center transition transform focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
        <span class="inline-flex items-center rounded-md bg-yellow-400 px-4 py-1.5 text-sm font-semibold text-gray-900 shadow-md ring-1 ring-black/10 hover:bg-yellow-300 hover:scale-105 whitespace-nowrap leading-none dark:bg-yellow-400 dark:text-gray-900 dark:hover:bg-yellow-300 {{ $navMainSection === 'plans' ? 'ring-2 ring-white/90' : '' }}">{{ __('nav.upgrade') }}</span>
        @if ($navMainSection === 'plans')
            <span class="{{ $navMainCaret }}" aria-hidden="true"></span>
        @endif
    </a>

</div>

                </div>
            </div>

            <!-- Language switcher + Settings Dropdown -->
            <div class="hidden md:flex md:items-center md:ms-6 md:gap-2 lg:ms-4 lg:gap-1.5">
                <x-language-switcher :on-red="true" />
                @auth
                    @if (auth()->user()->matrimonyProfile && filled(data_get($planUsageSummary, 'subscription_state_label')))
                        <span class="hidden max-w-[14rem] truncate text-xs font-medium text-white/90 lg:inline" title="{{ data_get($planUsageSummary, 'subscription_state_label') }}">
                            {{ data_get($planUsageSummary, 'subscription_state_label') }}
                        </span>
                    @endif
                <x-dropdown align="right" width="56">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 lg:py-1.5 lg:px-2.5 border border-white/25 text-sm leading-4 font-medium rounded-md text-white bg-red-700/40 hover:bg-red-700/80 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ Auth::user()->name }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="py-1 bg-white rounded-lg shadow-lg dark:bg-gray-800">
                        <div class="block px-4 py-2 text-xs text-gray-500 dark:text-gray-400 font-semibold">
                            {{ __('nav.personal_menu_profile_section') }}
                        </div>

                        @if (auth()->user()->matrimonyProfile)
                            <x-dropdown-link :href="route('matrimony.profile.upload-photo')" class="hover:bg-gray-100 transition rounded-md">
                                {{ __('Upload Photos') }}
                            </x-dropdown-link>

                            <x-dropdown-link :href="route('matrimony.profile.show', auth()->user()->matrimonyProfile->id)" class="hover:bg-gray-100 transition rounded-md">
                                {{ __('nav.my_profile') }}
                            </x-dropdown-link>

                            <x-dropdown-link :href="route('interests.index')" class="hover:bg-gray-100 transition rounded-md">
                                {{ __('nav.interests') }}
                            </x-dropdown-link>
                        @endif

                        <x-dropdown-link :href="route('contact-inbox.index')" class="hover:bg-gray-100 transition rounded-md">
                            {{ __('nav.contact_requests') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('shortlist.index')" class="hover:bg-gray-100 transition rounded-md">
                            {{ __('nav.shortlist') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('intake.index')" class="hover:bg-gray-100 transition rounded-md">
                            {{ __('nav.my_biodata_uploads') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('blocks.index')" class="hover:bg-gray-100 transition rounded-md">
                            {{ __('nav.blocked') }}
                        </x-dropdown-link>

                        @if (auth()->user()->isAnyAdmin())
                            <div class="border-t border-gray-200 dark:border-gray-600 my-1"></div>
                            <div class="block px-4 py-2 text-xs text-gray-500 dark:text-gray-400 font-semibold">
                                {{ __('nav.admin_section') }}
                            </div>
                            <x-dropdown-link :href="route('admin.dashboard')" class="hover:bg-gray-100 transition rounded-md font-semibold text-indigo-700 dark:text-indigo-300">
                                {{ __('nav.admin_panel') }}
                            </x-dropdown-link>
                        @endif

                        <div class="border-t border-gray-200 dark:border-gray-600 my-1"></div>

                        <div class="block px-4 py-2 text-xs text-gray-500 dark:text-gray-400 font-semibold">
                            {{ __('Settings') }}
                        </div>

                        <x-dropdown-link :href="route('user.settings.privacy')" class="hover:bg-gray-100 transition rounded-md">
                            {{ __('Privacy & Visibility') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('user.settings.communication')" class="hover:bg-gray-100 transition rounded-md">
                            {{ __('Communication Preferences') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('user.settings.security')" class="hover:bg-gray-100 transition rounded-md">
                            {{ __('Account & Security') }}
                        </x-dropdown-link>

                        @if (auth()->user()->matrimonyProfile)
                            <x-dropdown-link :href="route('user.settings.my-plan')" class="hover:bg-gray-100 transition rounded-md">
                                {{ __('user_plan.my_plan_hub_title') }}
                            </x-dropdown-link>
                        @endif

                        <x-dropdown-link :href="route('notifications.index')" class="hover:bg-gray-100 transition rounded-md">
                            {{ __('Manage Notifications') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                class="hover:bg-gray-100 transition rounded-md"
                                onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                        </div>
                    </x-slot>
                </x-dropdown>
                @endauth
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center md:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-white hover:bg-red-700/80 focus:outline-none transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    @include('layouts.partials.nav-secondary')

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden md:hidden bg-red-700 border-t border-red-800/90 dark:bg-red-900 dark:border-red-950">
        <div class="pt-2 pb-3 space-y-1">

{{-- =========================
    NEW MOBILE NAV (STEP 3)
========================= --}}

<x-responsive-nav-link :href="route('dashboard')">
    {{ __('nav.home') }}
</x-responsive-nav-link>

{{-- Discover --}}
<details class="px-2">
    <summary class="cursor-pointer px-3 py-2 text-white font-medium">
        Discover
    </summary>
    <div class="ml-3 space-y-1">
        <x-responsive-nav-link :href="route('matrimony.profiles.index')">
            Search Profiles
        </x-responsive-nav-link>

        @auth
        <x-responsive-nav-link :href="route('matches.index')">
            Matches
        </x-responsive-nav-link>
        @endauth
    </div>
</details>

{{-- Connect --}}
<details class="px-2">
    <summary class="cursor-pointer px-3 py-2 text-white font-medium">
        Connect
    </summary>
    <div class="ml-3 space-y-1">
        <x-responsive-nav-link :href="route('interests.index')">
            {{ __('nav.interests') }}
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('chat.index')" class="relative inline-flex items-center">
            Chat
            <span
                id="chat-badge-mobile"
                class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-red-800 bg-white ring-1 ring-white/80 rounded-full {{ (int) ($chatUnreadCount ?? 0) > 0 ? '' : 'hidden' }}"
            >{{ (int) ($chatUnreadCount ?? 0) > 99 ? '99+' : (int) ($chatUnreadCount ?? 0) }}</span>
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('contact-inbox.index')">
            Contact Requests
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('mediation-inbox.index')">
            Mediation
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('help-centre.index')">
            {{ __('nav.help_centre') }}
        </x-responsive-nav-link>
    </div>
</details>

{{-- Activity --}}
<details class="px-2">
    <summary class="cursor-pointer px-3 py-2 text-white font-medium">
        Activity
    </summary>
    <div class="ml-3 space-y-1">
        <x-responsive-nav-link :href="route('who-viewed.index')" class="relative inline-flex items-center">
            {{ __('nav.who_viewed_me') }}
            <span
                id="who-viewed-badge-mobile"
                class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-red-800 bg-white ring-1 ring-white/80 rounded-full {{ (int) ($memberActivityCounts['who_viewed_count'] ?? 0) > 0 ? '' : 'hidden' }}"
            >{{ (int) ($memberActivityCounts['who_viewed_count'] ?? 0) > 99 ? '99+' : (int) ($memberActivityCounts['who_viewed_count'] ?? 0) }}</span>
        </x-responsive-nav-link>

        @auth
            @if (auth()->user()->matrimonyProfile)
                <x-responsive-nav-link :href="route('interests.index')" class="relative inline-flex items-center">
                    {{ __('nav.interests') }}
                    <span
                        id="interests-received-badge-mobile"
                        class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-red-800 bg-white ring-1 ring-white/80 rounded-full {{ (int) ($memberActivityCounts['interests_pending'] ?? 0) > 0 ? '' : 'hidden' }}"
                    >{{ (int) ($memberActivityCounts['interests_pending'] ?? 0) > 99 ? '99+' : (int) ($memberActivityCounts['interests_pending'] ?? 0) }}</span>
                </x-responsive-nav-link>
            @endif
        @endauth

        <x-responsive-nav-link :href="route('shortlist.index')">
            {{ __('nav.shortlist') }}
        </x-responsive-nav-link>

        <x-responsive-nav-link :href="route('notifications.index')" class="relative inline-flex items-center">
            {{ __('nav.notifications') }}
            <span
                id="notification-badge-mobile"
                class="ml-2 inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-red-800 bg-white ring-1 ring-white/80 rounded-full {{ (int) ($unreadNotificationCount ?? 0) > 0 ? '' : 'hidden' }}"
            >{{ (int) ($unreadNotificationCount ?? 0) > 99 ? '99+' : (int) ($unreadNotificationCount ?? 0) }}</span>
        </x-responsive-nav-link>
    </div>
</details>

{{-- Plans --}}
<x-responsive-nav-link :href="route('plans.index')">
    Plans
</x-responsive-nav-link>

        </div>

        <!-- Language switcher (mobile) -->
        <div class="px-4 pt-2 pb-2 border-t border-red-800/80">
            <x-language-switcher :on-red="true" />
        </div>

        <!-- Responsive Settings Options -->
        @auth
        <div class="pt-4 pb-1 border-t border-red-800/80 bg-red-800/50">
            <div class="px-4">
                <div class="font-medium text-base text-white">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-white/75">{{ Auth::user()->email }}</div>
                @if (auth()->user()->matrimonyProfile && filled(data_get($planUsageSummary, 'subscription_state_label')))
                    <div class="mt-2 text-xs font-medium text-white/90">
                        {{ data_get($planUsageSummary, 'subscription_state_label') }}
                    </div>
                @endif
            </div>
            <div class="mt-3 space-y-1">
                <div class="px-4 pt-2 text-xs font-semibold text-white/70">
                    {{ __('nav.personal_menu_profile_section') }}
                </div>

                @if (auth()->user()->matrimonyProfile)
                    <x-responsive-nav-link :href="route('matrimony.profile.upload-photo')">
                        {{ __('Upload Photos') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link :href="route('matrimony.profile.show', auth()->user()->matrimonyProfile->id)">
                        {{ __('nav.my_profile') }}
                    </x-responsive-nav-link>

                    <x-responsive-nav-link :href="route('interests.index')">
                        {{ __('nav.interests') }}
                    </x-responsive-nav-link>
                @endif

                <x-responsive-nav-link :href="route('contact-inbox.index')">
                    {{ __('nav.contact_requests') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('shortlist.index')">
                    {{ __('nav.shortlist') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('intake.index')">
                    {{ __('nav.my_biodata_uploads') }}
                </x-responsive-nav-link>

                <x-responsive-nav-link :href="route('blocks.index')">
                    {{ __('nav.blocked') }}
                </x-responsive-nav-link>

                @if (auth()->user()->isAnyAdmin())
                    <div class="px-4 pt-3 text-xs font-semibold text-white/70">
                        {{ __('nav.admin_section') }}
                    </div>
                    <x-responsive-nav-link :href="route('admin.dashboard')">
                        {{ __('nav.admin_panel') }}
                    </x-responsive-nav-link>
                @endif

                <div class="px-4 pt-3 text-xs font-semibold text-white/70">
                    {{ __('Settings') }}
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

                @if (auth()->user()->matrimonyProfile)
                    <x-responsive-nav-link :href="route('user.settings.my-plan')">
                        {{ __('user_plan.my_plan_hub_title') }}
                    </x-responsive-nav-link>
                @endif

                <x-responsive-nav-link :href="route('notifications.index')">
                    {{ __('Manage Notifications') }}
                </x-responsive-nav-link>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link :href="route('logout')"
                        onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
        @endauth
    </div>
</nav>

{{-- Notification count polling (no WebSockets, no push) --}}
@auth
<script>
(function() {
    const POLL_INTERVAL = 30000; // 30 seconds

    function applyBadge(el, count) {
        if (!el) return;
        el.textContent = count > 99 ? '99+' : String(count);
        el.classList.toggle('hidden', !(count > 0));
    }

    function updateNotificationCount(badge, badgeMobile) {
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
            applyBadge(badge, count);
            applyBadge(badgeMobile, count);
        })
        .catch(err => {
            // Silent fail - don't disrupt user experience
            console.warn('Notification poll failed:', err);
        });
    }

    function updateMemberWidgetCounts(elements) {
        fetch('{{ route("member.widgets.counts") }}', {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (!data || data.ok !== true) return;
            const chatCount = Number(data.chat_unread || 0);
            const interestsCount = Number(data.interests_pending || 0);
            const whoViewedCount = Number(data.who_viewed_count || 0);
            applyBadge(elements.chatBadge, chatCount);
            applyBadge(elements.chatBadgeMobile, chatCount);
            applyBadge(elements.chatDockBadge, chatCount);
            applyBadge(elements.stickyChatBadge, chatCount);
            applyBadge(elements.interestsBadge, interestsCount);
            applyBadge(elements.interestsBadgeMobile, interestsCount);
            applyBadge(elements.stickyInterestsBadge, interestsCount);
            applyBadge(elements.whoViewedBadge, whoViewedCount);
            applyBadge(elements.whoViewedBadgeMobile, whoViewedCount);
            const activityCount = interestsCount + whoViewedCount;
            applyBadge(elements.activityMainBadge, activityCount);
            applyBadge(elements.stickyActivityBadge, activityCount);

            document.dispatchEvent(new CustomEvent('member-widget-counts-updated', {
                detail: {
                    chat_unread: chatCount,
                    interests_pending: interestsCount,
                    who_viewed_count: whoViewedCount
                }
            }));
        })
        .catch(() => {});
    }

    function startMemberWidgetPolling() {
        const badge = document.getElementById('notification-badge');
        const badgeMobile = document.getElementById('notification-badge-mobile');
        const chatBadge = document.getElementById('chat-badge');
        const chatBadgeMobile = document.getElementById('chat-badge-mobile');
        const chatDockBadge = document.getElementById('chat-dock-badge');
        const interestsBadge = document.getElementById('interests-received-badge');
        const interestsBadgeMobile = document.getElementById('interests-received-badge-mobile');
        const whoViewedBadge = document.getElementById('who-viewed-badge');
        const whoViewedBadgeMobile = document.getElementById('who-viewed-badge-mobile');
        const activityMainBadge = document.getElementById('activity-main-badge');
        const stickyChatBadge = document.getElementById('sticky-chat-badge');
        const stickyInterestsBadge = document.getElementById('sticky-interests-badge');
        const stickyActivityBadge = document.getElementById('sticky-activity-badge');
        const whoViewedBubbleRoot = document.getElementById('who-viewed-bubble-root');

        const widgets = {
            chatBadge,
            chatBadgeMobile,
            chatDockBadge,
            stickyChatBadge,
            interestsBadge,
            interestsBadgeMobile,
            stickyInterestsBadge,
            whoViewedBadge,
            whoViewedBadgeMobile,
            activityMainBadge,
            stickyActivityBadge,
        };

        if (
            !badge && !badgeMobile
            && !chatBadge && !chatBadgeMobile && !chatDockBadge
            && !interestsBadge && !interestsBadgeMobile && !stickyInterestsBadge
            && !whoViewedBadge && !whoViewedBadgeMobile
            && !activityMainBadge && !stickyChatBadge && !stickyActivityBadge
            && !whoViewedBubbleRoot
        ) {
            return;
        }

        updateNotificationCount(badge, badgeMobile);
        updateMemberWidgetCounts(widgets);
        setInterval(function () { updateNotificationCount(badge, badgeMobile); }, POLL_INTERVAL);
        setInterval(function () { updateMemberWidgetCounts(widgets); }, POLL_INTERVAL);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startMemberWidgetPolling);
    } else {
        startMemberWidgetPolling();
    }
})();
</script>
@endauth
