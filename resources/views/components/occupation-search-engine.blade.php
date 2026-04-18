@props([
    'profile',
    'namePrefix' => '',
    'formSelector' => null,
    'readOnly' => false,
    'occupationKeyStem' => null,
    'showLabel' => true,
    'compact' => false,
    /** Match plain wizard inputs (parent/sibling rows): single control height, no nested “card” for workplace. */
    'formFieldStyle' => false,
])
@php
    use App\Models\OccupationCustom;
    use App\Models\OccupationMaster;
    use App\Models\Profession;
    use Illuminate\Support\Facades\Schema;

    $hasOcc = Schema::hasColumn('matrimony_profiles', 'occupation_master_id');
    $occupationKeyStem = $occupationKeyStem ?? null;
    $midKey = $occupationKeyStem ? $occupationKeyStem.'_master_id' : 'occupation_master_id';
    $cidKey = $occupationKeyStem ? $occupationKeyStem.'_custom_id' : 'occupation_custom_id';

    $profile = $profile ?? new \stdClass();
    if ($profile instanceof \App\Models\MatrimonyProfile) {
        $profile->loadMissing(['occupationMaster', 'occupationCustom', 'profession']);
        if ($occupationKeyStem === 'father_occupation') {
            $profile->loadMissing(['fatherOccupationMaster', 'fatherOccupationCustom']);
        } elseif ($occupationKeyStem === 'mother_occupation') {
            $profile->loadMissing(['motherOccupationMaster', 'motherOccupationCustom']);
        }
    }

    $suffix = substr(bin2hex(random_bytes(6)), 0, 10);
    $n = fn ($b) => $namePrefix !== '' ? $namePrefix.'['.$b.']' : $b;
    $oldKey = fn ($key) => $namePrefix !== ''
        ? str_replace(']', '', str_replace('[', '.', $namePrefix.'['.$key.']'))
        : $key;

    $mid = old($oldKey($midKey), data_get($profile, $midKey));
    $cid = old($oldKey($cidKey), data_get($profile, $cidKey));

    if (! $mid && ! $cid && ($profile->profession_id ?? null) && $hasOcc && ! $occupationKeyStem && $profile instanceof \App\Models\MatrimonyProfile) {
        $prof = Profession::find($profile->profession_id);
        if ($prof) {
            $mid = OccupationMaster::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($prof->name))])
                ->value('id');
        }
    }

    $initialSelection = null;
    if ($mid) {
        $label = OccupationMaster::whereKey((int) $mid)->value('name') ?? '';
        if ($profile instanceof \App\Models\MatrimonyProfile) {
            if ($occupationKeyStem === 'father_occupation') {
                $label = optional($profile->fatherOccupationMaster)->name ?: $label;
            } elseif ($occupationKeyStem === 'mother_occupation') {
                $label = optional($profile->motherOccupationMaster)->name ?: $label;
            } elseif (! $occupationKeyStem) {
                $label = optional($profile->occupationMaster)->name ?: $label;
            }
        }
        $initialSelection = [
            'type' => 'master',
            'id' => (int) $mid,
            'label' => $label,
        ];
    } elseif ($cid) {
        $customLabel = OccupationCustom::whereKey((int) $cid)->value('raw_name') ?? '';
        if ($profile instanceof \App\Models\MatrimonyProfile) {
            if ($occupationKeyStem === 'father_occupation') {
                $customLabel = optional($profile->fatherOccupationCustom)->raw_name ?: $customLabel;
            } elseif ($occupationKeyStem === 'mother_occupation') {
                $customLabel = optional($profile->motherOccupationCustom)->raw_name ?: $customLabel;
            } elseif (! $occupationKeyStem) {
                $customLabel = optional($profile->occupationCustom)->raw_name ?: $customLabel;
            }
        }
        $initialSelection = [
            'type' => 'custom',
            'id' => (int) $cid,
            'label' => $customLabel,
        ];
    }

    $occConfig = [
        'selectSelector' => '#occupation-ts-'.$suffix,
        'hiddenMasterSelector' => '#occ-master-'.$suffix,
        'hiddenCustomSelector' => '#occ-custom-'.$suffix,
        'categoryMountSelector' => '#occupation-category-'.$suffix,
        'compactCategoryMount' => $formFieldStyle,
        'searchUrl' => route('api.occupations.search'),
        'createUrl' => route('matrimony.api.occupations.create'),
        'categoryBaseUrl' => url('/api/occupations/category'),
        'minQueryLength' => 2,
        'csrfToken' => csrf_token(),
        'formSelector' => $formSelector,
        'initialSelection' => $initialSelection,
        'labels' => [
            /** Shown inside Tom Select when empty — matches plain text inputs. */
            'inputPlaceholder' => __('components.relation.occupation_placeholder'),
            'categoryPrefix' => __('components.education.occupation_category_label'),
            'change' => __('change'),
            'addPrefix' => "+ Add '",
            'addSuffix' => "'",
            'customPending' => __('Custom occupation (pending review)'),
            'typeMore' => __('Type at least two characters to search.'),
            'createHint' => __('components.education.no_match_press_enter'),
            'createCta' => __('common.add'),
            'customWorkplaceIcon' => '✨',
        ],
    ];

    $selectSurface = $formFieldStyle
        ? 'w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2 text-sm h-10 box-border'
        : ($compact
            ? 'w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-3 py-2 text-sm min-h-[40px]'
            : 'w-full rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-700 px-4 py-3 text-base min-h-[48px]');
    $catMinH = $formFieldStyle ? '' : ($compact ? 'min-h-[2rem]' : 'min-h-[2.5rem]');
    $rootClasses = 'occupation-engine-root'.($formFieldStyle ? ' occupation-engine--form-field' : '');
