@extends('layouts.app')

{{--
| The OTP itself is never handled here. This view posts an address, then posts
| a code; the expiry, the attempt ceiling and the resend cooldown are the
| server's numbers, rendered as text. Nothing on this page counts anything.
--}}

@section('content')
<div class="py-12">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('settings_email.title') }}</h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400">{{ __('settings_email.intro') }}</p>
        </div>

        @if (session('status') === 'email-verified')
            <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
                {{ __('settings_email.status_verified') }}
            </div>
        @elseif (session('status') === 'email-otp-sent')
            <div class="mb-6 p-4 bg-blue-50 border border-blue-200 text-blue-800 rounded-lg">
                {{ __('settings_email.status_sent') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ __('settings_email.current_email') }}
                    </div>
                    <div class="mt-1 text-gray-900 dark:text-gray-100">
                        {{ $currentEmail ?: __('settings_email.no_email') }}
                    </div>
                </div>
                @if ($currentEmail)
                    @if ($emailVerified)
                        <span class="px-3 py-1 rounded-full bg-green-100 text-green-800 text-sm font-medium">
                            {{ __('settings_email.verified') }}
                        </span>
                    @else
                        <span class="px-3 py-1 rounded-full bg-red-50 text-red-700 text-sm font-medium">
                            {{ __('settings_email.unverified') }}
                        </span>
                    @endif
                @endif
            </div>
        </div>

        @if ($challenge)
            {{-- A code is outstanding. The address it belongs to is held in the
                 session, so it is shown but cannot be edited here. --}}
            <div class="mt-6 bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('settings_email.code_heading') }}
                </div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('settings_email.code_sent_to', ['email' => $challenge['email']]) }}
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('settings_email.code_expires_in', ['seconds' => $challenge['expires_in']]) }}
                    {{ __('settings_email.resend_after', ['seconds' => $challenge['resend_after']]) }}
                </p>
                @if (! empty($challenge['debug_otp']))
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        {{ __('settings_email.dev_code_notice', ['otp' => $challenge['debug_otp']]) }}
                    </p>
                @endif

                <form method="POST" action="{{ route('user.settings.email.otp.verify') }}" class="mt-6 space-y-4">
                    @csrf
                    <div>
                        <label for="otp" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('settings_email.code_label') }}
                        </label>
                        <input id="otp" type="text" name="otp" inputmode="numeric" maxlength="6" required
                               autocomplete="one-time-code"
                               class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit"
                                class="px-4 py-2 bg-rose-600 text-white rounded-md hover:bg-rose-700 transition">
                            {{ __('settings_email.verify_cta') }}
                        </button>
                    </div>
                </form>

                <div class="mt-4 flex items-center gap-4">
                    {{-- Resending is the same "send" action with the same address;
                         the server refuses it until its own cooldown has passed. --}}
                    <form method="POST" action="{{ route('user.settings.email.otp.send') }}">
                        @csrf
                        <input type="hidden" name="email" value="{{ $challenge['email'] }}">
                        <button type="submit"
                                class="text-sm text-rose-700 dark:text-rose-400 hover:underline">
                            {{ __('settings_email.verify_current_cta') }}
                        </button>
                    </form>
                    <form method="POST" action="{{ route('user.settings.email.otp.cancel') }}">
                        @csrf
                        <button type="submit" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">
                            {{ __('settings_email.cancel_cta') }}
                        </button>
                    </form>
                </div>
            </div>
        @else
            @if ($currentEmail && ! $emailVerified)
                <div class="mt-6 bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('settings_email.verify_current_heading') }}
                    </div>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('settings_email.verify_current_hint') }}
                    </p>
                    <form method="POST" action="{{ route('user.settings.email.otp.send') }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="email" value="{{ $currentEmail }}">
                        <button type="submit"
                                class="px-4 py-2 bg-rose-600 text-white rounded-md hover:bg-rose-700 transition">
                            {{ __('settings_email.verify_current_cta') }}
                        </button>
                    </form>
                </div>
            @endif

            <div class="mt-6 bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ $currentEmail ? __('settings_email.change_heading') : __('settings_email.add_heading') }}
                </div>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {{ $currentEmail ? __('settings_email.change_hint') : __('settings_email.add_hint') }}
                </p>

                <form method="POST" action="{{ route('user.settings.email.otp.send') }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="new-email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('settings_email.new_email_label') }}
                        </label>
                        <input id="new-email" type="email" name="email" required autocomplete="email"
                               value="{{ old('email') }}"
                               class="mt-2 w-full rounded-md border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                    </div>
                    <button type="submit"
                            class="px-4 py-2 bg-rose-600 text-white rounded-md hover:bg-rose-700 transition">
                        {{ __('settings_email.change_cta') }}
                    </button>
                </form>
            </div>
        @endif

        <div class="mt-6">
            <a href="{{ route('user.settings.security') }}"
               class="text-sm text-rose-700 dark:text-rose-400 hover:underline">
                {{ __('settings_email.back_to_security') }}
            </a>
        </div>
    </div>
</div>
@endsection
