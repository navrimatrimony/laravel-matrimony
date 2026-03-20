@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('Privacy & Visibility') }}</h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400">
                {{ __('Control who can see your photo and contact details.') }}
            </p>
        </div>

        @if (session('status') === 'privacy-updated')
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

        @if (!empty($profile->profile_visibility_mode))
            <div class="mb-6 p-4 bg-amber-50 border border-amber-200 text-amber-900 rounded-lg">
                <div class="font-semibold mb-1">{{ __('Legacy mode (read-only)') }}</div>
                <div class="text-sm">
                    {{ __('Current profile_visibility_mode:') }} <span class="font-medium">{{ $profile->profile_visibility_mode }}</span>
                </div>
                <div class="text-sm mt-2">
                    {{ __('These new controls will eventually unify with this legacy setting.') }}
                </div>
            </div>
        @endif

        @php
            $visibilityScope = old('visibility_scope', $visibilitySettings->visibility_scope ?? null);
            $showPhotoTo = old('show_photo_to', $visibilitySettings->show_photo_to ?? null);
            $showContactTo = old('show_contact_to', $visibilitySettings->show_contact_to ?? null);
            $hideFromBlocked = old('hide_from_blocked_users', $visibilitySettings->hide_from_blocked_users ?? true);
        @endphp

        <form method="POST" action="{{ route('user.settings.privacy.update') }}" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Photo visibility scope') }}</div>
                <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('This controls the general visibility of your profile in relation to your photo.') }}
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Visibility scope') }}
                    </label>
                    <select name="visibility_scope"
                            class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                            required>
                        <option value="" {{ $visibilityScope === null ? 'selected' : '' }}>{{ __('Choose...') }}</option>
                        <option value="public" {{ $visibilityScope === 'public' ? 'selected' : '' }}>{{ __('Public') }}</option>
                        <option value="premium_only" {{ $visibilityScope === 'premium_only' ? 'selected' : '' }}>{{ __('Premium only') }}</option>
                        <option value="hidden" {{ $visibilityScope === 'hidden' ? 'selected' : '' }}>{{ __('Hidden') }}</option>
                    </select>
                </div>

                <div class="mt-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Show photo to') }}
                    </label>
                    <select name="show_photo_to"
                            class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                            required>
                        <option value="" {{ $showPhotoTo === null ? 'selected' : '' }}>{{ __('Choose...') }}</option>
                        <option value="all" {{ $showPhotoTo === 'all' ? 'selected' : '' }}>{{ __('All viewers') }}</option>
                        <option value="premium" {{ $showPhotoTo === 'premium' ? 'selected' : '' }}>{{ __('Premium viewers') }}</option>
                        <option value="accepted_interest" {{ $showPhotoTo === 'accepted_interest' ? 'selected' : '' }}>{{ __('After interest accepted') }}</option>
                    </select>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Contact detail visibility') }}</div>
                <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('This controls when contact details can be unlocked for other profiles.') }}
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('Show contact to') }}
                    </label>
                    <select name="show_contact_to"
                            class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                            required>
                        <option value="" {{ $showContactTo === null ? 'selected' : '' }}>{{ __('Choose...') }}</option>
                        <option value="unlock_only" {{ $showContactTo === 'unlock_only' ? 'selected' : '' }}>{{ __('Unlocked only') }}</option>
                        <option value="accepted_interest" {{ $showContactTo === 'accepted_interest' ? 'selected' : '' }}>{{ __('After interest accepted') }}</option>
                    </select>
                </div>

                <div class="mt-4">
                    <label class="flex items-start gap-3">
                        <input type="hidden" name="hide_from_blocked_users" value="0">
                        <input type="checkbox"
                               name="hide_from_blocked_users"
                               value="1"
                               class="mt-1"
                               {{ $hideFromBlocked ? 'checked' : '' }}>
                        <span class="text-sm text-gray-700 dark:text-gray-200">
                            {{ __('Hide photo/contact from users you have blocked.') }}
                        </span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('user.settings.index') }}"
                   class="px-4 py-2 rounded-md border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-900 transition">
                    {{ __('Back') }}
                </a>
                <button type="submit"
                        class="px-5 py-2 rounded-md bg-red-600 text-white hover:bg-red-700 transition font-medium">
                    {{ __('Save') }}
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

