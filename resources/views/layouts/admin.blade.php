<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin — {{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .admin-sidebar .nav-link.active { background: rgb(79 70 229); color: white; }
        .admin-sidebar .nav-link.active svg { color: white; }
        .admin-sidebar .nav-link.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
        .admin-sidebar .nav-group-btn:hover { background: rgb(55 65 81); }
        .admin-sidebar .nav-link:not(.disabled):hover { background: rgb(55 65 81); }
        .admin-sidebar .nav-chevron { transition: transform 0.2s ease; }
    </style>
</head>
<body class="font-sans antialiased bg-gray-100 overflow-x-hidden">
    <div class="min-h-screen flex">
        <aside class="admin-sidebar w-64 flex-shrink-0 bg-gray-800 text-gray-300 fixed inset-y-0 left-0 z-30 flex flex-col min-h-screen h-screen" style="width: 16rem;">
            <div class="p-4 border-b border-gray-700 flex-shrink-0">
                <a href="{{ route('admin.dashboard') }}" class="text-lg font-semibold text-white">Admin Panel</a>
                <p class="text-xs text-gray-400 mt-1">Moderation &amp; settings</p>
            </div>
            <nav class="p-4 flex-1 overflow-y-auto overscroll-contain space-y-2">
                @php
                    $moderationOpen = request()->routeIs('admin.abuse-reports.*') || request()->routeIs('admin.conflict-records.*');
                    $settingsOpen = request()->routeIs('admin.demo-search-settings.*') || request()->routeIs('admin.view-back-settings.*') || request()->routeIs('admin.notifications.*') || request()->routeIs('admin.profile-field-config.*') || request()->routeIs('admin.field-registry.*');
                @endphp

                {{-- 1) Dashboard — default expanded --}}
                <div class="nav-group" x-data="{ open: true }">
                    <button type="button" @click="open = !open" class="nav-group-btn w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-left text-gray-300">
                        <svg class="w-5 h-5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                        <span>Dashboard</span>
                        <svg class="w-4 h-4 ml-auto nav-chevron shrink-0" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <ul class="nav-group-items mt-0.5 ml-4 pl-3 border-l border-gray-700 space-y-0.5" x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
                        <li><a href="{{ route('admin.dashboard') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg> Overview</a></li>
                    </ul>
                </div>

                {{-- 2) Profiles — default expanded --}}
                <div class="nav-group" x-data="{ open: true }">
                    <button type="button" @click="open = !open" class="nav-group-btn w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-left text-gray-300">
                        <svg class="w-5 h-5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>
                        <span>Profiles</span>
                        <svg class="w-4 h-4 ml-auto nav-chevron shrink-0" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <ul class="nav-group-items mt-0.5 ml-4 pl-3 border-l border-gray-700 space-y-0.5" x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
                        <li><a href="{{ route('admin.profiles.index') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.profiles.index') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>All Profiles</a></li>
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>Suspended</span></li>
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>Deleted</span></li>
                        <li><a href="{{ route('admin.demo-profile.create') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.demo-profile.create') || request()->routeIs('admin.demo-profile.store') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" /></svg>Demo profiles</a></li>
                        <li><a href="{{ route('admin.demo-profile.bulk-create') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.demo-profile.bulk-create') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM19.5 6v2.25a2.25 2.25 0 01-2.25 2.25H15a2.25 2.25 0 01-2.25-2.25V6M3.75 15.75h2.25A2.25 2.25 0 008.25 18v2.25a2.25 2.25 0 01-2.25 2.25H3.75a2.25 2.25 0 01-2.25-2.25V18a2.25 2.25 0 012.25-2.25zM15.75 15.75h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25h-2.25a2.25 2.25 0 01-2.25-2.25v-2.25a2.25 2.25 0 012.25-2.25z" /></svg>Bulk demo</a></li>
                    </ul>
                </div>

                {{-- 3) Interactions — collapsed by default --}}
                <div class="nav-group" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="nav-group-btn w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-left text-gray-300">
                        <svg class="w-5 h-5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>
                        <span>Interactions</span>
                        <svg class="w-4 h-4 ml-auto nav-chevron shrink-0" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <ul class="nav-group-items mt-0.5 ml-4 pl-3 border-l border-gray-700 space-y-0.5" x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>Interests</span></li>
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>Blocks</span></li>
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" /></svg>Shortlists</span></li>
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>Profile views</span></li>
                    </ul>
                </div>

                {{-- 4) Moderation — expanded when active --}}
                <div class="nav-group" x-data="{ open: {{ $moderationOpen ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open" class="nav-group-btn w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-left text-gray-300">
                        <svg class="w-5 h-5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285zm0 0A11.959 11.959 0 013.598 6" /></svg>
                        <span>Moderation</span>
                        <svg class="w-4 h-4 ml-auto nav-chevron shrink-0" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <ul class="nav-group-items mt-0.5 ml-4 pl-3 border-l border-gray-700 space-y-0.5" x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
                        <li><a href="{{ route('admin.abuse-reports.index') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.abuse-reports.*') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>Abuse reports</a></li>
                        <li><a href="{{ route('admin.conflict-records.index') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.conflict-records.*') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>Conflict records</a></li>
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>Image moderation</span></li>
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>Audit logs</span></li>
                    </ul>
                </div>

                {{-- 5) Settings — expanded when active --}}
                <div class="nav-group" x-data="{ open: {{ $settingsOpen ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open" class="nav-group-btn w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-left text-gray-300">
                        <svg class="w-5 h-5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        <span>Settings</span>
                        <svg class="w-4 h-4 ml-auto nav-chevron shrink-0" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <ul class="nav-group-items mt-0.5 ml-4 pl-3 border-l border-gray-700 space-y-0.5" x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>Search &amp; visibility</span></li>
                        <li><a href="{{ route('admin.demo-search-settings.index') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.demo-search-settings.*') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6z" /></svg>Demo search visibility</a></li>
                        <li><a href="{{ route('admin.profile-field-config.index') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.profile-field-config.*') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h11.25c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>Profile Field Configuration</a></li>
                        <li><a href="{{ route('admin.field-registry.index') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.field-registry.index') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" /></svg>Field Registry (CORE)</a></li>
                        <li><a href="{{ route('admin.field-registry.extended.index') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.field-registry.extended.*') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15m0 0l7.5 7.5m-7.5-7.5l7.5-7.5" /></svg>EXTENDED Fields</a></li>
                        <li><a href="{{ route('admin.view-back-settings.index') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.view-back-settings.*') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>View-back</a></li>
                        <li><a href="{{ route('admin.notifications.index') }}" class="nav-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('admin.notifications.*') ? 'active' : '' }}"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>Notification settings</a></li>
                    </ul>
                </div>

                {{-- 6) System — collapsed by default --}}
                <div class="nav-group" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="nav-group-btn w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-left text-gray-300">
                        <svg class="w-5 h-5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                        <span>System</span>
                        <svg class="w-4 h-4 ml-auto nav-chevron shrink-0" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <ul class="nav-group-items mt-0.5 ml-4 pl-3 border-l border-gray-700 space-y-0.5" x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>Admin users</span></li>
                        <li><span class="nav-link disabled flex items-center gap-3 px-3 py-2 rounded-lg text-sm" title="Coming soon"><svg class="w-4 h-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.876-.32-1.739-.439-2.628-.439a6 6 0 00-2.628.439 6 6 0 01-7.029 5.912 3 3 0 013 3m9 0v.75M12 15.75v.75M12 15.75H12m.75 0h-.75M12 15.75h.75m-.75 0h-.75" /></svg>Roles</span></li>
                    </ul>
                </div>
            </nav>
            <div class="p-4 border-t border-gray-700 flex-shrink-0">
                <a href="{{ url('/') }}" class="block text-sm text-gray-400 hover:text-white">← Back to site</a>
                <form method="POST" action="{{ route('logout') }}" class="mt-2">
                    @csrf
                    <button type="submit" class="text-sm text-gray-400 hover:text-white">Log out</button>
                </form>
            </div>
        </aside>
        <main class="flex-1 min-h-screen min-w-0" style="margin-left: 16rem;">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 py-6">
                @if (session('success'))
                    <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800">{{ session('success') }}</div>
                @endif
                @if (session('error'))
                    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800">{{ session('error') }}</div>
                @endif
                @if (session('info'))
                    <div class="mb-4 px-4 py-3 rounded-lg bg-sky-50 border border-sky-200 text-sky-800">{{ session('info') }}</div>
                @endif
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
