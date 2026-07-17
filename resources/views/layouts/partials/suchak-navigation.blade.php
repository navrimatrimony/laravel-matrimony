@php
    use App\Support\Suchak\SuchakMvpFeatures;

    $suchakUser = auth()->user();
    $suchakName = $suchakAccount?->suchak_name ?: $suchakUser?->name;
    $statusLabel = \Illuminate\Support\Str::headline((string) ($suchakAccount?->verification_status ?? 'pending'));
    $hasMatrimonyProfile = (bool) $suchakUser?->matrimonyProfile;
    $visibleDashboardTabs = SuchakMvpFeatures::visibleDashboardTabs();
    $dashboardTabKeys = $visibleDashboardTabs;
    $dashboardHasBusinessFilters = request('business_q', '') !== ''
        || request('note_type') !== null
        || request('ledger_status') !== null;
    $requestedDashboardTab = (string) request('dashboard_tab', '');
    $defaultDashboardTab = in_array('work', $dashboardTabKeys, true)
        ? 'work'
        : ($dashboardTabKeys[0] ?? 'work');
    $activeDashboardTab = in_array($requestedDashboardTab, $dashboardTabKeys, true)
        ? $requestedDashboardTab
        : ($dashboardHasBusinessFilters && in_array('profiles', $dashboardTabKeys, true) ? 'profiles' : $defaultDashboardTab);
    $dashboardTabUrl = static fn (string $tab): string => route(
        'suchak.dashboard',
        $tab === 'work' ? [] : ['dashboard_tab' => $tab],
    );

    $suchakMainSection = 'dashboard';
    if (request()->routeIs('suchak.intakes.*', 'suchak.manual-profiles.*', 'suchak.search.*', 'suchak.collaborations.*')) {
        $suchakMainSection = 'work';
    } elseif (request()->routeIs('suchak.offline-camps.*')) {
        $suchakMainSection = 'network';
    } elseif (request()->routeIs('suchak.export-retention.*', 'suchak.training-academy.*')) {
        $suchakMainSection = 'tools';
    } elseif (request()->routeIs('suchak.register.*', 'suchak.account-settings.*', 'user.settings.*', 'notifications.*')) {
        $suchakMainSection = 'account';
    }

    $mainItems = collect([
        ['label' => 'Dashboard', 'href' => route('suchak.dashboard'), 'section' => 'dashboard'],
        ['label' => 'Work', 'href' => route('suchak.intakes.create'), 'section' => 'work'],
        ['label' => 'Network', 'href' => route('suchak.collaborations.index'), 'section' => 'network'],
        ['label' => 'Tools', 'href' => route('suchak.export-retention.index'), 'section' => 'tools'],
    ])->filter(fn (array $item): bool => SuchakMvpFeatures::navSectionVisible($item['section']))->values()->all();

    $dashboardSubItems = [
        'profile' => ['label' => 'Profile setup', 'href' => $dashboardTabUrl('profile')],
        'work' => ['label' => 'Today', 'href' => $dashboardTabUrl('work')],
        'profiles' => ['label' => 'Customers', 'href' => $dashboardTabUrl('profiles')],
        'requests' => ['label' => 'Requests', 'href' => $dashboardTabUrl('requests')],
        'money' => ['label' => 'Money', 'href' => $dashboardTabUrl('money')],
        'sharing' => ['label' => 'Sharing', 'href' => $dashboardTabUrl('sharing')],
        'records' => ['label' => 'Records', 'href' => $dashboardTabUrl('records')],
    ];
    $subItemsBySection = [
        'dashboard' => collect($dashboardSubItems)
            ->only($visibleDashboardTabs)
            ->map(fn (array $item, string $tab): array => array_merge($item, [
                'active' => request()->routeIs('suchak.dashboard') && $activeDashboardTab === $tab,
            ]))
            ->values()
            ->all(),
        'work' => collect([
            ['label' => 'Upload / Paste', 'href' => route('suchak.intakes.create'), 'key' => 'upload'],
            ['label' => 'Manual Form', 'href' => route('suchak.manual-profiles.create'), 'key' => 'manual'],
            ['label' => 'Find Matches', 'href' => route('suchak.search.index'), 'key' => 'search'],
            ['label' => 'Collaborations', 'href' => route('suchak.collaborations.index'), 'key' => 'collaborations'],
        ])->filter(function (array $item): bool {
            if ($item['key'] === 'collaborations') {
                return SuchakMvpFeatures::navSubitemVisible('collaborations');
            }

            return true;
        })->map(fn (array $item): array => [
            'label' => $item['label'],
            'href' => $item['href'],
            'active' => match ($item['key']) {
                'upload' => request()->routeIs('suchak.intakes.*'),
                'manual' => request()->routeIs('suchak.manual-profiles.*'),
                'search' => request()->routeIs('suchak.search.*'),
                'collaborations' => request()->routeIs('suchak.collaborations.*'),
                default => false,
            },
        ])->values()->all(),
        'network' => collect([
            ['label' => 'Collaborations', 'href' => route('suchak.collaborations.index'), 'key' => 'collaborations'],
            ['label' => 'Offline Camps', 'href' => route('suchak.offline-camps.index'), 'key' => 'offline_camps'],
        ])->filter(fn (array $item): bool => SuchakMvpFeatures::navSubitemVisible($item['key']))
            ->map(fn (array $item): array => [
                'label' => $item['label'],
                'href' => $item['href'],
                'active' => match ($item['key']) {
                    'collaborations' => request()->routeIs('suchak.collaborations.*'),
                    'offline_camps' => request()->routeIs('suchak.offline-camps.*'),
                    default => false,
                },
            ])->values()->all(),
        'tools' => collect([
            ['label' => 'Export / Retention', 'href' => route('suchak.export-retention.index'), 'key' => 'export_retention'],
            ['label' => 'Training Academy', 'href' => route('suchak.training-academy.index'), 'key' => 'training_academy'],
        ])->filter(fn (array $item): bool => SuchakMvpFeatures::navSubitemVisible($item['key']))
            ->map(fn (array $item): array => [
                'label' => $item['label'],
                'href' => $item['href'],
                'active' => match ($item['key']) {
                    'export_retention' => request()->routeIs('suchak.export-retention.*'),
                    'training_academy' => request()->routeIs('suchak.training-academy.*'),
                    default => false,
                },
            ])->values()->all(),
        'account' => [
            ['label' => 'Suchak privacy & public listing', 'href' => route('suchak.register.status'), 'active' => request()->routeIs('suchak.register.*')],
            ['label' => 'Contact numbers', 'href' => route('suchak.account-settings.edit'), 'active' => request()->routeIs('suchak.account-settings.*')],
            ['label' => 'Account & Security', 'href' => route('user.settings.security'), 'active' => request()->routeIs('user.settings.security')],
            ['label' => 'Notification preferences', 'href' => route('user.settings.notifications'), 'active' => request()->routeIs('user.settings.notifications')],
            ['label' => 'Notification inbox', 'href' => route('notifications.index'), 'active' => request()->routeIs('notifications.index', 'notifications.show')],
        ],
    ];
    $activeSubItems = $subItemsBySection[$suchakMainSection] ?? $subItemsBySection['dashboard'];
    $navMainCaret = 'pointer-events-none absolute bottom-0 left-1/2 z-30 h-0 w-0 -translate-x-1/2 translate-y-px border-x-[9px] border-x-transparent border-b-[10px] border-b-white drop-shadow-sm';
    $navMainLink = static function (bool $active): string {
        $base = 'relative flex h-full items-center px-1 leading-none transition';

        return $active
            ? $base.' text-base font-black text-yellow-300 dark:text-yellow-200'
            : $base.' text-sm font-medium text-white/90 hover:text-white';
    };
    $subLinkClass = static fn (bool $active): string => $active
        ? 'inline-flex items-center border-b-2 border-red-600 px-3 py-3 text-sm font-semibold text-red-600 dark:border-red-500 dark:text-red-400'
        : 'inline-flex items-center border-b-2 border-transparent px-3 py-3 text-sm font-medium text-gray-600 transition hover:text-red-700 dark:text-gray-400 dark:hover:text-red-400';
    $mobileSubLinkClass = static fn (bool $active): string => $active
        ? 'block rounded-md bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-200'
        : 'block rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800';
