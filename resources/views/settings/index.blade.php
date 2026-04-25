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

        @php
            $settingsSummaryItems = [];

            if (session('warning')) {
                $settingsSummaryItems[] = [
                    'severity' => 'warning',
                    'message' => (string) session('warning'),
                ];
            }

            if (!$hasProfile) {
                $settingsSummaryItems[] = [
                    'severity' => 'info',
                    'message' => __('Create/update your matrimony profile first. Privacy & communication settings are profile-based.'),
                    'action_url' => route('matrimony.profile.wizard.section', ['section' => 'full']),
                    'action_label' => __('nav.edit_profile'),
                ];
            }
        @endphp

        @if (!empty($settingsSummaryItems))
            <div class="mb-6">
                <x-notification-summary :items="$settingsSummaryItems" variant="cards" :columns="2" />
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
                <a href="{{ route('user.settings.my-plan') }}"
                   class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition">
                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('user_plan.my_plan_hub_title') }}</div>
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

