{{-- MaritalEngine: single canonical UI for marital status + children. Used in wizard (marriages/full) and intake preview. Pass namePrefix='snapshot' for intake so names become snapshot[core][...], snapshot[marriages][...], snapshot[children][...]. Pass showMaritalStatus=false when used in marriages step only (status already in basic info). --}}
@php
    $namePrefix = $namePrefix ?? '';
    $isSnapshot = $namePrefix === 'snapshot';
    $showMaritalStatus = $showMaritalStatus ?? true;
    $maritalStatuses = $maritalStatuses ?? collect();
    // Use the latest marriage row (highest id) so UI reflects the most recent saved legal status.
    $marriage = ($profileMarriages ?? collect())->sortByDesc('id')->first();
    $profileChildren = $profileChildren ?? collect();
    $childLivingWithOptions = $childLivingWithOptions ?? collect();
    $oldCore = $isSnapshot ? 'snapshot.core' : null;
    $currentStatusId = $oldCore ? old($oldCore . '.marital_status_id', $profile->marital_status_id ?? '') : old('marital_status_id', $profile->marital_status_id ?? '');
    $currentKey = $maritalStatuses->firstWhere('id', $currentStatusId)?->key ?? '';
    $hasChildrenValue = $oldCore ? old($oldCore . '.has_children', $profile->has_children) : old('has_children', $profile->has_children);
    $showChildrenQuestion = in_array($currentKey, ['divorced', 'annulled', 'separated', 'widowed'], true);
    $showChildrenDetails = $showChildrenQuestion && ($hasChildrenValue === true || $hasChildrenValue === '1' || $hasChildrenValue === 1);
    $coreName = $isSnapshot ? 'snapshot[core][' : '';
    $coreNameSuffix = $isSnapshot ? ']' : '';
    $marriagesPrefix = $isSnapshot ? 'snapshot[marriages][0][' : 'marriages[0][';
    $marriagesSuffix = ']';
