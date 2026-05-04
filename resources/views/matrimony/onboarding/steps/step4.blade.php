@php
    use Illuminate\Support\Facades\Schema;

    $hasEducationEngine = Schema::hasColumn('matrimony_profiles', 'highest_education');
@endphp
<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 4]) }}" class="space-y-6" id="onboarding-step4-form">
    @csrf
    <div class="rounded-xl border border-rose-200 dark:border-rose-800/60 bg-white dark:bg-gray-900/30 p-4 space-y-4">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('wizard.basic_information') }}</h3>

        @if ($hasEducationEngine)
            <x-education-multiselect-engine :profile="$profile" form-selector="#onboarding-step4-form" />
        @else
            <p class="text-sm text-amber-800 dark:text-amber-200">{{ __('onboarding.run_migrations_education') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.education_examples') }}</p>
        @endif
    </div>

    <div class="rounded-xl border border-rose-200 dark:border-rose-800/60 bg-white dark:bg-gray-900/30 p-4 space-y-4 career-dependent-block">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('components.education.career') }}</h3>
        <x-occupation-search-engine :profile="$profile" form-selector="#onboarding-step4-form" />
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900/20 p-4 space-y-3">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('onboarding.career_income_section') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.career_income_hint') }}</p>
        <x-income-engine
            :label="__('onboarding.career_income_label')"
            name-prefix="income"
            empty-value-type-default="approximate"
            :profile="$profile"
            :currencies="$currencies ?? collect()"
            :help-text="__('onboarding.career_income_help')"
        />
    </div>

    <div class="mt-8 space-y-4 sm:sticky sm:bottom-4 sm:z-10 sm:-mx-1 sm:px-4 sm:py-4 sm:rounded-xl sm:border sm:border-gray-200/90 sm:dark:border-gray-600 sm:bg-white/95 sm:dark:bg-gray-800/95 sm:backdrop-blur-md sm:shadow-lg sm:shadow-slate-300/20 dark:sm:shadow-none">
        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('onboarding.step5_continue_intro') }}</p>
        <x-onboarding.form-footer
            :back-url="route('matrimony.onboarding.show', ['step' => 3])"
            submit-extra-class="!min-h-[58px] !text-lg !font-bold !px-11"
            class="!mt-0 !pt-4 border-t border-gray-200 dark:border-gray-600"
        />
    </div>
</form>
