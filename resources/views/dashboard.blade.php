<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
    <div class="p-6 text-gray-900 dark:text-gray-100">

        {{-- 
        |--------------------------------------------------------------------------
        | Matrimony Dashboard (Day 14)
        |--------------------------------------------------------------------------
        | PURPOSE:
        |   Login नंतर user ला next clear action दाखवणे
        |
        | LOGIC:
        |   - Matrimony profile नाही → Create Profile
        |   - Matrimony profile आहे → Search Profiles
        |--------------------------------------------------------------------------
        --}}

        @if (!auth()->user()->matrimonyProfile)
            <h3 class="text-xl font-semibold mb-4">
                Welcome! Let’s create your Matrimony Profile
            </h3>

            <p class="mb-6 text-gray-600 dark:text-gray-300">
                You haven’t created your matrimony profile yet.  
                Creating a profile is required to search and send interests.
            </p>

            <a href="{{ route('matrimony.profile.create') }}"
               class="inline-block px-6 py-3 bg-blue-600 text-white rounded hover:bg-blue-700">
                Create Matrimony Profile
            </a>
        @else
            <h3 class="text-xl font-semibold mb-4">
                Welcome back!
            </h3>

            <p class="mb-6 text-gray-600 dark:text-gray-300">
                Your matrimony profile is ready.  
                You can now search profiles and send interests.
            </p>

            <a href="{{ route('matrimony.profiles.index') }}"
               class="inline-block px-6 py-3 bg-green-600 text-white rounded hover:bg-green-700">
                Search Matrimony Profiles
            </a>
        @endif

    </div>
</div>

        </div>
    </div>
</x-app-layout>
