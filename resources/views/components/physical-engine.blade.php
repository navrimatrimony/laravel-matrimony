@props([
    'profile' => null,
    'values' => [],
    'namePrefix' => '',
])
@php
    $hasPrefix = $namePrefix !== '';
    $complexions = $complexions ?? \App\Models\MasterComplexion::where('is_active', true)->orderBy('id')->get();
    $bloodGroups = $bloodGroups ?? \App\Models\MasterBloodGroup::where('is_active', true)->orderBy('id')->get();
    $physicalBuilds = $physicalBuilds ?? \App\Models\MasterPhysicalBuild::where('is_active', true)->orderBy('id')->get();
    $diets = $diets ?? \App\Models\MasterDiet::where('is_active', true)->orderBy('sort_order')->get();
    $smokingStatuses = $smokingStatuses ?? \App\Models\MasterSmokingStatus::where('is_active', true)->orderBy('sort_order')->get();
    $drinkingStatuses = $drinkingStatuses ?? \App\Models\MasterDrinkingStatus::where('is_active', true)->orderBy('sort_order')->get();

    $spectaclesOptions = trans('components.physical.spectacles_options');
    $physicalConditionOptions = trans('components.physical.condition_options');

    $optionLabel = function ($row, string $field) {
        $key = $row->key ?? null;
        $dbLabel = $row->label ?? '';
        if ($key) {
            $tKey = 'components.options.' . $field . '.' . $key;
            $t = __($tKey);
            if ($t !== $tKey) return $t;
        }
        return $dbLabel;
    };
    $bloodGroupLabel = function ($row) {
        $key = $row->key ?? null;
        if ($key) {
            $t = \Illuminate\Support\Facades\Lang::get('components.options.blood_group.' . $key, [], 'en');
            if ($t !== 'components.options.blood_group.' . $key) return $t;
        }
        return $row->label ?? '';
    };

    $n = fn ($base) => $hasPrefix ? $namePrefix . '[' . $base . ']' : $base;
    if ($hasPrefix) {
        $valHeight = $values['height_cm'] ?? null;
        $valComplexion = $values['complexion_id'] ?? null;
        $valBlood = $values['blood_group_id'] ?? null;
        $valBuild = $values['physical_build_id'] ?? null;
        $valSpectacles = $values['spectacles_lens'] ?? null;
        $valCondition = $values['physical_condition'] ?? null;
        $valDiet = $values['diet_id'] ?? null;
        $valSmoking = $values['smoking_status_id'] ?? null;
        $valDrinking = $values['drinking_status_id'] ?? null;
    } else {
        $valHeight = old('height_cm', $profile->height_cm ?? null);
        $valComplexion = old('complexion_id', $profile->complexion_id ?? null);
        $valBlood = old('blood_group_id', $profile->blood_group_id ?? null);
        $valBuild = old('physical_build_id', $profile->physical_build_id ?? null);
        $valSpectacles = old('spectacles_lens', $profile->spectacles_lens ?? null);
        $valCondition = old('physical_condition', $profile->physical_condition ?? null);
        $valDiet = old('diet_id', $profile->diet_id ?? null);
        $valSmoking = old('smoking_status_id', $profile->smoking_status_id ?? null);
        $valDrinking = old('drinking_status_id', $profile->drinking_status_id ?? null);
    }
@endphp

<div class="physical-engine space-y-6 border border-gray-200 dark:border-gray-600 rounded-lg p-4">
    {{-- A) Core Physical Details — 3 fields on one line (md+) --}}
    <div>
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">{{ __('components.physical.core_physical_details') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <x-profile.height-picker
                    :value="$valHeight"
                    :namePrefix="$namePrefix"
                    :label="__('components.physical.height')"
                />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.physical.complexion') }}</label>
                <select name="{{ $n('complexion_id') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">{{ __('components.physical.select_complexion') }}</option>
                    @foreach($complexions as $c)
                        <option value="{{ $c->id }}" {{ (string)$valComplexion === (string)$c->id ? 'selected' : '' }}>{{ $optionLabel($c, 'complexion') }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.physical.blood_group') }}</label>
                <select name="{{ $n('blood_group_id') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">{{ __('components.physical.select_blood_group') }}</option>
                    @foreach($bloodGroups as $bg)
                        <option value="{{ $bg->id }}" {{ (string)$valBlood === (string)$bg->id ? 'selected' : '' }}>{{ $bloodGroupLabel($bg) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- B) Additional Physical Details — 3 fields on one line (md+) --}}
    <div>
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">{{ __('components.physical.additional_physical_details') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.physical.physical_build') }}</label>
                <select name="{{ $n('physical_build_id') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">{{ __('components.physical.select_physical_build') }}</option>
                    @foreach($physicalBuilds as $pb)
                        <option value="{{ $pb->id }}" {{ (string)$valBuild === (string)$pb->id ? 'selected' : '' }}>{{ $optionLabel($pb, 'physical_build') }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.physical.spectacles_lens') }}</label>
                <select name="{{ $n('spectacles_lens') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">{{ __('common.select') }}</option>
                    @foreach($spectaclesOptions as $key => $label)
                        <option value="{{ $key }}" {{ (string)$valSpectacles === (string)$key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.physical.physical_condition') }}</label>
                <select name="{{ $n('physical_condition') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">{{ __('common.select') }}</option>
                    @foreach($physicalConditionOptions as $key => $label)
                        <option value="{{ $key }}" {{ (string)$valCondition === (string)$key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- C) Lifestyle — Diet, Smoking, Drinking --}}
    <div>
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">{{ __('components.physical.lifestyle') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.physical.diet') }}</label>
                <select name="{{ $n('diet_id') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">{{ __('common.select_placeholder') }}</option>
                    @foreach($diets as $d)
                        <option value="{{ $d->id }}" {{ (string)$valDiet === (string)$d->id ? 'selected' : '' }}>{{ $optionLabel($d, 'diet') }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.physical.smoking') }}</label>
                <select name="{{ $n('smoking_status_id') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">{{ __('common.select_placeholder') }}</option>
                    @foreach($smokingStatuses as $s)
                        <option value="{{ $s->id }}" {{ (string)$valSmoking === (string)$s->id ? 'selected' : '' }}>{{ $optionLabel($s, 'smoking') }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.physical.drinking') }}</label>
                <select name="{{ $n('drinking_status_id') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">{{ __('common.select_placeholder') }}</option>
                    @foreach($drinkingStatuses as $d)
                        <option value="{{ $d->id }}" {{ (string)$valDrinking === (string)$d->id ? 'selected' : '' }}>{{ $optionLabel($d, 'drinking') }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>
