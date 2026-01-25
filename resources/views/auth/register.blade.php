<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
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

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
<div class="mt-4">
    <x-input-label for="gender" value="Gender" />

    <div class="mt-2 flex gap-4">
        <label class="flex items-center">
            <input type="radio" name="gender" value="male" required>
            <span class="ml-2">Male</span>
        </label>

        <label class="flex items-center">
            <input type="radio" name="gender" value="female" required>
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
</x-guest-layout>
