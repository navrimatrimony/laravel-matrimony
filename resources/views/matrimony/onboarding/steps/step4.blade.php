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
        <div class="grid grid-cols-1 gap-4">
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

    <div class="flex flex-col sm:flex-row gap-3 pt-2">
        <a href="{{ route('matrimony.onboarding.show', ['step' => 3]) }}" class="inline-flex justify-center items-center min-h-[52px] px-6 rounded-xl text-base font-semibold border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 w-full sm:w-auto text-center">
            {{ __('onboarding.back') }}
        </a>
        <button type="submit" class="inline-flex justify-center items-center min-h-[52px] px-6 rounded-xl text-base font-semibold text-white bg-indigo-600 hover:bg-indigo-700 w-full sm:flex-1">
            {{ __('onboarding.continue') }}
        </button>
    </div>
</form>