@endphp

<nav x-data="{ open: false }" data-suchak-nav class="border-b border-red-900 bg-red-700 shadow-sm dark:border-red-950 dark:bg-red-900">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between gap-4 lg:h-12">
            <div class="flex min-w-0 items-center self-stretch">
                <a href="{{ route('suchak.dashboard') }}" class="flex shrink-0 items-center">
                    <x-application-logo class="block h-9 w-auto fill-current text-white drop-shadow-sm lg:h-8" />
                </a>

                <div class="ml-8 hidden h-full min-w-0 items-center gap-8 md:flex">
                    @foreach ($mainItems as $item)
                        @php($isActive = $suchakMainSection === $item['section'])
                        <a href="{{ $item['href'] }}" class="{{ $navMainLink($isActive) }}">
                            <span class="whitespace-nowrap">{{ $item['label'] }}</span>
                            @if ($isActive)
                                <span class="{{ $navMainCaret }}" aria-hidden="true"></span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="hidden items-center gap-2 md:flex">
                <x-language-switcher :on-red="true" />
                <x-dropdown align="right" width="56">
                    <x-slot name="trigger">
                        <button class="inline-flex max-w-[14rem] items-center rounded-md border border-white/25 bg-red-800/45 px-3 py-2 text-sm font-medium leading-4 text-white transition hover:bg-red-900/70 focus:outline-none lg:py-1.5">
                            <span class="truncate">{{ $suchakName }}</span>
                            <svg class="ms-1 h-4 w-4 shrink-0 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="rounded-lg bg-white py-1 shadow-lg dark:bg-gray-800">
                            <div class="px-4 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                Suchak account
                            </div>
                            <div class="px-4 pb-2 text-xs text-gray-600 dark:text-gray-300">
                                Status: {{ $statusLabel }}
                            </div>

                            @if (Route::has('suchak.register.status'))
                                <x-dropdown-link :href="route('suchak.register.status')">
                                    Suchak privacy & public listing
                                </x-dropdown-link>
                            @endif
                            @if (Route::has('suchak.account-settings.edit'))
                                <x-dropdown-link :href="route('suchak.account-settings.edit')">
                                    Contact numbers
                                </x-dropdown-link>
                            @endif

                            <div class="my-1 border-t border-gray-200 dark:border-gray-600"></div>
                            <div class="px-4 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                Settings
                            </div>
                            <x-dropdown-link :href="route('user.settings.security')">
                                Account & Security
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('user.settings.notifications')">
                                Notification preferences
                            </x-dropdown-link>
                            <x-dropdown-link :href="route('notifications.index')">
                                Notification inbox
                            </x-dropdown-link>

                            @if ($hasMatrimonyProfile)
                                <div class="my-1 border-t border-gray-200 dark:border-gray-600"></div>
                                <div class="px-4 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                    Candidate profile settings
                                </div>
                                <x-dropdown-link :href="route('user.settings.privacy')">
                                    Privacy & Visibility
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('user.settings.communication')">
                                    Communication Preferences
                                </x-dropdown-link>
                            @endif

                            @if ($suchakUser?->isAnyAdmin() && Route::has('admin.suchak.dashboard'))
                                <div class="my-1 border-t border-gray-200 dark:border-gray-600"></div>
                                <x-dropdown-link :href="route('admin.suchak.dashboard')" class="font-semibold text-indigo-700 dark:text-indigo-300">
                                    Admin Suchak
                                </x-dropdown-link>
                            @endif

                            <div class="my-1 border-t border-gray-200 dark:border-gray-600"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                    {{ __('Log Out') }}
                                </x-dropdown-link>
                            </form>
                        </div>
                    </x-slot>
                </x-dropdown>
            </div>

            <button @click="open = ! open" class="inline-flex items-center justify-center rounded-md p-2 text-white transition hover:bg-red-800 focus:outline-none md:hidden" aria-label="Open Suchak menu">
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    <div data-suchak-subnav class="hidden border-b border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-950 md:block">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <nav class="flex flex-nowrap items-stretch gap-x-1 overflow-x-auto [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Suchak submenu">
                @foreach ($activeSubItems as $item)
                    <a href="{{ $item['href'] }}" class="{{ $subLinkClass((bool) $item['active']) }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>
    </div>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden border-t border-red-800 bg-white md:hidden dark:bg-gray-950">
        <div class="space-y-3 px-3 py-3">
            @foreach ($mainItems as $mainItem)
                @php($mainActive = $suchakMainSection === $mainItem['section'])
                <div class="rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-800 dark:bg-gray-900">
                    <a href="{{ $mainItem['href'] }}" class="{{ $mobileSubLinkClass($mainActive) }}">
                        {{ $mainItem['label'] }}
                    </a>
                    @if (($subItemsBySection[$mainItem['section']] ?? []) !== [])
                        <div class="mt-1 space-y-1 pl-3">
                            @foreach ($subItemsBySection[$mainItem['section']] as $subItem)
                                <a href="{{ $subItem['href'] }}" class="{{ $mobileSubLinkClass((bool) $subItem['active']) }}">
                                    {{ $subItem['label'] }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-800">
            <x-language-switcher :on-red="false" />
        </div>

        <div class="border-t border-gray-200 bg-gray-50 px-4 py-3 text-gray-900 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100">
            <div class="text-sm font-semibold">{{ $suchakName }}</div>
            <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">Status: {{ $statusLabel }}</div>
            <div class="mt-3 space-y-1">
                @if (Route::has('suchak.register.status'))
                    <a href="{{ route('suchak.register.status') }}" class="{{ $mobileSubLinkClass(request()->routeIs('suchak.register.*')) }}">
                        Suchak privacy & public listing
                    </a>
                @endif
                @if (Route::has('suchak.account-settings.edit'))
                    <a href="{{ route('suchak.account-settings.edit') }}" class="{{ $mobileSubLinkClass(request()->routeIs('suchak.account-settings.*')) }}">
                        Contact numbers
                    </a>
                @endif

                <div class="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Settings
                </div>
                <a href="{{ route('user.settings.security') }}" class="{{ $mobileSubLinkClass(request()->routeIs('user.settings.security')) }}">
                    Account & Security
                </a>
                <a href="{{ route('user.settings.notifications') }}" class="{{ $mobileSubLinkClass(request()->routeIs('user.settings.notifications')) }}">
                    Notification preferences
                </a>
                <a href="{{ route('notifications.index') }}" class="{{ $mobileSubLinkClass(request()->routeIs('notifications.index', 'notifications.show')) }}">
                    Notification inbox
                </a>

                @if ($hasMatrimonyProfile)
                    <div class="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Candidate profile settings
                    </div>
                    <a href="{{ route('user.settings.privacy') }}" class="{{ $mobileSubLinkClass(request()->routeIs('user.settings.privacy')) }}">
                        Privacy & Visibility
                    </a>
                    <a href="{{ route('user.settings.communication') }}" class="{{ $mobileSubLinkClass(request()->routeIs('user.settings.communication')) }}">
                        Communication Preferences
                    </a>
                @endif

                @if ($suchakUser?->isAnyAdmin() && Route::has('admin.suchak.dashboard'))
                    <a href="{{ route('admin.suchak.dashboard') }}" class="{{ $mobileSubLinkClass(false) }}">
                        Admin Suchak
                    </a>
                @endif

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <a href="{{ route('logout') }}" class="{{ $mobileSubLinkClass(false) }}" onclick="event.preventDefault(); this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </a>
                </form>
            </div>
        </div>
    </div>
</nav>