@endphp

@if (! $hasOcc)
    <p class="text-sm text-amber-800 dark:text-amber-200">{{ __('onboarding.run_migrations_education') }}</p>
@elseif ($readOnly)
    @php
        $display = '—';
        if ($mid) {
            $display = OccupationMaster::whereKey((int) $mid)->value('name') ?? '—';
            if ($profile instanceof \App\Models\MatrimonyProfile) {
                if ($occupationKeyStem === 'father_occupation') {
                    $display = optional($profile->fatherOccupationMaster)->name ?? $display;
                } elseif ($occupationKeyStem === 'mother_occupation') {
                    $display = optional($profile->motherOccupationMaster)->name ?? $display;
                } elseif (! $occupationKeyStem) {
                    $display = optional($profile->occupationMaster)->name ?? $display;
                }
            }
        } elseif ($cid) {
            $display = OccupationCustom::whereKey((int) $cid)->value('raw_name') ?? '—';
            if ($profile instanceof \App\Models\MatrimonyProfile) {
                if ($occupationKeyStem === 'father_occupation') {
                    $display = optional($profile->fatherOccupationCustom)->raw_name ?? $display;
                } elseif ($occupationKeyStem === 'mother_occupation') {
                    $display = optional($profile->motherOccupationCustom)->raw_name ?? $display;
                } elseif (! $occupationKeyStem) {
                    $display = optional($profile->occupationCustom)->raw_name ?? $display;
                }
            }
        } elseif (($profile->profession_id ?? null) && ! $occupationKeyStem && $profile instanceof \App\Models\MatrimonyProfile) {
            $display = Profession::find($profile->profession_id)?->name ?? '—';
        }
    @endphp
    <div class="space-y-1">
        @if ($showLabel)
            <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('components.education.occupation_select_label') }}</span>
        @endif
        <p class="text-gray-900 dark:text-gray-100">{{ $display }}</p>
    </div>
