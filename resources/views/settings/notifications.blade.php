@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
            <a href="{{ route('user.settings.index') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                {{ __('user_settings_notifications.back_to_settings') }}
            </a>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">
                {{ __('user_settings_notifications.title') }}
            </h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400">
                {{ __('user_settings_notifications.intro') }}
            </p>
        </div>

        @if (session('status') === 'notifications-updated')
            <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
                {{ __('Saved successfully.') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
                <div class="font-semibold mb-2">{{ __('Please fix the following:') }}</div>
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('user.settings.notifications.update') }}" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('user_settings_notifications.in_app_heading') }}
                </h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('user_settings_notifications.in_app_note') }}
                </p>
                <p class="mt-4">
                    <a href="{{ route('notifications.index') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                        {{ __('user_settings_notifications.open_inbox') }}
                    </a>
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6 space-y-5">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('user_settings_notifications.email_heading') }}
                </h2>

                @if (! $platformMailEnabled)
                    <p class="text-sm text-amber-700 dark:text-amber-300">
                        {{ __('user_settings_notifications.platform_mail_off') }}
                    </p>
                @elseif (trim((string) ($user->email ?? '')) === '')
                    <p class="text-sm text-amber-700 dark:text-amber-300">
                        {{ __('user_settings_notifications.no_email') }}
                    </p>
                @else
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="hidden" name="email_alerts" value="0">
                        <input type="checkbox"
                               name="email_alerts"
                               value="1"
                               class="mt-1 rounded border-gray-300 dark:border-gray-600"
                               @checked((bool) old('email_alerts', $prefs['email_alerts']))>
                        <span>
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ __('user_settings_notifications.email_alerts_label') }}
                            </span>
                            <span class="block text-sm text-gray-600 dark:text-gray-400">
                                {{ __('user_settings_notifications.email_alerts_help') }}
                            </span>
                        </span>
                    </label>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6 space-y-5">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('user_settings_notifications.engagement_heading') }}
                </h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('user_settings_notifications.engagement_help') }}
                </p>

                @if (! $platformInactiveEnabled)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('user_settings_notifications.platform_inactive_off') }}
                    </p>
                @else
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="hidden" name="engagement_inactive_reminder" value="0">
                        <input type="checkbox"
                               name="engagement_inactive_reminder"
                               value="1"
                               class="mt-1 rounded border-gray-300 dark:border-gray-600"
                               @checked((bool) old('engagement_inactive_reminder', $prefs['engagement_inactive_reminder']))>
                        <span>
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ __('user_settings_notifications.inactive_label') }}
                            </span>
                            <span class="block text-sm text-gray-600 dark:text-gray-400">
                                {{ __('user_settings_notifications.inactive_help') }}
                            </span>
                        </span>
                    </label>
                @endif

                @if (! $platformDigestEnabled)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('user_settings_notifications.platform_digest_off') }}
                    </p>
                @else
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="hidden" name="engagement_new_matches_digest" value="0">
                        <input type="checkbox"
                               name="engagement_new_matches_digest"
                               value="1"
                               class="mt-1 rounded border-gray-300 dark:border-gray-600"
                               @checked((bool) old('engagement_new_matches_digest', $prefs['engagement_new_matches_digest']))>
                        <span>
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ __('user_settings_notifications.digest_label') }}
                            </span>
                            <span class="block text-sm text-gray-600 dark:text-gray-400">
                                {{ __('user_settings_notifications.digest_help') }}
                            </span>
                        </span>
                    </label>
                @endif
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    {{ __('Save') }}
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
