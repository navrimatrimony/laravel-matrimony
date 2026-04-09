@php
    $highestEdRaw = old('highest_education', $profile->highest_education);
    $selectedCategory = old('education_category', $selectedEducationCategory ?? null);
    $isOtherEducation = (string) $highestEdRaw === 'Other';
@endphp
<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 4]) }}" class="space-y-6">
    @csrf
    <div class="rounded-xl border border-rose-200 dark:border-rose-800/60 bg-white dark:bg-gray-900/30 p-4 space-y-4">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('wizard.basic_information') }}</h3>
        <x-education-hierarchy-select
            categoryName="education_category"
            degreeName="highest_education"
            :selectedCategory="$selectedCategory"
            :selectedDegree="$highestEdRaw"
            mode="dependent"
            labelCategory="{{ __('Education category') }}"
            labelDegree="{{ __('Highest education') }}"
        />
        @error('highest_education')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <div data-other-ed class="{{ $isOtherEducation ? '' : 'hidden' }}">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('Other (please specify)') }}</label>
            <input type="text" name="highest_education_other" value="{{ old('highest_education_other', $profile->highest_education_other) }}"
                class="w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-4 py-3 text-base min-h-[48px]">
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.education_examples') }}</p>
        <script>
        (function(){
            var form = document.currentScript.closest('form');
            if (!form) return;
            var degSelect = form.querySelector('.education-degree-select');
            var otherBlock = form.querySelector('[data-other-ed]');
            if (!degSelect || !otherBlock) return;
            function toggle(){ otherBlock.classList.toggle('hidden', degSelect.value !== 'Other'); }
            degSelect.addEventListener('change', toggle);
            toggle();
        })();
        </script>
    </div>

    <div class="rounded-xl border border-rose-200 dark:border-rose-800/60 bg-white dark:bg-gray-900/30 p-4 space-y-4 career-dependent-block">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('components.education.career') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.education.working_with') }}</label>
                <select name="working_with_type_id" class="w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-4 py-3 text-base min-h-[48px] career-parent-select">
                    <option value="">{{ __('common.select') }}</option>
                    @foreach($workingWithTypes as $ww)
                        <option value="{{ $ww->id }}" {{ (string) old('working_with_type_id', $profile->working_with_type_id) === (string) $ww->id ? 'selected' : '' }}>{{ $ww->name }}</option>
                    @endforeach
                </select>
                @error('working_with_type_id')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.education.working_as') }}</label>
                <select name="profession_id" class="w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-4 py-3 text-base min-h-[48px] career-child-select">
                    <option value="">{{ __('components.education.select_working_with_first') }}</option>
                    @foreach($professions as $pr)
                        <option value="{{ $pr->id }}" data-working-with-type-id="{{ $pr->working_with_type_id ?? '' }}"
                            {{ (string) old('profession_id', $profile->profession_id) === (string) $pr->id ? 'selected' : '' }}>{{ $pr->name }}</option>
                    @endforeach
                </select>
                @error('profession_id')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
        </div>
        <script>
        (function() {
            var block = document.currentScript.closest('.career-dependent-block');
            if (!block) return;
            var parentSelect = block.querySelector('.career-parent-select');
            var childSelect = block.querySelector('.career-child-select');
            if (!parentSelect || !childSelect) return;
            var childOptions = Array.prototype.slice.call(childSelect.querySelectorAll('option'));
            var placeholderOption = childOptions[0];
            var professionOptions = childOptions.slice(1);
            function filterChildOptions() {
                var parentVal = parentSelect.value || '';
                var currentVal = childSelect.value || '';
                professionOptions.forEach(function(opt) {
                    var optParent = opt.getAttribute('data-working-with-type-id') || '';
                    var show = parentVal === '' ? false : (String(optParent) === String(parentVal) || (opt.value === currentVal && currentVal !== ''));
                    opt.style.display = show ? '' : 'none';
                    opt.disabled = !show;
                });
                if (parentVal === '') {
                    childSelect.value = '';
                    childSelect.disabled = true;
                    if (placeholderOption) placeholderOption.textContent = @json(__('components.education.select_working_with_first'));
                } else {
                    childSelect.disabled = false;
                    if (placeholderOption) placeholderOption.textContent = @json(__('common.select'));
                    var stillValid = false;
                    professionOptions.forEach(function(opt) {
                        if (opt.value === currentVal && opt.style.display !== 'none') stillValid = true;
                    });
                    if (!stillValid && currentVal) childSelect.value = '';
                }
            }
            parentSelect.addEventListener('change', filterChildOptions);
            filterChildOptions();
        })();
        </script>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900/20 p-4 space-y-3">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('onboarding.career_income_section') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('onboarding.career_income_hint') }}</p>
        <x-income-engine
            :label="__('onboarding.career_income_label')"
            name-prefix="income"
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
