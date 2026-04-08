{{-- Shaadi-style secondary bar: contextual links for the active main section (desktop only). --}}
@php
    $section = $navMainSection ?? 'none';
@endphp
@if ($section !== 'none')
    <div class="hidden md:block bg-white border-b border-gray-200 dark:bg-gray-950 dark:border-gray-800 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="flex flex-wrap items-stretch gap-x-1 gap-y-0" aria-label="{{ __('nav.sub_navigation') }}">
                @if ($section === 'home')
                    {{-- Dashboard + same items as user menu “My profile & lists” (no Edit / Partner prefs / Settings here) --}}
                    <a href="{{ route('dashboard') }}"
                       class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.dashboard') }}
                    </a>
                    @auth
                        @if (auth()->user()->matrimonyProfile)
                            <a href="{{ route('matrimony.profile.upload-photo') }}"
                               class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('matrimony.profile.upload-photo') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                                {{ __('Upload Photos') }}
                            </a>
                            <a href="{{ route('matrimony.profile.show', auth()->user()->matrimonyProfile->id) }}"
                               class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('matrimony.profile.show') && ($isOwnProfileShow ?? false) ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                                {{ __('nav.my_profile') }}
                            </a>
                        @else
                            <a href="{{ route('matrimony.profile.wizard.section', ['section' => 'basic-info']) }}"
                               class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('matrimony.profile.wizard*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                                {{ __('nav.create_profile') }}
                            </a>
                        @endif
                        <a href="{{ route('contact-inbox.index') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('contact-inbox.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            {{ __('nav.contact_requests') }}
                        </a>
                        <a href="{{ route('shortlist.index') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('shortlist.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            {{ __('nav.shortlist') }}
                        </a>
                        <a href="{{ route('intake.index') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('intake.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            {{ __('nav.my_biodata_uploads') }}
                        </a>
                        <a href="{{ route('blocks.index') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('blocks.index') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            {{ __('nav.blocked') }}
                        </a>
                    @endauth
                @elseif ($section === 'discover')
                    <a href="{{ route('matrimony.profiles.index') }}"
                       class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('matrimony.profiles.index') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.search_profiles') }}
                    </a>
                    @auth
                        @if (auth()->user()->matrimonyProfile)
                            <a href="{{ route('matches.index') }}"
                               class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('matches.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                                {{ __('matching.nav_matches') }}
                            </a>
                        @endif
                    @endauth
                @elseif ($section === 'connect')
                    <a href="{{ route('interests.received') }}"
                       class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('interests.received') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.inbox') }}
                    </a>
                    <a href="{{ route('interests.sent') }}"
                       class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('interests.sent') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.interests_sent') }}
                    </a>
                    <a href="{{ route('chat.index') }}"
                       class="inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('chat.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('Chat') }}
                        @if (isset($chatUnreadCount) && $chatUnreadCount > 0)
                            <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">{{ $chatUnreadCount > 99 ? '99+' : $chatUnreadCount }}</span>
                        @endif
                    </a>
                    <a href="{{ route('mediation-inbox.index') }}"
                       class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('mediation-inbox.*') || request()->routeIs('mediation-requests.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('mediation.nav_link') }}
                    </a>
                @elseif ($section === 'activity')
                    <a href="{{ route('who-viewed.index') }}"
                       class="inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('who-viewed.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        <span>{{ __('nav.who_viewed_me') }}</span>
                        <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">{{ __('nav.premium') }}</span>
                    </a>
                    <a href="{{ route('notifications.index') }}"
                       class="inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('notifications.index', 'notifications.show') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.notifications') }}
                        @if (isset($unreadNotificationCount) && $unreadNotificationCount > 0)
                            <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>
                        @endif
                    </a>
                @elseif ($section === 'plans')
                    <a href="{{ route('plans.index') }}"
                       class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('plans.index') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.browse_plans') }}
                    </a>
                @endif
            </nav>
        </div>
    </div>
@endif
