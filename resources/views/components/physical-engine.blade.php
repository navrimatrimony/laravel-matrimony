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

    $spectaclesOptions = trans('components.physical.spectacles_options');
    $physicalConditionOptions = trans('components.physical.condition_options');

    $n = fn ($base) => $hasPrefix ? $namePrefix . '[' . $base . ']' : $base;
    if ($hasPrefix) {
        $valHeight = $values['height_cm'] ?? null;
        $valComplexion = $values['complexion_id'] ?? null;
        $valBlood = $values['blood_group_id'] ?? null;
        $valBuild = $values['physical_build_id'] ?? null;
        $valSpectacles = $values['spectacles_lens'] ?? null;
        $valCondition = $values['physical_condition'] ?? null;
    } else {
        $valHeight = old('height_cm', $profile->height_cm ?? null);
        $valComplexion = old('complexion_id', $profile->complexion_id ?? null);
        $valBlood = old('blood_group_id', $profile->blood_group_id ?? null);
        $valBuild = old('physical_build_id', $profile->physical_build_id ?? null);
        $valSpectacles = old('spectacles_lens', $profile->spectacles_lens ?? null);
        $valCondition = old('physical_condition', $profile->physical_condition ?? null);
    }
@endphp

<div class="physical-engine space-y-6 border-2 border-rose-500 dark:border-rose-400 rounded-lg p-4">
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
                        <option value="{{ $c->id }}" {{ (string)$valComplexion === (string)$c->id ? 'selected' : '' }}>{{ $c->label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('components.physical.blood_group') }}</label>
                <select name="{{ $n('blood_group_id') }}" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                    <option value="">{{ __('components.physical.select_blood_group') }}</option>
                    @foreach($bloodGroups as $bg)
                        <option value="{{ $bg->id }}" {{ (string)$valBlood === (string)$bg->id ? 'selected' : '' }}>{{ $bg->label }}</option>
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
                        <option value="{{ $pb->id }}" {{ (string)$valBuild === (string)$pb->id ? 'selected' : '' }}>{{ $pb->label }}</option>
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
</div>
