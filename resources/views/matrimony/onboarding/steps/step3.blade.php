<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 3]) }}" class="space-y-6">
    @csrf
    <x-profile.religion-caste-selector :profile="$profile" namePrefix="" />

    <div class="flex flex-col sm:flex-row gap-3 pt-2">
        <a href="{{ route('matrimony.onboarding.show', ['step' => 2]) }}" class="inline-flex justify-center items-center min-h-[52px] px-6 rounded-xl text-base font-semibold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 w-full sm:w-auto text-center">
            {{ __('onboarding.back') }}
        </a>
        <button type="submit" class="inline-flex justify-center items-center min-h-[52px] px-6 rounded-xl text-base font-semibold text-white bg-indigo-600 hover:bg-indigo-700 focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 w-full sm:flex-1">
            {{ __('onboarding.continue') }}
        </button>
    </div>
</form>
