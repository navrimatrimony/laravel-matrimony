@extends('layouts.app')

@section('content')
<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('Account & Security') }}</h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400">
                {{ __('Update your password and verify your mobile/email.') }}
            </p>
        </div>

        @if (session('status') === 'password-updated')
            <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
                {{ __('Password updated successfully.') }}
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

        <div class="grid grid-cols-1 gap-6">
            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Change password') }}</div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('For security, enter your current password and choose a new one.') }}
                </p>

                <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('Current password') }}
                        </label>
                        <input type="password" name="current_password"
                               class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                               required autocomplete="current-password">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('New password') }}
                        </label>
                        <input type="password" name="password"
                               class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                               required autocomplete="new-password">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('Confirm new password') }}
                        </label>
                        <input type="password" name="password_confirmation"
                               class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100"
                               required autocomplete="new-password">
                    </div>

                    <div class="flex items-center justify-end">
                        <button type="submit"
                                class="px-5 py-2 rounded-md bg-red-600 text-white hover:bg-red-700 transition font-medium">
                            {{ __('Update password') }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Verification status') }}</div>
                <div class="mt-4 space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ __('Mobile') }}</div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                {{ __('Verify to enable contact features safely.') }}
                            </div>
                        </div>
                        @if ($mobileVerified)
                            <span class="px-3 py-1 rounded-full bg-green-100 text-green-800 text-sm font-medium">
                                {{ __('Mobile verified') }}
                            </span>
                        @else
                            <a href="{{ route('mobile.verify') }}"
                               class="px-3 py-1 rounded-full bg-red-50 text-red-700 text-sm font-medium hover:bg-red-100 transition">
                                {{ __('Verify mobile') }}
                            </a>
                        @endif
                    </div>

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ __('Email') }}</div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                {{ __('Verify to secure your account and recover access.') }}
                            </div>
                        </div>
                        @if ($emailVerified)
                            <span class="px-3 py-1 rounded-full bg-green-100 text-green-800 text-sm font-medium">
                                {{ __('Email verified') }}
                            </span>
                        @else
                            <a href="{{ route('verification.notice') }}"
                               class="px-3 py-1 rounded-full bg-red-50 text-red-700 text-sm font-medium hover:bg-red-100 transition">
                                {{ __('Verify email') }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

