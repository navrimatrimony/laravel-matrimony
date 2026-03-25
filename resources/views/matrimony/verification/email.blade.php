@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto py-10 px-4 sm:px-6">
    @if (session('info'))
        <div class="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">{{ session('info') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif
    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('profile.verification_email_title') }}</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
        @if ($hasEmail ?? true)
            {{ __('profile.verification_email_intro') }}
        @else
            {{ __('profile.verification_email_missing_intro') }}
        @endif
    </p>

    @if ($emailVerified)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100">
            {{ __('profile.verification_email_done') }}
        </div>
    @else
        <div class="rounded-lg border border-stone-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            @if (session('status') === 'verification-link-sent')
                <div class="mb-4 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                    {{ __('profile.verification_email_link_sent_flash') }}
                </div>
            @endif

            @if ($hasEmail ?? true)
                <p class="text-sm text-gray-700 dark:text-gray-300 mb-1">{{ __('profile.verification_email_account_label') }}</p>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 break-all mb-4">{{ $email }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">{{ __('profile.verification_email_link_help') }}</p>
            @else
                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('profile.verification_email_input_label') }}</label>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        value="{{ old('email') }}"
                        required
                        form="matrimony-email-send-form"
                        autocomplete="email"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                    />
                    @error('email')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <form id="matrimony-email-send-form" method="POST" action="{{ route('matrimony.verification.email.send') }}" class="mb-4">
                @csrf
                <button type="submit" class="inline-flex w-full sm:w-auto justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-indigo-700">
                    {{ __('profile.verification_email_send_link') }}
                </button>
            </form>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('profile.verification_email_check_spam') }}</p>
        </div>
    @endif

    <div class="mt-8">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('dashboard') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
            {{ __('profile.verification_back') }}
        </a>
    </div>
</div>
@endsection
