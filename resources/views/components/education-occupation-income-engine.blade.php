@props([
    'profile' => null,
    'values' => [],
    'currencies' => [],
    'categories' => null,
    'workingWithTypes' => null,
    'professions' => null,
    'incomeRanges' => null,
    'namePrefix' => null,
    'mode' => 'compact',
    'readOnly' => false,
    'errors' => [],
    'educationHistory' => [],
    'historyNamePrefix' => null,
])
@php
    use App\Models\EducationDegree;
    use App\Services\EducationService;
    use Illuminate\Support\Facades\Schema;
    use App\Models\MasterIncomeCurrency;
    use App\Models\WorkingWithType;
    use App\Models\Profession;
    use App\Models\IncomeRange;

    $currencies = (!empty($currencies)) ? $currencies : MasterIncomeCurrency::where('is_active', true)->get();
    $workingWithTypes = $workingWithTypes ?? WorkingWithType::where('is_active', true)->orderBy('sort_order')->get();
    $professions = $professions ?? Profession::where('is_active', true)->with('workingWithType')->orderBy('sort_order')->get();
    $incomeRanges = $incomeRanges ?? IncomeRange::where('is_active', true)->orderBy('sort_order')->get(); // legacy
    $profile = $profile ?? new \stdClass();
    $namePrefix = $namePrefix ?? '';
    $errorsArray = is_array($errors) ? $errors : [];
    if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
        $bag = $errors->getBag('default');
        foreach ($bag->getMessages() as $k => $msgs) {
            $errorsArray[$k] = is_array($msgs) ? ($msgs[0] ?? null) : $msgs;
        }
    }
    $educationHistory = $educationHistory ?? [];
    $historyNamePrefix = $historyNamePrefix ?? '';

    $n = fn($base) => $namePrefix ? $namePrefix.'['.$base.']' : $base;
    $oldKey = fn($key) => $namePrefix ? str_replace(']', '', str_replace('[', '.', $namePrefix.'['.$key.']')) : $key;
    $v = function($key) use ($profile, $values, $namePrefix) {
        if ($namePrefix !== '' && is_array($values) && array_key_exists($key, $values)) {
            return $values[$key];
        }
        return $profile->$key ?? null;
    };
    $err = fn($key) => $errorsArray[$key] ?? null;

    $highestEdRaw = old($oldKey('highest_education'), $v('highest_education'));
    $isOtherEducation = (string)$highestEdRaw === 'Other' || (string)($v('highest_education')) === 'Other';
    $selectedCategoryForEd = null;
    if ($highestEdRaw) {
        $deg = EducationDegree::where('code', $highestEdRaw)->with('category')->first();
        $selectedCategoryForEd = $deg?->category?->name;
    }
    $workingWithIdRaw = old($oldKey('working_with_type_id'), $v('working_with_type_id'));
    $professionIdRaw = old($oldKey('profession_id'), $v('profession_id'));
    $workCityId = old('work_city_id', old($oldKey('work_city_id'), $v('work_city_id')));
    $workStateId = old('work_state_id', old($oldKey('work_state_id'), $v('work_state_id')));
    $workTypeaheadDisplay = '';
    if ($profile instanceof \App\Models\MatrimonyProfile) {
        $workHintsEarly = $profile->workCityHierarchyHints();
        if (($workCityId === null || $workCityId === '') && ($workHintsEarly['location_id'] ?? '') !== '') {
            $workCityId = $workHintsEarly['location_id'];
        }
        if (($workStateId === null || $workStateId === '') && ($workHintsEarly['state_id'] ?? '') !== '') {
            $workStateId = $workHintsEarly['state_id'];
        }
        $workTypeaheadDisplay = trim($profile->workLocationDisplayLine());
        if ($workTypeaheadDisplay === '' && trim((string) ($profile->work_location_text ?? '')) !== '') {
            $workTypeaheadDisplay = trim((string) $profile->work_location_text);
        }
    }
    $workTypeaheadDisplay = old('work_location_text', old($oldKey('work_location_text'), $workTypeaheadDisplay !== '' ? $workTypeaheadDisplay : (string) ($v('work_location_text') ?? '')));
    $inputCls = 'w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2';
    $labelCls = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
    $cardCls = 'rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/50 p-4';

    // Intake preview uses a stdClass "intakeProfile" — still support the Tom Select engine when the DB column exists.
    $useDegreeMultiselectEngine = Schema::hasColumn('matrimony_profiles', 'highest_education')
        && is_object($profile);

    $hasOccupationEngine = Schema::hasColumn('matrimony_profiles', 'occupation_master_id');
