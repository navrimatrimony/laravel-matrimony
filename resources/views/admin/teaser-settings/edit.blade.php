@extends('layouts.admin')

@section('content')
@php
    $tab = $activeTab ?? 'who-viewed';
@endphp
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-3xl">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">{{ __('admin.teaser_settings_title') }}</h1>
    <p class="text-gray-600 dark:text-gray-400 text-sm mb-6">{{ __('admin.teaser_settings_intro') }}</p>

    @if (session('success'))
        <p class="text-green-600 dark:text-green-400 text-sm mb-4">{{ session('success') }}</p>
    @endif
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    @endif

    <div class="mb-6 flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-600">
        <a href="{{ route('admin.teaser-settings.index', ['tab' => 'who-viewed']) }}"
           @class([
               'inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition',
               'bg-indigo-600 text-white shadow-sm' => $tab === 'who-viewed',
               'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600' => $tab !== 'who-viewed',
           ])>{{ __('admin.teaser_tab_who_viewed') }}</a>
        <a href="{{ route('admin.teaser-settings.index', ['tab' => 'received-interests']) }}"
           @class([
               'inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition',
               'bg-indigo-600 text-white shadow-sm' => $tab === 'received-interests',
               'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600' => $tab !== 'received-interests',
           ])>{{ __('admin.teaser_tab_received_interests') }}</a>
        <a href="{{ route('admin.teaser-settings.index', ['tab' => 'chat']) }}"
           @class([
               'inline-flex items-center rounded-lg px-4 py-2 text-sm font-semibold transition',
               'bg-indigo-600 text-white shadow-sm' => $tab === 'chat',
               'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600' => $tab !== 'chat',
           ])>{{ __('admin.teaser_tab_chat') }}</a>
    </div>

    @if ($tab === 'who-viewed')
        @include('admin.teaser-settings._tab-who-viewed', ['policy' => $whoViewedPolicy])
    @elseif ($tab === 'received-interests')
        @include('admin.teaser-settings._tab-received-interests', ['policy' => $receivedInterestPolicy])
    @else
        @include('admin.teaser-settings._tab-chat', ['policy' => $chatPolicy])
    @endif
</div>
@endsection
