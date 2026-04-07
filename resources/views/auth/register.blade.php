<x-guest-layout>
    <x-slot name="aboveCard">
        <div class="text-center text-sm text-gray-600 dark:text-gray-400">
            <a href="{{ url('/') }}" class="underline hover:text-gray-900 dark:hover:text-gray-100">Home</a>
            <span class="mx-1 text-gray-400 dark:text-gray-500" aria-hidden="true">|</span>
            <a href="{{ route('login') }}" class="underline hover:text-gray-900 dark:hover:text-gray-100">Already have an account? Login here</a>
        </div>
    </x-slot>

    <form id="register-form" method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Registrant name (account) -->
        <div>
            <x-input-label for="name" :value="__('onboarding.registrant_name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="registering_for" :value="__('onboarding.registering_for')" />
            <select id="registering_for" name="registering_for" required class="block mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="self" @selected(old('registering_for', 'self') === 'self')>{{ __('onboarding.registering_for_self') }}</option>
                <option value="parent_guardian" @selected(old('registering_for') === 'parent_guardian')>{{ __('onboarding.registering_for_parent_guardian') }}</option>
                <option value="sibling" @selected(old('registering_for') === 'sibling')>{{ __('onboarding.registering_for_sibling') }}</option>
                <option value="relative" @selected(old('registering_for') === 'relative')>{{ __('onboarding.registering_for_relative') }}</option>
                <option value="friend" @selected(old('registering_for') === 'friend')>{{ __('onboarding.registering_for_friend') }}</option>
                <option value="other" @selected(old('registering_for') === 'other')>{{ __('onboarding.registering_for_other') }}</option>
            </select>
            <x-input-error :messages="$errors->get('registering_for')" class="mt-2" />
        </div>

        <!-- Mobile (required) — centralized contact-field -->
        <div class="mt-4">
            <x-profile.contact-field
                name="mobile"
                :value="old('mobile')"
                label="Mobile number (required)"
                placeholder="10-digit mobile number"
                :showCountryCode="true"
                :showWhatsapp="false"
                :required="true"
            />
            <x-input-error :messages="$errors->get('mobile')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-password-input id="password" name="password" required autocomplete="new-password" class="mt-0" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-password-input id="password_confirmation" name="password_confirmation" required autocomplete="new-password" class="mt-0" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <button type="submit" style="background-color: #4f46e5; color: white; padding: 10px 24px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; margin-left: 16px;">
                Register
            </button>
            
        </div>
    </form>
    {{-- Prevent double-submit: first request logs in and migrates session; a second in-flight POST keeps the old CSRF and gets 419. --}}
    <script>
        (function () {
            var f = document.getElementById('register-form');
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
