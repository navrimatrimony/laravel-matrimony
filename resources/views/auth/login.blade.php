<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div class="mb-5">
        <h1 class="text-xl font-semibold tracking-tight text-stone-900 dark:text-stone-100">{{ __('Welcome back') }}</h1>
        <p class="mt-1 text-sm leading-relaxed text-stone-600 dark:text-stone-400">
            {{ __('Sign in using your mobile number, email, or username in a single step.') }}
        </p>
    </div>

    <form id="login-form" method="POST" action="{{ route('login') }}">
        @csrf

        <div>
            <x-input-label for="login" :value="__('Mobile / Email / Username')" />
            <x-text-input id="login" class="block mt-1 w-full" type="text" name="login" :value="old('login')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('login')" class="mt-2" />
            <p class="mt-2 text-xs text-stone-500 dark:text-stone-400">{{ __('Use any one: 10-digit mobile number, email address, or your username.') }}</p>
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800" name="remember">
                <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="mt-5 flex items-center justify-between gap-3">
            <x-primary-button class="ms-3">
                {{ __('Log in') }}
            </x-primary-button>
        </div>

        <div class="mt-5 text-sm text-gray-600 dark:text-gray-400">
            @if (Route::has('password.request'))
                <a class="underline hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
                <span class="mx-2 text-gray-400">|</span>
            @endif
            <a href="{{ route('register') }}" class="underline hover:text-gray-900 dark:hover:text-gray-100">
                {{ __('New user? Register here') }}
            </a>
        </div>
    </form>

    <div class="mt-6 text-center text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ url('/') }}" class="underline-offset-2 hover:underline hover:text-gray-900 dark:hover:text-gray-100">
            {{ __('Back to Home') }}
        </a>
    </div>

    <script>
        (function () {
            var f = document.getElementById('login-form');
            if (!f) return;
            f.addEventListener('submit', function () {
                var btn = f.querySelector('button[type="submit"]');
                if (!btn || btn.dataset.once) return;
                btn.dataset.once = '1';
                btn.disabled = true;
            });
        })();
    </script>
</x-guest-layout>
