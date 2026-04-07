{{-- No main matrimony menu during card onboarding / photo handoff — logo + sign out only --}}
<nav class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex justify-between items-center h-14">
        <a href="{{ url('/') }}" class="flex items-center gap-2 text-gray-800 dark:text-gray-100 font-semibold text-sm sm:text-base">
            <x-application-logo class="block h-8 w-auto fill-current text-gray-800 dark:text-gray-200" />
            <span class="truncate">{{ config('app.name') }}</span>
        </a>
        <form method="POST" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit" class="text-sm font-medium text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400">
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</nav>
