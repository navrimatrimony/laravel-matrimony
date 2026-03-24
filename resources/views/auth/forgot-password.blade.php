<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        Forgot your password? Enter your mobile, email, or username and we will send a reset link to your registered email.
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div>
            <x-input-label for="login" :value="__('Mobile / Email / Username')" />
            <x-text-input id="login" class="block mt-1 w-full" type="text" name="login" :value="old('login')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('login')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-4">
            <a href="{{ route('login') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">Back to login</a>
            <x-primary-button>{{ __('Send Password Reset Link') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