@endphp
<div class="education-occupation-income-engine space-y-6 border border-gray-200 dark:border-gray-600 rounded-lg p-4">
    {{-- Education engine: snapshot + history एकत्र --}}
    <div class="{{ $cardCls }}">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Education</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @if($readOnly)
                @php
                    if ($useDegreeMultiselectEngine) {
                        $degDisplay = $profile instanceof \App\Models\MatrimonyProfile
                            ? app(EducationService::class)->displayHighestEducation($profile)
                            : app(EducationService::class)->formatEducationDisplayLineFromObject($profile);
                    } else {
                        $degDisplay = $highestEdRaw ? (EducationDegree::where('code', $highestEdRaw)->value('code') ?? $highestEdRaw) : '';
                    }
                @endphp
                <div class="md:col-span-2">
                    <label class="{{ $labelCls }}">Highest Education</label>
                    <p class="py-2 text-gray-900 dark:text-gray-100">{{ ($degDisplay !== '' && $degDisplay !== null) ? $degDisplay : '—' }}</p>
                </div>
            @else
                @if($useDegreeMultiselectEngine)
                    <div class="md:col-span-2">
                        <x-education-multiselect-engine
                            :profile="$profile"
                            :name-prefix="$namePrefix"
                        />
                    </div>
                @else
                    <div class="md:col-span-2">
                        <x-education-hierarchy-select
                            :categories="$categories"
                            :namePrefix="$namePrefix"
                            categoryName="education_category"
                            degreeName="highest_education"
                            :selectedCategory="$selectedCategoryForEd"
                            :selectedDegree="$highestEdRaw"
                            mode="dependent"
                            labelCategory="Education Category"
                            labelDegree="Education Degree"
                        />
                        @if($err('highest_education'))<p class="text-red-600 text-xs mt-1">{{ $err('highest_education') }}</p>@endif
                    </div>
                    <div data-other-ed class="{{ $isOtherEducation ? '' : 'hidden' }} md:col-span-2">
                        <label class="{{ $labelCls }}">Other (please specify)</label>
                        <input type="text" name="{{ $n('highest_education_other') }}" value="{{ old($oldKey('highest_education_other'), $v('highest_education_other')) }}" class="{{ $inputCls }}" placeholder="Specify">
                    </div>
                    <script>
                    (function(){
                        var engine = document.currentScript.closest('.education-occupation-income-engine');
                        if (!engine) return;
                        var degSelect = engine.querySelector('.education-degree-select');
                        var otherBlock = engine.querySelector('[data-other-ed]');
                        if (!degSelect || !otherBlock) return;
                        function toggle(){ otherBlock.classList.toggle('hidden', degSelect.value !== 'Other'); }
                        degSelect.addEventListener('change', toggle);
                        toggle();
                    })();
                    </script>
                @endif
            @endif
        </div>
    </div>

    {{-- Career engine: snapshot + history एकत्र --}}
    <div class="{{ $cardCls }} career-dependent-block">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">{{ __('components.education.career') }}</h3>
        <div class="space-y-4">
            @if($hasOccupationEngine)
                <x-occupation-search-engine
                    :profile="$profile"
                    :name-prefix="$namePrefix"
                    :read-only="$readOnly"
                />
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="{{ $labelCls }}">{{ __('components.education.working_with') }}</label>
                        @if($readOnly)
                            @php $ww = $workingWithIdRaw ? (WorkingWithType::find($workingWithIdRaw)?->name ?? '—') : '—'; @endphp
                            <p class="py-2 text-gray-900 dark:text-gray-100">{{ $ww }}</p>
                        @else
                            <select name="{{ $n('working_with_type_id') }}" class="{{ $inputCls }} career-parent-select">
                                <option value="">{{ __('common.select') }}</option>
                                @foreach($workingWithTypes as $ww)
                                    <option value="{{ $ww->id }}" {{ (string)$workingWithIdRaw === (string)$ww->id ? 'selected' : '' }}>{{ $ww->name }}</option>
                                @endforeach
                            </select>
                            @if($err('working_with_type_id'))<p class="text-red-600 text-xs mt-1">{{ $err('working_with_type_id') }}</p>@endif
                        @endif
                    </div>
                    <div>
                        <label class="{{ $labelCls }}">{{ __('components.education.working_as') }}</label>
                        @if($readOnly)
                            @php $prof = $professionIdRaw ? (Profession::find($professionIdRaw)?->name ?? '—') : '—'; @endphp
                            <p class="py-2 text-gray-900 dark:text-gray-100">{{ $prof }}</p>
                        @else
                            <select name="{{ $n('profession_id') }}" class="{{ $inputCls }} career-child-select">
                                <option value="">{{ __('components.education.select_working_with_first') }}</option>
                                @foreach($professions as $pr)
                                    <option value="{{ $pr->id }}" data-working-with-type-id="{{ $pr->working_with_type_id ?? '' }}" {{ (string)$professionIdRaw === (string)$pr->id ? 'selected' : '' }}>{{ $pr->name }}</option>
                                @endforeach
                            </select>
                            @if($err('profession_id'))<p class="text-red-600 text-xs mt-1">{{ $err('profession_id') }}</p>@endif
                        @endif
                    </div>
                </div>
            @endif
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 w-full">
                <div class="min-w-0">
                    <label class="{{ $labelCls }}">{{ __('components.education.employer_name') }}</label>
                    @if($readOnly)
                        <p class="py-2 text-gray-900 dark:text-gray-100">{{ $v('company_name') ?: '—' }}</p>
                    @else
                        <input type="text" name="{{ $n('company_name') }}" value="{{ old($oldKey('company_name'), $v('company_name')) }}" class="{{ $inputCls }}" placeholder="{{ __('components.education.company_org') }}">
                        @if($err('company_name'))<p class="text-red-600 text-xs mt-1">{{ $err('company_name') }}</p>@endif
                    @endif
                </div>
                <div class="min-w-0">
                    <label class="{{ $labelCls }}">{{ __('components.education.work_location') }}</label>
                    @if($readOnly)
                        @php
                            $workRo = '';
                            if ($profile instanceof \App\Models\MatrimonyProfile) {
                                $workRo = trim($profile->workLocationDisplayLine());
                                if ($workRo === '' && trim((string) ($profile->work_location_text ?? '')) !== '') {
                                    $workRo = trim((string) $profile->work_location_text);
                                }
                            }
                            if ($workRo === '') {
                                $workRo = trim((string) ($v('work_location_text') ?? ''));
                            }
                        @endphp
                        <p class="py-2 text-gray-900 dark:text-gray-100">{{ $workRo !== '' ? $workRo : '—' }}</p>
                    @else
                        <input type="hidden" name="{{ $n('work_location_text') }}" value="{{ old($oldKey('work_location_text'), $v('work_location_text')) }}">
                        <x-profile.location-typeahead
                            context="work"
                            :value="$workTypeaheadDisplay"
                            :placeholder="__('wizard.type_city_area')"
                            label=""
                            :noBorder="true"
                            :compactRow="true"
                            :displaySyncName="$n('work_location_text')"
                            :data-work-city-id="(string) ($workCityId ?? '')"
                            :data-work-state-id="(string) ($workStateId ?? '')"
                        />
                    @endif
                </div>
            </div>
        </div>
        @if(!$readOnly)
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
                    var selectedOption = childSelect.options[childSelect.selectedIndex];
                    var selectedParent = selectedOption && selectedOption.value ? (selectedOption.getAttribute('data-working-with-type-id') || '') : '';
                    if (currentVal && selectedParent !== '' && String(selectedParent) !== String(parentVal)) childSelect.value = '';
                }
            }
            parentSelect.addEventListener('change', filterChildOptions);
            filterChildOptions();
        })();
        </script>
        @endif
    </div>

    {{-- Income engine --}}
    <x-income-engine
        :label="__('components.education.income')"
        namePrefix="income"
        :values="$values"
        :profile="$profile"
        :currencies="$currencies"
        :privacy-enabled="true"
        :read-only="$readOnly"
        :errors="$errorsArray"
    />
</div>

@if(!$readOnly)
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.LocationTypeahead && window.LocationTypeahead.init) {
        window.LocationTypeahead.init();
    }
});
</script>
@endif
