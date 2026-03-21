<x-guest-layout>
    <form id="register-form" method="POST" action="{{ route('register') }}">
        @csrf
        {{-- Navigation links for usability --}}
<div class="mt-6 text-center text-sm text-gray-600">
    <a href="{{ url('/') }}" class="underline hover:text-gray-900">
        Home
    </a>
    |
    <a href="{{ route('login') }}" class="underline hover:text-gray-900">
        Already have an account? Login here
    </a>
</div>

        <!-- Name (registrant) -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="registering_for" :value="__('onboarding.registering_for')" />
            <select id="registering_for" name="registering_for" required class="block mt-1 w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="self" @selected(old('registering_for', 'self') === 'self')>{{ __('onboarding.registering_for_self') }}</option>
                <option value="son" @selected(old('registering_for') === 'son')>{{ __('onboarding.registering_for_son') }}</option>
                <option value="daughter" @selected(old('registering_for') === 'daughter')>{{ __('onboarding.registering_for_daughter') }}</option>
                <option value="sibling" @selected(old('registering_for') === 'sibling')>{{ __('onboarding.registering_for_sibling') }}</option>
                <option value="other" @selected(old('registering_for') === 'other')>{{ __('onboarding.registering_for_other') }}</option>
            </select>
            <x-input-error :messages="$errors->get('registering_for')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="relation_to_profile" :value="__('onboarding.relation_to_profile')" />
            <x-text-input id="relation_to_profile" class="block mt-1 w-full" type="text" name="relation_to_profile" :value="old('relation_to_profile')" :placeholder="__('onboarding.relation_placeholder')" autocomplete="off" />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.optional_relation_hint') }}</p>
            <x-input-error :messages="$errors->get('relation_to_profile')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
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

        <!-- Gender (optional; can set in profile wizard) -->
        <div class="mt-4">
            <x-input-label for="gender" value="Gender" />
            <div class="mt-2 flex gap-4">
                <label class="flex items-center">
                    <input type="radio" name="gender" value="male" {{ old('gender') === 'male' ? 'checked' : '' }}>
                    <span class="ml-2">Male</span>
                </label>
                <label class="flex items-center">
                    <input type="radio" name="gender" value="female" {{ old('gender') === 'female' ? 'checked' : '' }}>
                    <span class="ml-2">Female</span>
                </label>
            </div>
            <x-input-error :messages="$errors->get('gender')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

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
