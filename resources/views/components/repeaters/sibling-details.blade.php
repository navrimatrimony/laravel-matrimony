{{-- Sibling Details — relation-details engine with Yes/No toggle inside the block. --}}
@props(['siblings' => collect(), 'hasSiblings' => null, 'namePrefix' => 'siblings'])
@php
    $siblingRelationOptions = \App\Models\MasterRelative::optionsForGroup('sibling');
    $showForm = $hasSiblings !== false;
@endphp
<x-repeaters.relation-details
    namePrefix="{{ $namePrefix }}"
    :relationOptions="$siblingRelationOptions"
    :showMarried="true"
    :items="$siblings"
    addButtonLabel="{{ __('wizard.add_sibling') }}"
    removeButtonLabel="{{ __('wizard.remove_sibling') }}"
    contentShowBinding="showSiblingForm"
    :contentShowInitial="$showForm"
>
    <x-slot:header>
        <div class="siblings-toggle-wrap" x-init="$watch('showSiblingForm', v => { const i = $el.querySelector('input[name=has_siblings]'); if(i) i.value = v ? '1' : '0'; })">
            <input type="hidden" name="has_siblings" value="{{ $showForm ? '1' : '0' }}">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('wizard.siblings_question') }}</p>
            <div class="inline-flex p-1 rounded-full bg-gray-200 dark:bg-gray-600 shadow-inner" role="group" aria-label="{{ __('wizard.siblings_question') }}">
                <button type="button"
                    @click="showSiblingForm = true"
                    class="px-5 py-2.5 rounded-full font-semibold text-sm transition-all duration-200"
                    :class="showSiblingForm ? 'shadow text-white' : 'bg-transparent text-gray-600 dark:text-gray-400 hover:bg-gray-300 dark:hover:bg-gray-500'"
                    :style="showSiblingForm ? 'background-color: rgb(34 197 94)' : ''"
                >{{ __('wizard.yes') }}</button>
                <button type="button"
                    @click="showSiblingForm = false"
                    :class="!showSiblingForm ? 'bg-rose-500 text-white shadow' : 'bg-transparent text-gray-600 dark:text-gray-400 hover:bg-gray-300 dark:hover:bg-gray-500'"
                    class="px-5 py-2.5 rounded-full font-semibold text-sm transition-all duration-200"
                >{{ __('wizard.no') }}</button>
            </div>
        </div>
    </x-slot:header>
</x-repeaters.relation-details>
