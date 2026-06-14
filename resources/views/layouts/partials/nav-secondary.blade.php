{{-- Shaadi-style secondary bar: contextual links for the active main section (desktop only). --}}
@php
    $section = $navMainSection ?? 'none';
@endphp
@if ($section !== 'none')
    <div class="block bg-white border-b border-gray-200 dark:bg-gray-950 dark:border-gray-800 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="flex flex-nowrap overflow-x-auto items-stretch gap-x-1 gap-y-0 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="{{ __('nav.sub_navigation') }}">
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
                        @if (config('referral.enabled', true))
                            <a href="{{ route('referrals.index') }}"
                               class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('referrals.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                                {{ __('nav.my_referrals') }}
                            </a>
                        @endif
                        <a href="{{ route('who-viewed.index') }}"
                           class="inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('who-viewed.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            <span>{{ __('nav.who_viewed_me') }}</span>
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">{{ __('nav.premium') }}</span>
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
                @elseif ($section === 'suchak')
                    @php($suchakSecondaryAccount = auth()->check() ? auth()->user()->suchakAccount : null)
                    <a href="{{ route('suchak.home') }}"
                       class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('suchak.home') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('suchak.nav.centre') }}
                    </a>
                    @if ($suchakSecondaryAccount)
                        <a href="{{ route('suchak.dashboard') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('suchak.dashboard') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            {{ __('suchak.nav.dashboard') }}
                        </a>
                        <a href="{{ route('suchak.intakes.create') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('suchak.intakes.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            {{ __('suchak.nav.create_intake_source') }}
                        </a>
                        <a href="{{ route('suchak.search.index') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('suchak.search.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            {{ __('suchak.nav.masked_search') }}
                        </a>
                    @else
                        <a href="{{ route('suchak.register.info') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('suchak.register.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            {{ __('suchak.nav.registration') }}
                        </a>
                        <a href="{{ route('login') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400">
                            {{ __('suchak.nav.login') }}
                        </a>
                    @endif
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
                    <a href="{{ route('interests.index') }}"
                       class="inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('interests.index', 'interests.sent', 'interests.received') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.interests') }}
                        <span
                            id="interests-received-badge"
                            class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white {{ (int) ($memberActivityCounts['interests_pending'] ?? 0) > 0 ? '' : 'hidden' }}"
                        >{{ (int) ($memberActivityCounts['interests_pending'] ?? 0) > 99 ? '99+' : (int) ($memberActivityCounts['interests_pending'] ?? 0) }}</span>
                    </a>
                    <a href="{{ route('chat.index') }}"
                       data-open-chat-launcher
                       data-open-chat-tab="chats"
                       class="inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('chat.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('Chat') }}
                        <span
                            id="chat-badge"
                            class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white {{ (int) ($chatUnreadCount ?? 0) > 0 ? '' : 'hidden' }}"
                        >{{ (int) ($chatUnreadCount ?? 0) > 99 ? '99+' : (int) ($chatUnreadCount ?? 0) }}</span>
                    </a>
                    <a href="{{ route('mediation-inbox.index') }}"
                       class="inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('mediation-inbox.*') || request()->routeIs('mediation-requests.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        <svg class="h-4 w-4 shrink-0 text-[#25D366]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        <span>{{ __('mediation.nav_link') }}</span>
                    </a>
                    <a href="{{ route('help-centre.index') }}"
                       class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('help-centre.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.help_centre') }}
                    </a>
                @elseif ($section === 'activity')
                    <a href="{{ route('who-viewed.index') }}"
                       class="inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('who-viewed.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        <span>{{ __('nav.who_viewed_me') }}</span>
                        <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">{{ __('nav.premium') }}</span>
                        <span
                            id="who-viewed-badge"
                            class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white {{ (int) ($memberActivityCounts['who_viewed_count'] ?? 0) > 0 ? '' : 'hidden' }}"
                        >{{ (int) ($memberActivityCounts['who_viewed_count'] ?? 0) > 99 ? '99+' : (int) ($memberActivityCounts['who_viewed_count'] ?? 0) }}</span>
                    </a>
                    @auth
                        @if (auth()->user()->matrimonyProfile)
                            <a href="{{ route('interests.index') }}"
                               class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('interests.index', 'interests.sent', 'interests.received') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                                {{ __('nav.interests') }}
                            </a>
                        @endif
                    @endauth
                    <a href="{{ route('notifications.index') }}"
                       class="inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('notifications.index', 'notifications.show') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.notifications') }}
                        <span
                            id="notification-badge"
                            class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white {{ (int) ($unreadNotificationCount ?? 0) > 0 ? '' : 'hidden' }}"
                        >{{ (int) ($unreadNotificationCount ?? 0) > 99 ? '99+' : (int) ($unreadNotificationCount ?? 0) }}</span>
                    </a>
                @elseif ($section === 'plans')
                    <a href="{{ route('plans.index') }}"
                       class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('plans.index') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                        {{ __('nav.browse_plans') }}
                    </a>
                    @auth
                        @if (auth()->user()->matrimonyProfile)
                            <a href="{{ route('user.settings.my-plan') }}"
                               class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('user.settings.my-plan') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                                {{ __('user_plan.my_plan_hub_title') }}
                            </a>
                        @endif
                        <a href="{{ route('user.settings.privacy') }}"
                           class="inline-flex items-center border-b-2 px-3 py-3 text-sm font-medium transition {{ request()->routeIs('user.settings.*') ? 'border-red-600 text-red-600 dark:border-red-500 dark:text-red-400' : 'border-transparent text-gray-600 hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400' }}">
                            {{ __('Settings') }}
                        </a>
                    @endauth
                @endif
            </nav>
        </div>
    </div>
@endif
