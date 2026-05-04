@props([
    'profile' => null,
    'values' => [],
    'currencies' => [],
    'categories' => null,
    'occupationTypes' => null,
    'employmentStatuses' => null,
    'workingWithTypes' => null,
    'professions' => null,
    'incomeRanges' => null,
    'colleges' => null,
    'namePrefix' => null,
    'mode' => 'compact',
    'showHistory' => null,
    'readOnly' => false,
    'errors' => [],
    'educationHistory' => [],
    'careerHistory' => [],
    'historyNamePrefix' => null,
])
@php
    use App\Models\EducationDegree;
    use App\Services\EducationService;
    use Illuminate\Support\Facades\Schema;
    use App\Models\MasterOccupationType;
    use App\Models\MasterEmploymentStatus;
    use App\Models\MasterIncomeCurrency;
    use App\Models\WorkingWithType;
    use App\Models\Profession;
    use App\Models\IncomeRange;
    use App\Models\College;

    $currencies = (!empty($currencies)) ? $currencies : MasterIncomeCurrency::where('is_active', true)->get();
    $occupationTypes = $occupationTypes ?? MasterOccupationType::where('is_active', true)->orderBy('sort_order')->get();
    $employmentStatuses = $employmentStatuses ?? MasterEmploymentStatus::where('is_active', true)->orderBy('sort_order')->get();
    $workingWithTypes = $workingWithTypes ?? WorkingWithType::where('is_active', true)->orderBy('sort_order')->get();
    $professions = $professions ?? Profession::where('is_active', true)->with('workingWithType')->orderBy('sort_order')->get();
    $incomeRanges = $incomeRanges ?? IncomeRange::where('is_active', true)->orderBy('sort_order')->get(); // legacy
    $colleges = $colleges ?? College::where('is_active', true)->orderBy('sort_order')->get();
    $profile = $profile ?? new \stdClass();
    $namePrefix = $namePrefix ?? '';
    $showHistory = $showHistory ?? ($mode === 'full');
    $errorsArray = is_array($errors) ? $errors : [];
    if ($errors instanceof \Illuminate\Support\ViewErrorBag) {
        $bag = $errors->getBag('default');
        foreach ($bag->getMessages() as $k => $msgs) {
            $errorsArray[$k] = is_array($msgs) ? ($msgs[0] ?? null) : $msgs;
        }
    }
    $educationHistory = $educationHistory ?? [];
    $careerHistory = $careerHistory ?? [];
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

    $hnE = fn($idx, $field) => $historyNamePrefix !== '' ? $historyNamePrefix.'[education_history]['.$idx.']['.$field.']' : 'education_history['.$idx.']['.$field.']';
    $hnC = fn($idx, $field) => $historyNamePrefix !== '' ? $historyNamePrefix.'[career_history]['.$idx.']['.$field.']' : 'career_history['.$idx.']['.$field.']';

    $highestEdRaw = old($oldKey('highest_education'), $v('highest_education'));
    $isOtherEducation = (string)$highestEdRaw === 'Other' || (string)($v('highest_education')) === 'Other';
    $selectedCategoryForEd = null;
    if ($highestEdRaw) {
        $deg = EducationDegree::where('code', $highestEdRaw)->with('category')->first();
        $selectedCategoryForEd = $deg?->category?->name;
    }
    $occupationTypeRaw = old($oldKey('occupation_type'), $v('occupation_type'));
    $companyLabel = 'Company / Business Name';
    if (in_array($occupationTypeRaw, ['Business', 'Self Employed', 'business', 'self_employed'], true)) {
        $companyLabel = 'Business Name / Firm Name';
    } elseif (in_array($occupationTypeRaw, ['Professional Practice', 'professional_practice'], true)) {
        $companyLabel = 'Clinic / Chamber / Office Name';
    }
    $collegeIdRaw = old($oldKey('college_id'), $v('college_id'));
    $workingWithIdRaw = old($oldKey('working_with_type_id'), $v('working_with_type_id'));
    $professionIdRaw = old($oldKey('profession_id'), $v('profession_id'));
    $workCityId = old($oldKey('work_city_id'), $v('work_city_id'));
    $workStateId = old($oldKey('work_state_id'), $v('work_state_id'));
    $workLocationDisplay = '';
    if ($workCityId || $workStateId) {
        $parts = [];
        if ($workCityId) {
            $c = \App\Models\City::find($workCityId);
            if ($c) $parts[] = $c->name;
        }
        if ($workStateId) {
            $s = \App\Models\State::find($workStateId);
            if ($s) $parts[] = $s->name;
        }
        $workLocationDisplay = implode(', ', $parts);
    }
    $inputCls = 'w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2';
    $labelCls = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
    $cardCls = 'rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800/50 p-4';

    // जर education history row मध्ये degree/specialization/university/year पैकी काहीही भरलेलं असेल,
    // तर तो section collapsed न ठेवण्यासाठी flag.
    $hasFilledEducationHistory = collect($educationHistory)->contains(function ($row) {
        $r = is_array($row) ? $row : (array) $row;
        $deg = trim((string) ($r['degree'] ?? ''));
        $spec = trim((string) ($r['specialization'] ?? ''));
        $uni = trim((string) ($r['university'] ?? ''));
        $year = trim((string) ($r['year_completed'] ?? ''));
        return $deg !== '' || $spec !== '' || $uni !== '' || $year !== '';
    });

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
                        $degDisplay = $highestEdRaw ? (EducationDegree::where('code', $highestEdRaw)->value('title') ?? $highestEdRaw) : '';
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
            <div>
                <label class="{{ $labelCls }}">College Attended</label>
                @if($readOnly)
                    @php $collegeName = $collegeIdRaw ? (College::find($collegeIdRaw)?->name ?? '—') : '—'; @endphp
                    <p class="py-2 text-gray-900 dark:text-gray-100">{{ $collegeName }}</p>
                @else
                    <select name="{{ $n('college_id') }}" class="{{ $inputCls }}">
                        <option value="">Select</option>
                        @foreach($colleges as $col)
                            <option value="{{ $col->id }}" {{ (string)$collegeIdRaw === (string)$col->id ? 'selected' : '' }}>{{ $col->name }}{{ $col->city ? ' - ' . $col->city : '' }}</option>
                        @endforeach
                    </select>
                    @if($err('college_id'))<p class="text-red-600 text-xs mt-1">{{ $err('college_id') }}</p>@endif
                @endif
            </div>
            <div>
                <label class="{{ $labelCls }}">Specialization</label>
                @if($readOnly)
                    <p class="py-2 text-gray-900 dark:text-gray-100">{{ $v('specialization') ?: '—' }}</p>
                @else
                    <input type="text" name="{{ $n('specialization') }}" value="{{ old($oldKey('specialization'), $v('specialization')) }}" class="{{ $inputCls }}">
                    @if($err('specialization'))<p class="text-red-600 text-xs mt-1">{{ $err('specialization') }}</p>@endif
                @endif
            </div>
        </div>
        @if($showHistory)
        <details class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600 group" id="education-history-details" {{ $hasFilledEducationHistory ? 'open' : '' }}>
            <summary class="cursor-pointer list-none flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 [&::-webkit-details-marker]:hidden">
                <span aria-hidden="true">🎓</span>
                <span>{{ __('components.education.add_education_history') }}</span>
            </summary>
            <div class="mt-3 pl-6" id="education-history-block">
            <div class="space-y-4" id="education-history-rows">
                @foreach($educationHistory as $idx => $row)
                    @php $row = is_array($row) ? $row : (array)$row; @endphp
                    <div class="flex flex-wrap items-end gap-2 border border-gray-200 dark:border-gray-600 rounded p-2 education-history-row">
                        @if(!empty($row['id']))<input type="hidden" name="{{ $hnE($idx, 'id') }}" value="{{ $row['id'] }}">@endif
                        <div class="flex-1 min-w-[120px]"><label class="text-xs text-gray-600 dark:text-gray-400">Degree</label><input type="text" name="{{ $hnE($idx, 'degree') }}" value="{{ $row['degree'] ?? '' }}" class="{{ $inputCls }} text-sm"></div>
                        <div class="flex-1 min-w-[120px]"><label class="text-xs text-gray-600 dark:text-gray-400">Specialization</label><input type="text" name="{{ $hnE($idx, 'specialization') }}" value="{{ $row['specialization'] ?? '' }}" class="{{ $inputCls }} text-sm"></div>
                        <div class="flex-1 min-w-[120px]"><label class="text-xs text-gray-600 dark:text-gray-400">University / Institute</label><input type="text" name="{{ $hnE($idx, 'university') }}" value="{{ $row['university'] ?? '' }}" class="{{ $inputCls }} text-sm"></div>
                        <div class="w-24"><label class="text-xs text-gray-600 dark:text-gray-400">Year (optional)</label><input type="number" name="{{ $hnE($idx, 'year_completed') }}" value="{{ $row['year_completed'] ?? '' }}" class="{{ $inputCls }} text-sm" placeholder="e.g. 2020"></div>
                        @if(!$readOnly)<div class="education-row-actions flex items-center gap-1 shrink-0"><button type="button" class="remove-education-row flex items-center justify-center w-8 h-8 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 cursor-pointer shrink-0" title="{{ __('common.remove_this_entry') }}" aria-label="{{ __('common.remove_this_entry') }}"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg></button></div>@endif
                    </div>
                @endforeach
            </div>
            </div>
        </details>
        @endif
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
                        <p class="py-2 text-gray-900 dark:text-gray-100">{{ $v('work_location_text') ?: ($workLocationDisplay ?: '—') }}</p>
                    @else
                        <input type="text"
                               name="{{ $n('work_location_text') }}"
                               value="{{ old($oldKey('work_location_text'), $v('work_location_text') ?? $workLocationDisplay) }}"
                               class="{{ $inputCls }}"
                               placeholder="{{ __('components.education.city_area') }}">
                    @endif
                </div>
            </div>
        </div>
        @if($showHistory)
        <details class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600 group" id="career-history-details">
            <summary class="cursor-pointer list-none flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 [&::-webkit-details-marker]:hidden">
                <span aria-hidden="true">💼</span>
                <span>{{ __('components.education.add_career_history') }}</span>
            </summary>
            <div class="mt-3 pl-6" id="career-history-block">
            <div class="space-y-4" id="career-history-rows">
                @foreach($careerHistory as $idx => $row)
                    @php $row = is_array($row) ? $row : (array)$row; $careerRowPrefix = $historyNamePrefix !== '' ? $historyNamePrefix.'[career_history]['.$idx.']' : 'career_history['.$idx.']'; @endphp
                    <div class="flex flex-wrap items-end gap-2 border border-gray-200 dark:border-gray-600 rounded p-2 career-history-row career-history-row-server">
                        @if(!empty($row['id']))<input type="hidden" name="{{ $hnC($idx, 'id') }}" value="{{ $row['id'] }}">@endif
                        <div class="w-full"><label class="text-xs text-gray-600 dark:text-gray-400">Designation</label><input type="text" name="{{ $hnC($idx, 'designation') }}" value="{{ $row['designation'] ?? '' }}" class="{{ $inputCls }} text-sm" placeholder="Role / title"></div>
                        <div class="flex flex-wrap items-end gap-2 w-full" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                            <div class="min-w-0"><label class="text-xs text-gray-600 dark:text-gray-400">Employer name</label><input type="text" name="{{ $hnC($idx, 'company') }}" value="{{ $row['company'] ?? '' }}" class="{{ $inputCls }} text-sm" placeholder="Company / organisation"></div>
                            <div class="min-w-0 career-work-location-cell">
                                <label class="text-xs text-gray-600 dark:text-gray-400">Work location</label>
                                @if($readOnly)
                                    @php $locRo = trim((string) ($row['location'] ?? '')); @endphp
                                    <p class="py-1 text-sm text-gray-900 dark:text-gray-100">{{ $locRo !== '' ? $locRo : '—' }}</p>
                                @else
                                    <input type="hidden" name="{{ $hnC($idx, 'location') }}" value="{{ $row['location'] ?? '' }}" class="career-location-hidden">
                                    <x-profile.location-typeahead
                                        context="alliance"
                                        :namePrefix="$careerRowPrefix"
                                        :value="$row['location'] ?? ''"
                                        :dataCityId="isset($row['city_id']) && $row['city_id'] !== null && $row['city_id'] !== '' ? (string) (int) $row['city_id'] : ''"
                                        :displaySyncName="$hnC($idx, 'location')"
                                        :placeholder="__('components.education.city_area')"
                                        compactRow="true"
                                        noBorder="true"
                                    />
                                @endif
                            </div>
                        </div>
                        <div class="w-20"><label class="text-xs text-gray-600 dark:text-gray-400">Start</label><input type="number" name="{{ $hnC($idx, 'start_year') }}" value="{{ $row['start_year'] ?? '' }}" min="1900" max="2100" class="{{ $inputCls }} text-sm"></div>
                        <div class="w-20"><label class="text-xs text-gray-600 dark:text-gray-400">End</label><input type="number" name="{{ $hnC($idx, 'end_year') }}" value="{{ $row['end_year'] ?? '' }}" min="1900" max="2100" class="{{ $inputCls }} text-sm"></div>
                        <div class="flex items-center gap-1"><label class="text-xs text-gray-600 dark:text-gray-400">Current</label><input type="checkbox" name="{{ $hnC($idx, 'is_current') }}" value="1" {{ !empty($row['is_current']) ? 'checked' : '' }} class="rounded"></div>
                        @if(!$readOnly)<div class="career-row-actions flex items-center gap-1 shrink-0"><button type="button" class="remove-career-row flex items-center justify-center w-8 h-8 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 cursor-pointer shrink-0" title="{{ __('common.remove_this_entry') }}" aria-label="{{ __('common.remove_this_entry') }}"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg></button></div>@endif
                    </div>
                @endforeach
            </div>
            </div>
        </details>
        @endif
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