@endphp
<div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-white dark:bg-gray-800 space-y-7"
     data-name-prefix="{{ $namePrefix }}"
     x-data="maritalEngineState({{ json_encode($currentStatusId) }}, {{ json_encode($currentKey) }}, {{ $showChildrenQuestion ? 'true' : 'false' }}, {{ $showChildrenDetails ? 'true' : 'false' }}, {{ json_encode($hasChildrenValue) }}, {{ json_encode($profileChildren->map(fn($c) => ['id' => $c->id ?? null, 'gender' => $c->gender ?? '', 'age' => $c->age ?? '', 'child_living_with_id' => $c->child_living_with_id ?? ''])->values()->toArray()) }}, {{ json_encode($childLivingWithOptions->pluck('id')->toArray()) }}, {{ json_encode($namePrefix) }})"
     x-init="init()"
     @marital-status-change.window="onStatusChange($event.detail)">
    @if($showMaritalStatus)
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">{{ __('Marital status') }}</h2>
        {{-- Step 1: Marital status (radios) — bold, spaced, card-style options --}}
        <div>
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Marital status') }} <span class="text-red-500">*</span></p>
            <div class="w-full overflow-x-auto overflow-y-visible -mx-1 px-1 sm:mx-0 sm:px-0 pb-1 scroll-pl-2 snap-x snap-mandatory">
                <div class="flex flex-nowrap gap-2 sm:gap-2.5 items-stretch min-w-min py-0.5">
                @foreach($maritalStatuses as $s)
                    <label class="snap-start inline-flex items-center justify-center cursor-pointer rounded-lg border-2 pl-3 pr-3.5 sm:pl-4 sm:pr-4 py-2 sm:py-2.5 transition-all duration-150 shrink-0 min-h-[42px] whitespace-nowrap
                        hover:border-gray-300 dark:hover:border-gray-500
                        focus-within:ring-2 focus-within:ring-indigo-400 focus-within:ring-offset-1 dark:focus-within:ring-offset-gray-800"
                        :class="maritalStatusId == '{{ $s->id }}' ? 'border-indigo-600 bg-indigo-600 dark:bg-indigo-500 dark:border-indigo-400 shadow-md ring-2 ring-indigo-400/30' : 'border-gray-200 dark:border-gray-600 bg-gray-50/50 dark:bg-gray-700/30'">
                        <input type="radio" name="{{ $coreName }}marital_status_id{{ $coreNameSuffix }}" value="{{ $s->id }}"
                               {{ (string) $currentStatusId === (string) $s->id ? 'checked' : '' }}
                               class="rounded-full border-2 border-gray-400 flex-shrink-0 w-3.5 h-3.5 accent-indigo-600"
                               x-model="maritalStatusId"
                               @change="onMaritalChange()">
                        <span class="ml-1.5 sm:ml-2 text-xs font-semibold whitespace-nowrap"
                              :class="maritalStatusId == '{{ $s->id }}' ? 'text-white' : 'text-gray-800 dark:text-gray-200'">{{ __($s->label) }}</span>
                    </label>
                @endforeach
                </div>
            </div>
            @error($isSnapshot ? 'snapshot.core.marital_status_id' : 'marital_status_id')
                <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
    @else
        {{-- Marriages step only: marital status already set in basic info; submit current value via hidden --}}
        <input type="hidden" :name="namePrefix ? 'snapshot[core][marital_status_id]' : 'marital_status_id'" :value="maritalStatusId">
    @endif

    {{-- Step 2: Status details (optional) + Children — heading line then inputs; Yes/No as on-off toggle --}}
    <div class="marital-details-block" x-show="statusKey === 'divorced' || statusKey === 'annulled' || statusKey === 'separated' || statusKey === 'widowed'" x-cloak style="display: none;">
        {{-- Heading line: Status details + Children (required) — visible section heading --}}
        <div class="flex flex-wrap items-center gap-4 pb-2 mb-2 border-b border-gray-200 dark:border-gray-600">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ __('Status details (optional)') }}</h3>
            <span class="text-gray-400 dark:text-gray-500" aria-hidden="true">|</span>
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ __('Children') }} <span class="text-red-500">*</span></h3>
        </div>
        <div class="flex flex-wrap sm:flex-nowrap gap-2 sm:gap-3 items-end">
            <div class="flex-shrink-0" style="min-width: 6rem;">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">{{ __('Marriage year') }}</label>
                <input type="number" name="marriages[0][marriage_year]" min="1901" max="{{ date('Y') }}"
                       value="{{ old('marriages.0.marriage_year', $marriage?->marriage_year ?? '') }}"
                       class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
            </div>
            <div class="flex-shrink-0" x-show="statusKey === 'divorced' || statusKey === 'annulled'" style="min-width: 6rem;">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">
                    <span x-show="statusKey === 'divorced'">{{ __('wizard.divorce_year') }}</span>
                    <span x-show="statusKey === 'annulled'" x-cloak style="display: none;">{{ __('wizard.annulment_year') }}</span>
                </label>
                <input type="number" name="{{ $marriagesPrefix }}divorce_year{{ $marriagesSuffix }}" min="1901" max="{{ date('Y') }}"
                       value="{{ old('marriages.0.divorce_year', $marriage?->divorce_year ?? '') }}"
                       class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
            </div>
            <div class="flex-shrink-0" x-show="statusKey === 'separated'" style="min-width: 6.5rem;" x-cloak>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">{{ __('Separation year') }}</label>
                <input type="number" name="{{ $marriagesPrefix }}separation_year{{ $marriagesSuffix }}" min="1901" max="{{ date('Y') }}"
                       value="{{ old('marriages.0.separation_year', $marriage?->separation_year ?? '') }}"
                       class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
            </div>
            <div class="flex-shrink-0" x-show="statusKey === 'widowed'" style="min-width: 7rem;">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">{{ __('wizard.spouse_death_year') }}</label>
                <input type="number" name="{{ $marriagesPrefix }}spouse_death_year{{ $marriagesSuffix }}" min="1901" max="{{ date('Y') }}"
                       value="{{ old('marriages.0.spouse_death_year', $marriage?->spouse_death_year ?? '') }}"
                       class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
            </div>
            <div class="flex-1 min-w-0" x-show="statusKey === 'divorced' || statusKey === 'annulled' || statusKey === 'separated'" style="min-width: 6rem;">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">{{ __('wizard.legal_status') }}</label>
                <select name="{{ $marriagesPrefix }}divorce_status{{ $marriagesSuffix }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
                    <option value="">—</option>
                    <option value="pending" {{ old('marriages.0.divorce_status', $marriage?->divorce_status ?? '') == 'pending' ? 'selected' : '' }}>{{ __('wizard.divorce_pending') }}</option>
                    <option value="finalized" {{ old('marriages.0.divorce_status', $marriage?->divorce_status ?? '') == 'finalized' ? 'selected' : '' }}>{{ __('wizard.divorce_finalized') }}</option>
                    <option value="mutual" {{ old('marriages.0.divorce_status', $marriage?->divorce_status ?? '') == 'mutual' ? 'selected' : '' }}>{{ __('wizard.divorce_mutual') }}</option>
                    <option value="contested" {{ old('marriages.0.divorce_status', $marriage?->divorce_status ?? '') == 'contested' ? 'selected' : '' }}>{{ __('wizard.divorce_contested') }}</option>
                </select>
            </div>
            {{-- Children Yes/No: heading + toggle. Yes selected = green. --}}
            <div class="flex-shrink-0 flex flex-col">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">{{ __('Children') }} <span class="text-red-500">*</span></label>
                <input type="hidden" :name="namePrefix ? 'snapshot[core][has_children]' : 'has_children'" :value="hasChildrenValue">
                <div class="inline-flex rounded-full border-2 border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 p-0.5 h-[42px] items-stretch" role="group">
                    <button type="button" @click="hasChildrenValue = '0'; onHasChildrenChange()"
                            class="px-3 py-2 text-sm font-medium rounded-full transition-all min-w-[3.5rem] flex items-center justify-center"
                            :class="hasChildrenValue == '0' ? 'bg-white dark:bg-gray-600 text-gray-800 dark:text-gray-200 shadow' : 'text-gray-500 dark:text-gray-400'">
                        {{ __('wizard.no') }}
                    </button>
                    <button type="button" @click="hasChildrenValue = '1'; onHasChildrenChange()"
                            class="px-3 py-2 text-sm font-medium rounded-full transition-all min-w-[3.5rem] flex items-center justify-center"
                            :class="hasChildrenValue == '1' ? 'bg-green-600 text-white shadow' : 'text-gray-500 dark:text-gray-400'">
                        {{ __('wizard.yes') }}
                    </button>
                </div>
            </div>
        </div>
        @error($isSnapshot ? 'snapshot.core.has_children' : 'has_children')
            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror
    </div>

    {{-- Hidden marriage row id + marital_status_id so row exists for intake/wizard when status is never_married (details block hidden). --}}
    <input type="hidden" name="{{ $marriagesPrefix }}id{{ $marriagesSuffix }}" value="{{ $marriage?->id ?? '' }}">
    <input type="hidden" :name="namePrefix ? 'snapshot[marriages][0][marital_status_id]' : 'marriages[0][marital_status_id]'" :value="maritalStatusId" x-show="false">

    {{-- Step 4: Children details (only if has_children = Yes) — one line per child --}}
    <div id="marital-children-details" x-show="showChildrenDetails" x-cloak style="display: none;">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">{{ __('wizard.children_details') }}</h3>
        <div class="space-y-3" x-ref="childrenContainer">
            <template x-for="(child, index) in children" :key="'child-' + index">
                <div class="flex flex-wrap sm:flex-nowrap gap-2 sm:gap-3 items-end border border-gray-200 dark:border-gray-600 rounded-lg p-3 sm:p-4 bg-gray-50 dark:bg-gray-700/30">
                    <div class="flex-shrink-0" style="min-width: 5.5rem;">
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">{{ __('wizard.gender') }}</label>
                        <select :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][gender]'" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]" x-model="child.gender">
                            <option value="">{{ __('wizard.select') }}</option>
                            <option value="male">{{ __('wizard.male') }}</option>
                            <option value="female">{{ __('wizard.female') }}</option>
                            <option value="other">{{ __('wizard.other') }}</option>
                            <option value="prefer_not_say">{{ __('wizard.prefer_not_say') }}</option>
                        </select>
                    </div>
                    <div class="flex-shrink-0" style="min-width: 4rem;">
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">{{ __('wizard.age') }} <span class="text-red-500">*</span></label>
                        <input type="number" :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][age]'" min="1" max="120" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]" x-model.number="child.age" placeholder="{{ __('wizard.age') }}">
                    </div>
                    <div class="flex-1 min-w-0" style="min-width: 6rem;">
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">{{ __('wizard.living_with') }}</label>
                        <select :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][child_living_with_id]'" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]" x-model="child.child_living_with_id">
                            <option value="">{{ __('wizard.select') }}</option>
                            @foreach($childLivingWithOptions as $opt)
                                <option value="{{ $opt->id }}">{{ $opt->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="w-full sm:w-auto sm:flex-shrink-0 flex sm:items-end pt-2 sm:pt-0 border-t sm:border-t-0 border-gray-200/80 dark:border-gray-600 sm:pl-2">
                        <div class="flex flex-row flex-wrap items-center gap-x-4 gap-y-2 w-full sm:w-auto sm:justify-end">
                            <input type="hidden" :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][id]'" :value="child.id || ''">
                            <input type="hidden" :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][sort_order]'" :value="index">
                            <button type="button"
                                    @click="addChild()"
                                    class="text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 underline-offset-2 hover:underline">
                                + {{ __('wizard.add') }}
                            </button>
                            <button type="button"
                                    @click="removeChild(index)"
                                    class="text-sm font-medium text-rose-700 dark:text-rose-400 hover:text-rose-900 dark:hover:text-rose-300 underline-offset-2 hover:underline whitespace-nowrap">
                                {{ __('wizard.remove_entry') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', function() {
    function maritalEngineState(initialStatusId, initialKey, initialShowQuestion, initialShowDetails, initialHasChildren, initialChildren, livingWithIds, namePrefix) {
        const statusKeysForChildren = ['divorced', 'annulled', 'separated', 'widowed'];
        return {
            namePrefix: namePrefix || '',
            maritalStatusId: initialStatusId,
            statusKey: initialKey,
            showChildrenQuestion: initialShowQuestion,
            showChildrenDetails: initialShowDetails,
            hasChildrenValue: initialHasChildren === true || initialHasChildren === '1' || initialHasChildren === 1 ? '1' : (initialHasChildren === false || initialHasChildren === '0' || initialHasChildren === 0 ? '0' : ''),
            children: Array.isArray(initialChildren) ? initialChildren.map(function(c) {
                return { id: c.id || null, gender: c.gender || '', age: c.age !== undefined && c.age !== null ? String(c.age) : '', child_living_with_id: c.child_living_with_id ? String(c.child_living_with_id) : '' };
            }) : [],
            statusKeysForChildren: statusKeysForChildren,
            statusIdToKey: @json($maritalStatuses->pluck('key', 'id')->toArray()),

            init() {
                this.syncStatusKeyFromId();
                if (this.children.length === 0 && this.showChildrenDetails) this.addChild();
            },

            syncStatusKeyFromId() {
                var id = this.maritalStatusId;
                this.statusKey = (this.statusIdToKey && this.statusIdToKey[id]) ? this.statusIdToKey[id] : '';
            },

            onMaritalChange() {
                var id = this.maritalStatusId;
                this.statusKey = (this.statusIdToKey && this.statusIdToKey[id]) ? this.statusIdToKey[id] : '';
                this.showChildrenQuestion = this.statusKeysForChildren.indexOf(this.statusKey) !== -1;
                this.hasChildrenValue = '';
                this.showChildrenDetails = false;
                this.children = [];
            },

            onStatusChange(detail) {
                if (detail && detail.maritalStatusId !== undefined) {
                    this.maritalStatusId = detail.maritalStatusId;
                    this.syncStatusKeyFromId();
                    this.showChildrenQuestion = this.statusKeysForChildren.indexOf(this.statusKey) !== -1;
                    this.hasChildrenValue = '';
                    this.showChildrenDetails = false;
                    this.children = [];
                }
            },

            onHasChildrenChange() {
                var yes = this.hasChildrenValue === '1' || this.hasChildrenValue === 1;
                this.showChildrenDetails = yes;
                if (!yes) this.children = [];
                else if (this.children.length === 0) this.addChild();
            },

            addChild() {
                this.children.push({ id: null, gender: '', age: '', child_living_with_id: '' });
            },

            removeChild(index) {
                this.children.splice(index, 1);
            }
        };
    }
    window.maritalEngineState = maritalEngineState;
});
</script>
