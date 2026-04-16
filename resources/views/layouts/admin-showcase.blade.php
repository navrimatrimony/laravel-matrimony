@extends('layouts.admin')

@php
    $showcaseNavItems = [
        [
            'route' => 'admin.showcase-dashboard.index',
            'match' => ['admin.showcase-dashboard.*'],
            'label' => 'Activity',
            'sub' => 'Views & interests',
            'icon' => 'M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6',
        ],
        [
            'route' => 'admin.showcase-search-settings.index',
            'match' => ['admin.showcase-search-settings.*'],
            'label' => 'Member search',
            'sub' => 'Listing visibility',
            'icon' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
        ],
        [
            'route' => 'admin.view-back-settings.index',
            'match' => ['admin.view-back-settings.*'],
            'label' => 'View-back',
            'sub' => 'Showcase → real views',
            'icon' => 'M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
        ],
        [
            'route' => 'admin.showcase-interest-settings.index',
            'match' => ['admin.showcase-interest-settings.*'],
            'label' => 'Interest rules',
            'sub' => 'Showcase → real',
            'icon' => 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z',
        ],
        [
            'route' => 'admin.showcase-chat-settings.index',
            'match' => ['admin.showcase-chat-settings.*'],
            'label' => 'Chat automation',
            'sub' => 'AI / orchestration',
            'icon' => 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
        ],
        [
            'route' => 'admin.showcase-conversations.index',
            'match' => ['admin.showcase-conversations.*', 'admin.showcase-chat.debug'],
            'label' => 'Conversations',
            'sub' => 'Monitor & debug',
            'icon' => 'M7.5 8.25h9m-9 3H12m-4.5 3h6M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
        ],
        [
            'route' => 'admin.showcase-profile.bulk-create',
            'match' => ['admin.showcase-profile.*'],
            'label' => 'Bulk profiles',
            'sub' => 'Create 1–50',
            'icon' => 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM19.5 6v2.25a2.25 2.25 0 01-2.25 2.25H15a2.25 2.25 0 01-2.25-2.25V6M3.75 15.75h2.25A2.25 2.25 0 008.25 18v2.25a2.25 2.25 0 01-2.25 2.25H3.75a2.25 2.25 0 01-2.25-2.25V18a2.25 2.25 0 012.25-2.25zM15.75 15.75h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25h-2.25a2.25 2.25 0 01-2.25-2.25v-2.25a2.25 2.25 0 012.25-2.25z',
        ],
        [
            'route' => 'admin.auto-showcase-settings.edit',
            'match' => ['admin.auto-showcase-settings.*'],
            'label' => 'Auto-showcase',
            'sub' => 'Engine & districts',
            'icon' => 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423L16.5 15.75l.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z',
        ],
    ];
@endphp

@section('content')
<style>
    .showcase-engine-bar {
        background: linear-gradient(92deg, #4c1d95 0%, #6d28d9 28%, #7c3aed 55%, #a855f7 78%, #c026d3 100%);
        box-shadow: 0 12px 40px -14px rgba(91, 33, 182, 0.45);
    }
    .showcase-engine-bar::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(255,255,255,0.1) 0%, transparent 42%);
        pointer-events: none;
        border-radius: inherit;
    }
    .showcase-h-tab {
        position: relative;
        border-radius: 0.75rem;
        transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
    }
    .showcase-h-tab:not(.showcase-h-tab--active) {
        color: rgba(255, 255, 255, 0.88);
    }
    .showcase-h-tab:not(.showcase-h-tab--active):hover {
        background: rgba(255, 255, 255, 0.12);
    }
    .showcase-h-tab--active {
        background: #fff;
        color: rgb(76 29 149);
        box-shadow: 0 4px 14px -4px rgba(0, 0, 0, 0.25);
    }
    .showcase-h-tab--active .showcase-h-tab__sub {
        color: rgba(91, 33, 182, 0.78);
    }
    .showcase-h-tab__icon {
        flex-shrink: 0;
    }
</style>

<div class="max-w-[1680px] mx-auto space-y-6">
    <div class="relative showcase-engine-bar rounded-2xl overflow-hidden ring-1 ring-violet-400/30">
        <div class="relative flex flex-col lg:flex-row lg:items-stretch lg:min-h-[4.25rem]">
            <a href="{{ route('admin.showcase-dashboard.index') }}" class="group flex shrink-0 items-center gap-2 px-4 py-3 lg:py-2 lg:w-[11.5rem] border-b border-white/10 lg:border-b-0 lg:border-r text-left hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/80 rounded-xl transition-colors">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25 group-hover:bg-white/20">
                    <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" /></svg>
                </span>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-violet-100/90">Admin</p>
                    <p class="text-sm font-bold text-white leading-tight truncate group-hover:underline">Showcase engine</p>
                </div>
            </a>
            <nav class="relative w-full flex-1 min-w-0 px-2 pb-2 pt-1 lg:py-2 lg:pr-3 lg:pl-2 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 content-start" aria-label="Showcase engine sections">
                @foreach ($showcaseNavItems as $item)
                    @php
                        $isActive = collect($item['match'])->contains(fn ($p) => request()->routeIs($p));
                    @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        title="{{ $item['label'] }} — {{ $item['sub'] }}"
                        class="showcase-h-tab flex flex-row items-center gap-2 px-2 py-1.5 min-w-0 {{ $isActive ? 'showcase-h-tab--active' : '' }}"
                    >
                        <span class="showcase-h-tab__icon flex h-7 w-7 shrink-0 items-center justify-center rounded-lg {{ $isActive ? 'bg-violet-100 text-violet-700' : 'bg-white/10 text-white ring-1 ring-white/20' }}">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" /></svg>
                        </span>
                        <span class="min-w-0 text-left">
                            <span class="block text-[11px] sm:text-[12px] font-semibold leading-tight">{{ $item['label'] }}</span>
                            <span class="showcase-h-tab__sub block text-[9px] sm:text-[10px] leading-snug mt-0.5 line-clamp-2 {{ $isActive ? '' : 'text-white/65' }}">{{ $item['sub'] }}</span>
                        </span>
                    </a>
                @endforeach
            </nav>
        </div>
    </div>

    <div class="showcase-engine-content min-w-0">
        @yield('showcase_content')
    </div>
</div>
@endsection
