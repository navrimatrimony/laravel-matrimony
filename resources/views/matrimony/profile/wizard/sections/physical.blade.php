{{-- Physical Engine: border is inside component (all engines use same rose border). --}}
@php $namePrefix = $namePrefix ?? ''; @endphp
<h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2 mb-4">{{ __('wizard.physical_details') }}</h2>
<x-physical-engine :profile="$profile" :values="$coreData ?? []" :namePrefix="$namePrefix" />