@if(!$readOnly && $showHistory)
<script>
(function() {
    const MAX_HISTORY_ROWS = 5;
    const eduBlock = document.getElementById('education-history-block');
    const careerBlock = document.getElementById('career-history-block');
    const eduDetails = document.getElementById('education-history-details');
    const careerDetails = document.getElementById('career-history-details');

    if (eduBlock) {
        const eduRows = document.getElementById('education-history-rows');
        const eduPrefix = '{{ $historyNamePrefix !== "" ? $historyNamePrefix."[education_history]" : "education_history" }}';
        const summaryAdd = document.getElementById('education-summary-add');
        const summaryRemove = document.getElementById('education-summary-remove');
        const addEduRow = function() {
            if (eduRows.querySelectorAll('.education-history-row').length >= MAX_HISTORY_ROWS) return;
            const i = eduRows.querySelectorAll('.education-history-row').length;
            const div = document.createElement('div');
            div.className = 'flex flex-wrap items-end gap-2 border border-gray-200 dark:border-gray-600 rounded p-2 education-history-row';
            const tDegree = @json(__('components.education.degree'));
            const tSpec = @json(__('components.education.specialization'));
            const tUniv = @json(__('components.education.university_institute'));
            const tYear = @json(__('components.education.year'));
            const tRemove = @json(__('common.remove_this_entry'));
            div.innerHTML =
                '<div class="flex-1 min-w-[120px]"><label class="text-xs text-gray-600 dark:text-gray-400">' + tDegree + '</label><input type="text" name="' + eduPrefix + '[' + i + '][degree]" class="{{ $inputCls }} text-sm"></div>' +
                '<div class="flex-1 min-w-[120px]"><label class="text-xs text-gray-600 dark:text-gray-400">' + tSpec + '</label><input type="text" name="' + eduPrefix + '[' + i + '][specialization]" class="{{ $inputCls }} text-sm"></div>' +
                '<div class="flex-1 min-w-[120px]"><label class="text-xs text-gray-600 dark:text-gray-400">' + tUniv + '</label><input type="text" name="' + eduPrefix + '[' + i + '][university]" class="{{ $inputCls }} text-sm"></div>' +
                '<div class="w-24"><label class="text-xs text-gray-600 dark:text-gray-400">' + tYear + '</label><input type="number" name="' + eduPrefix + '[' + i + '][year_completed]" min="1900" max="2100" class="{{ $inputCls }} text-sm"></div>' +
                '<div class="education-row-actions flex items-center gap-1 shrink-0"><button type="button" class="remove-education-row flex items-center justify-center w-8 h-8 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 cursor-pointer shrink-0" title="' + tRemove + '" aria-label="' + tRemove + '"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg></button></div>';
            eduRows.appendChild(div);
            refreshSummaryEdu();
            refreshEduRowAddButtons();
        };
        function refreshSummaryEdu() {
            var n = eduRows.querySelectorAll('.education-history-row').length;
            if (summaryAdd) summaryAdd.classList.toggle('hidden', n > 0);
            if (summaryRemove) summaryRemove.classList.toggle('hidden', n === 0);
        }
        function refreshEduRowAddButtons() {
            var rows = eduRows.querySelectorAll('.education-history-row');
            rows.forEach(function(row) {
                var actions = row.querySelector('.education-row-actions');
                if (!actions) return;
                var existing = actions.querySelector('.add-education-row-after');
                if (existing) existing.remove();
            });
            if (rows.length > 0 && rows.length < MAX_HISTORY_ROWS) {
                var last = rows[rows.length - 1];
                var actions = last.querySelector('.education-row-actions');
                if (actions) {
                    var plusBtn = document.createElement('button');
                    plusBtn.type = 'button';
                    plusBtn.className = 'add-education-row-after flex items-center justify-center w-8 h-8 rounded text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 cursor-pointer shrink-0';
                    plusBtn.title = @json(__('components.education.add_another_entry'));
                    plusBtn.setAttribute('aria-label', @json(__('components.education.add_another_entry')));
                    plusBtn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>';
                    actions.appendChild(plusBtn);
                }
            }
        }
        summaryAdd?.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); addEduRow(); });
        summaryRemove?.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); var first = eduRows.querySelector('.education-history-row'); if (first) first.remove(); refreshSummaryEdu(); refreshEduRowAddButtons(); });
        eduRows.addEventListener('click', function(e) {
            if (e.target.closest('.remove-education-row')) { e.target.closest('.education-history-row')?.remove(); refreshSummaryEdu(); refreshEduRowAddButtons(); }
            else if (e.target.closest('.add-education-row-after')) { e.preventDefault(); e.stopPropagation(); addEduRow(); }
        });
        eduDetails?.addEventListener('toggle', function() { if (eduDetails.open && eduRows.querySelectorAll('.education-history-row').length === 0) addEduRow(); });
        refreshSummaryEdu();
        refreshEduRowAddButtons();
    }

    if (careerBlock) {
        const careerRows = document.getElementById('career-history-rows');
        const careerPrefix = '{{ $historyNamePrefix !== "" ? $historyNamePrefix."[career_history]" : "career_history" }}';
        const careerSummaryAdd = document.getElementById('career-summary-add');
        const careerSummaryRemove = document.getElementById('career-summary-remove');
        const addCareerRow = function() {
            var rows = careerRows.querySelectorAll('.career-history-row');
            if (rows.length >= MAX_HISTORY_ROWS) return;
            var i = rows.length;
            var lastRow = rows[rows.length - 1];
            if (!lastRow) {
                var tDes = @json(__('components.education.designation'));
                var tEmp = @json(__('components.education.employer_name'));
                var tWl = @json(__('components.education.work_location'));
                var tStart = @json(__('components.education.start'));
                var tEnd = @json(__('components.education.end'));
                var tCur = @json(__('components.education.current'));
                var tRemove = @json(__('common.remove_this_entry'));
                var tCity = @json(__('components.education.city_area'));
                var div = document.createElement('div');
                div.className = 'flex flex-wrap items-end gap-2 border border-gray-200 dark:border-gray-600 rounded p-2 career-history-row';
                var p = careerPrefix;
                div.innerHTML =
                    '<div class="w-full"><label class="text-xs text-gray-600 dark:text-gray-400">' + tDes + '</label><input type="text" name="' + p + '[0][designation]" class="{{ $inputCls }} text-sm" placeholder="' + @json(__('components.education.role_title')) + '"></div>' +
                    '<div class="flex flex-wrap items-end gap-2 w-full" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">' +
                    '<div class="min-w-0"><label class="text-xs text-gray-600 dark:text-gray-400">' + tEmp + '</label><input type="text" name="' + p + '[0][company]" class="{{ $inputCls }} text-sm" placeholder="Company / organisation"></div>' +
                    '<div class="min-w-0 career-work-location-cell"><label class="text-xs text-gray-600 dark:text-gray-400">' + tWl + '</label><input type="text" name="' + p + '[0][location]" class="{{ $inputCls }} text-sm" placeholder="' + tCity + '"></div></div>' +
                    '<div class="w-20"><label class="text-xs text-gray-600 dark:text-gray-400">' + tStart + '</label><input type="number" name="' + p + '[0][start_year]" min="1900" max="2100" class="{{ $inputCls }} text-sm"></div>' +
                    '<div class="w-20"><label class="text-xs text-gray-600 dark:text-gray-400">' + tEnd + '</label><input type="number" name="' + p + '[0][end_year]" min="1900" max="2100" class="{{ $inputCls }} text-sm"></div>' +
                    '<div class="flex items-center gap-1"><label class="text-xs text-gray-600 dark:text-gray-400">' + tCur + '</label><input type="checkbox" name="' + p + '[0][is_current]" value="1" class="rounded"></div>' +
                    '<div class="career-row-actions flex items-center gap-1 shrink-0"><button type="button" class="remove-career-row flex items-center justify-center w-8 h-8 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 cursor-pointer shrink-0" title="' + tRemove + '" aria-label="' + tRemove + '"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg></button></div>';
                careerRows.appendChild(div);
                refreshSummaryCareer();
                refreshCareerRowAddButtons();
                return;
            }
            var clone = lastRow.cloneNode(true);
            clone.classList.remove('career-history-row-server');
            var oldIdx = i - 1;
            var escaped = careerPrefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            var re = new RegExp(escaped + '\\[' + oldIdx + '\\]', 'g');
            clone.querySelectorAll('input, select, textarea').forEach(function(el) {
                if (el.name) el.name = el.name.replace(re, careerPrefix + '[' + i + ']');
                if (el.type === 'checkbox') el.checked = false;
                else if (el.type !== 'hidden') el.value = '';
            });
            var idInp = clone.querySelector('input[name*="[id]"]');
            if (idInp) idInp.value = '';
            clone.querySelectorAll('.location-typeahead-wrapper').forEach(function(w) {
                w.removeAttribute('data-bound');
                var inp = w.querySelector('.location-typeahead-input');
                if (inp) inp.value = '';
                w.querySelectorAll('.location-hidden-city, .location-hidden-taluka, .location-hidden-district, .location-hidden-state').forEach(function(h) { h.value = ''; });
            });
            var locHidden = clone.querySelector('.career-location-hidden');
            if (locHidden) locHidden.value = '';
            careerRows.appendChild(clone);
            if (window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init();
            syncCareerWorkLocationInputs();
            refreshSummaryCareer();
            refreshCareerRowAddButtons();
        };
        function refreshSummaryCareer() {
            var n = careerRows.querySelectorAll('.career-history-row').length;
            if (careerSummaryAdd) careerSummaryAdd.classList.toggle('hidden', n > 0);
            if (careerSummaryRemove) careerSummaryRemove.classList.toggle('hidden', n === 0);
        }
        function refreshCareerRowAddButtons() {
            var rows = careerRows.querySelectorAll('.career-history-row');
            rows.forEach(function(row) {
                var actions = row.querySelector('.career-row-actions');
                if (!actions) return;
                var existing = actions.querySelector('.add-career-row-after');
                if (existing) existing.remove();
            });
            if (rows.length > 0 && rows.length < MAX_HISTORY_ROWS) {
                var last = rows[rows.length - 1];
                var actions = last.querySelector('.career-row-actions');
                if (actions) {
                    var plusBtn = document.createElement('button');
                    plusBtn.type = 'button';
                    plusBtn.className = 'add-career-row-after flex items-center justify-center w-8 h-8 rounded text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 cursor-pointer shrink-0';
                    plusBtn.title = 'Add another entry';
                    plusBtn.setAttribute('aria-label', 'Add another entry');
                    plusBtn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>';
                    actions.appendChild(plusBtn);
                }
            }
        }
        careerSummaryAdd?.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); addCareerRow(); });
        careerSummaryRemove?.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); var first = careerRows.querySelector('.career-history-row'); if (first) first.remove(); refreshSummaryCareer(); refreshCareerRowAddButtons(); });
        careerRows.addEventListener('click', function(e) {
            if (e.target.closest('.remove-career-row')) { e.target.closest('.career-history-row')?.remove(); refreshSummaryCareer(); refreshCareerRowAddButtons(); }
            else if (e.target.closest('.add-career-row-after')) { e.preventDefault(); e.stopPropagation(); addCareerRow(); }
        });
        careerDetails?.addEventListener('toggle', function() { if (careerDetails.open && careerRows.querySelectorAll('.career-history-row').length === 0) addCareerRow(); if (careerDetails.open && window.LocationTypeahead && window.LocationTypeahead.init) window.LocationTypeahead.init(); syncCareerWorkLocationInputs(); });
        refreshSummaryCareer();
        refreshCareerRowAddButtons();
        function syncCareerWorkLocationInputs() {
            document.querySelectorAll('.career-work-location-cell').forEach(function(cell) {
                var visible = cell.querySelector('.location-typeahead-input');
                var hidden = cell.querySelector('.career-location-hidden');
                if (!visible || !hidden) return;
                if (visible._careerLocationSynced) return;
                visible._careerLocationSynced = true;
                visible.addEventListener('input', function() { hidden.value = visible.value; });
                visible.addEventListener('change', function() { hidden.value = visible.value; });
                if (visible.value) hidden.value = visible.value;
            });
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function() { setTimeout(syncCareerWorkLocationInputs, 300); });
        else setTimeout(syncCareerWorkLocationInputs, 300);
    }
})();
</script>
@endif