@else
    @once
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.default.min.css" crossorigin="anonymous">
        <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js" crossorigin="anonymous"></script>
        <style>
            .ts-dropdown-content .occupation-option-create { cursor: pointer; }
            .ts-dropdown-content .option.active .occupation-option-create {
                outline: 2px solid rgb(16 185 129 / 0.45);
                outline-offset: -2px;
                border-radius: 0.375rem;
            }
            .dark .ts-dropdown-content .option.active .occupation-option-create {
                outline-color: rgb(52 211 153 / 0.5);
            }
            .occupation-workplace-strip {
                display: inline-flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.5rem 0.75rem;
                margin-top: 0.25rem;
                padding: 0.5rem 0.75rem;
                border-radius: 0.5rem;
                border: 1px solid rgb(209 250 229 / 0.9);
                background: linear-gradient(to right, rgb(236 253 245 / 0.85), rgb(255 255 255 / 0.95));
            }
            .dark .occupation-workplace-strip {
                border-color: rgb(6 95 70 / 0.45);
                background: linear-gradient(to right, rgb(6 78 59 / 0.35), rgb(17 24 39 / 0.9));
            }
            /* Form-field: Tom Select shell matches plain h-10 inputs (40px, centered content) */
            .occupation-engine--form-field .ts-wrapper {
                margin-bottom: 0;
                width: 100%;
                vertical-align: middle;
            }
            .occupation-engine--form-field .ts-wrapper.single .ts-control {
                height: 40px !important;
                min-height: 40px !important;
                max-height: 40px !important;
                display: flex !important;
                align-items: center !important;
                border-radius: 0.25rem !important;
                border: 1px solid rgb(209 213 219) !important;
                padding-top: 0 !important;
                padding-bottom: 0 !important;
                box-sizing: border-box;
                background-image: none !important;
                background-color: #ffffff !important;
                box-shadow: none !important;
            }
            .occupation-engine--form-field .ts-wrapper.single .ts-control input {
                height: 100% !important;
                line-height: 40px !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .occupation-engine--form-field .ts-wrapper.single .ts-control > div {
                display: flex !important;
                align-items: center !important;
                height: 100% !important;
            }
            .dark .occupation-engine--form-field .ts-wrapper.single .ts-control {
                border-color: rgb(75 85 99) !important;
                background-color: rgb(55 65 81) !important;
            }
            .occupation-engine--form-field .ts-wrapper.single.focus .ts-control,
            .occupation-engine--form-field .ts-wrapper.single.dropdown-active .ts-control {
                background-image: none !important;
                box-shadow: none !important;
            }
            .occupation-engine--form-field .ts-wrapper.single.input-active .ts-control {
                background-image: none !important;
                background-color: #ffffff !important;
            }
            .dark .occupation-engine--form-field .ts-wrapper.single.input-active .ts-control {
                background-color: rgb(55 65 81) !important;
            }
            .occupation-engine--form-field [data-occupation-category-mount] .occupation-category-current {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                max-width: min(10rem, 28vw);
            }
            .occupation-engine--form-field [data-occupation-category-mount] .occupation-category-toggle {
                font-size: 10px;
                padding: 0;
                margin: 0 0 0 0.125rem;
                background: transparent !important;
                border: 0 !important;
                color: rgb(59 130 246);
                cursor: pointer;
                text-decoration: underline;
                flex-shrink: 0;
            }
            .dark .occupation-engine--form-field [data-occupation-category-mount] .occupation-category-toggle {
                color: rgb(147 197 253);
            }
            /* Dropdown stacks above repeats / grids */
            .occupation-engine-root .ts-wrapper .ts-dropdown {
                z-index: 10060;
            }
            .occupation-engine--form-field [data-occupation-category-mount]:empty {
                display: none !important;
            }
            /* Absolute category rail: clicks only on toggle / inline category control */
            .occupation-engine--form-field [data-occupation-category-mount] .occupation-category-toggle,
            .occupation-engine--form-field [data-occupation-category-mount] select.occupation-category-inline {
                pointer-events: auto;
            }
        </style>
    @endonce

    <div
        class="{{ $rootClasses }}"
        data-init-occupation-engine
        data-config='@json($occConfig)'
    >
        @if ($showLabel)
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1" for="occupation-ts-{{ $suffix }}">{{ __('components.education.occupation_select_label') }}</label>
        @endif
        <input type="hidden" name="{{ $n($midKey) }}" id="occ-master-{{ $suffix }}" value="{{ $mid ?: '' }}">
        <input type="hidden" name="{{ $n($cidKey) }}" id="occ-custom-{{ $suffix }}" value="{{ $cid ?: '' }}">
        @if($formFieldStyle)
        <div class="relative w-full min-w-0 overflow-visible">
            <select id="occupation-ts-{{ $suffix }}" data-searchable-single class="{{ $selectSurface }}" autocomplete="off" @if(! $showLabel) aria-label="{{ __('components.education.occupation_select_label') }}" @endif></select>
            <div id="occupation-category-{{ $suffix }}" data-occupation-category-mount class="absolute left-0 top-full z-10 mt-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400 p-0 m-0 border-0 bg-transparent pointer-events-none"></div>
        </div>
        @else
        <select id="occupation-ts-{{ $suffix }}" data-searchable-single class="{{ $selectSurface }}" autocomplete="off" @if(! $showLabel) aria-label="{{ __('components.education.occupation_select_label') }}" @endif></select>
        <div id="occupation-category-{{ $suffix }}" data-occupation-category-mount class="mt-2 text-sm text-gray-600 dark:text-gray-400 {{ $catMinH }}"></div>
        @endif
    </div>
    @error($oldKey($midKey))<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
    @error($oldKey($cidKey))<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
@endif
