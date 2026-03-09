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
    $showChildrenQuestion = in_array($currentKey, ['divorced', 'separated', 'widowed'], true);
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
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Marital status</h2>
        {{-- Step 1: Marital status (radios) — bold, spaced, card-style options --}}
        <div>
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Marital status <span class="text-red-500">*</span></p>
            <div class="flex flex-nowrap gap-2 w-full overflow-x-auto items-stretch">
                @foreach($maritalStatuses as $s)
                    <label class="inline-flex items-center justify-center cursor-pointer rounded-lg border-2 pl-3 pr-4 py-2.5 transition-all duration-150 flex-1 min-w-0 min-h-[42px]
                        hover:border-gray-300 dark:hover:border-gray-500
                        focus-within:ring-2 focus-within:ring-red-500 focus-within:ring-offset-1"
                        :class="maritalStatusId == '{{ $s->id }}' ? 'border-red-600 bg-red-500 dark:bg-red-600 dark:border-red-500 shadow-md' : 'border-gray-200 dark:border-gray-600 bg-gray-50/50 dark:bg-gray-700/30'">
                        <input type="radio" name="{{ $coreName }}marital_status_id{{ $coreNameSuffix }}" value="{{ $s->id }}"
                               {{ (string) $currentStatusId === (string) $s->id ? 'checked' : '' }}
                               class="rounded-full border-2 border-gray-400 flex-shrink-0 w-3.5 h-3.5 accent-white"
                               x-model="maritalStatusId"
                               @change="onMaritalChange()">
                        <span class="ml-2 text-xs font-semibold break-words"
                              :class="maritalStatusId == '{{ $s->id }}' ? 'text-white' : 'text-gray-800 dark:text-gray-200'">{{ $s->label }}</span>
                    </label>
                @endforeach
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
    <div class="marital-details-block" x-show="statusKey === 'divorced' || statusKey === 'separated' || statusKey === 'widowed'" x-cloak style="display: none;">
        {{-- Heading line: Status details + Children (required) — visible section heading --}}
        <div class="flex flex-wrap items-center gap-4 pb-2 mb-2 border-b border-gray-200 dark:border-gray-600">
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">Status details (optional)</h3>
            <span class="text-gray-400 dark:text-gray-500" aria-hidden="true">|</span>
            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">Children <span class="text-red-500">*</span></h3>
        </div>
        <div class="flex flex-wrap sm:flex-nowrap gap-2 sm:gap-3 items-end">
            <div class="flex-shrink-0" style="min-width: 6rem;">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">Marriage year</label>
                <input type="number" name="marriages[0][marriage_year]" min="1901" max="{{ date('Y') }}"
                       value="{{ old('marriages.0.marriage_year', $marriage?->marriage_year ?? '') }}"
                       class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
            </div>
            <div class="flex-shrink-0" x-show="statusKey === 'divorced'" style="min-width: 6rem;">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">Divorce year</label>
                <input type="number" name="{{ $marriagesPrefix }}divorce_year{{ $marriagesSuffix }}" min="1901" max="{{ date('Y') }}"
                       value="{{ old('marriages.0.divorce_year', $marriage?->divorce_year ?? '') }}"
                       class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
            </div>
            <div class="flex-shrink-0" x-show="statusKey === 'separated'" style="min-width: 6.5rem;">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">Separation year</label>
                <input type="number" name="{{ $marriagesPrefix }}separation_year{{ $marriagesSuffix }}" min="1901" max="{{ date('Y') }}"
                       value="{{ old('marriages.0.separation_year', $marriage?->separation_year ?? '') }}"
                       class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
            </div>
            <div class="flex-shrink-0" x-show="statusKey === 'widowed'" style="min-width: 7rem;">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">Spouse death year</label>
                <input type="number" name="{{ $marriagesPrefix }}spouse_death_year{{ $marriagesSuffix }}" min="1901" max="{{ date('Y') }}"
                       value="{{ old('marriages.0.spouse_death_year', $marriage?->spouse_death_year ?? '') }}"
                       class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
            </div>
            <div class="flex-1 min-w-0" x-show="statusKey === 'divorced' || statusKey === 'separated'" style="min-width: 6rem;">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">Legal status</label>
                <select name="{{ $marriagesPrefix }}divorce_status{{ $marriagesSuffix }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]">
                    <option value="">—</option>
                    <option value="pending" {{ old('marriages.0.divorce_status', $marriage?->divorce_status ?? '') == 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="finalized" {{ old('marriages.0.divorce_status', $marriage?->divorce_status ?? '') == 'finalized' ? 'selected' : '' }}>Finalized</option>
                    <option value="mutual" {{ old('marriages.0.divorce_status', $marriage?->divorce_status ?? '') == 'mutual' ? 'selected' : '' }}>Mutual</option>
                    <option value="contested" {{ old('marriages.0.divorce_status', $marriage?->divorce_status ?? '') == 'contested' ? 'selected' : '' }}>Contested</option>
                </select>
            </div>
            {{-- Children Yes/No: heading + toggle. Yes selected = green. --}}
            <div class="flex-shrink-0 flex flex-col">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">Children <span class="text-red-500">*</span></label>
                <input type="hidden" :name="namePrefix ? 'snapshot[core][has_children]' : 'has_children'" :value="hasChildrenValue">
                <div class="inline-flex rounded-full border-2 border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 p-0.5 h-[42px] items-stretch" role="group">
                    <button type="button" @click="hasChildrenValue = '0'; onHasChildrenChange()"
                            class="px-3 py-2 text-sm font-medium rounded-full transition-all min-w-[3.5rem] flex items-center justify-center"
                            :class="hasChildrenValue == '0' ? 'bg-white dark:bg-gray-600 text-gray-800 dark:text-gray-200 shadow' : 'text-gray-500 dark:text-gray-400'">
                        No
                    </button>
                    <button type="button" @click="hasChildrenValue = '1'; onHasChildrenChange()"
                            class="px-3 py-2 text-sm font-medium rounded-full transition-all min-w-[3.5rem] flex items-center justify-center"
                            :class="hasChildrenValue == '1' ? 'bg-green-600 text-white shadow' : 'text-gray-500 dark:text-gray-400'">
                        Yes
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
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Children details</h3>
        <div class="space-y-3" x-ref="childrenContainer">
            <template x-for="(child, index) in children" :key="'child-' + index">
                <div class="flex flex-wrap sm:flex-nowrap gap-2 sm:gap-3 items-end border border-gray-200 dark:border-gray-600 rounded p-2 sm:p-3 bg-gray-50 dark:bg-gray-700/30">
                    <div class="flex-shrink-0" style="min-width: 5.5rem;">
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">Gender</label>
                        <select :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][gender]'" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]" x-model="child.gender">
                            <option value="">Select</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                            <option value="prefer_not_say">Prefer not to say</option>
                        </select>
                    </div>
                    <div class="flex-shrink-0" style="min-width: 4rem;">
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">Age <span class="text-red-500">*</span></label>
                        <input type="number" :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][age]'" min="1" max="120" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]" x-model.number="child.age" placeholder="Age">
                    </div>
                    <div class="flex-1 min-w-0" style="min-width: 6rem;">
                        <label class="block text-xs text-gray-600 dark:text-gray-400 mb-0.5">Living with</label>
                        <select :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][child_living_with_id]'" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2.5 text-sm h-[42px]" x-model="child.child_living_with_id">
                            <option value="">Select</option>
                            @foreach($childLivingWithOptions as $opt)
                                <option value="{{ $opt->id }}">{{ $opt->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-shrink-0 flex items-end">
                        <div class="flex flex-col items-end space-y-1">
                            <input type="hidden" :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][id]'" :value="child.id || ''">
                            <input type="hidden" :name="(namePrefix ? 'snapshot[children][' : 'children[') + index + '][sort_order]'" :value="index">
                            <div class="flex items-center gap-3 h-[42px]">
                                <button type="button"
                                        @click="addChild()"
                                        class="text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">
                                    + Add
                                </button>
                                <button type="button"
                                        @click="removeChild(index)"
                                        class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 whitespace-nowrap">
                                    Remove this entry
                                </button>
                            </div>
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
        const statusKeysForChildren = ['divorced', 'separated', 'widowed'];
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
