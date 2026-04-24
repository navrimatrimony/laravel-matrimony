@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">
                {{ __('Settings') }}
            </h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400">
                {{ __('Manage your privacy, communication, and account security.') }}
            </p>
        </div>

        @if (session('warning'))
            <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg">
                {{ session('warning') }}
            </div>
        @endif

        @if (!$hasProfile)
            <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-700/30 border border-gray-200 dark:border-gray-600 rounded-lg">
                {{ __('Create/update your matrimony profile first. Privacy & communication settings are profile-based.') }}
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <a href="{{ route('user.settings.privacy') }}"
               class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Privacy & Visibility') }}</div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Control who can see your photo and contact details.') }}
                </p>
            </a>

            <a href="{{ route('user.settings.communication') }}"
               class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Communication Preferences') }}</div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Choose when contact details can be unlocked.') }}
                </p>
            </a>

            <a href="{{ route('user.settings.security') }}"
               class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Account & Security') }}</div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Update password and verify your contact methods.') }}
                </p>
            </a>

            @if ($hasProfile)
                <a href="{{ route('user.my-plan') }}"
                   class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition">
                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('user_plan.page_title') }}</div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('user_plan.settings_my_plan_intro') }}
                    </p>
                </a>
            @endif

            <a href="{{ route('blocks.index') }}"
               class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition md:col-span-2 lg:col-span-1">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Manage Blocked Profiles') }}</div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Open your blocked list and manage access.') }}
                </p>
            </a>

            <a href="{{ route('notifications.index') }}"
               class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition md:col-span-2 lg:col-span-1">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Manage Notifications') }}</div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Open notifications and mark them as read.') }}
                </p>
            </a>
        </div>
    </div>
</div>
@endsection

