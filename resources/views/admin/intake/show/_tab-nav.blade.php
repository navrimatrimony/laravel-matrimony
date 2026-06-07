@php
    $tabs = [
        'review' => ['label' => 'Parse review', 'hint' => 'Find mapping mistakes'],
        'source' => ['label' => 'Source & file', 'hint' => 'Image + parse input text'],
        'actions' => ['label' => 'Actions & apply', 'hint' => 'Re-parse, apply, governance'],
        'technical' => ['label' => 'Technical', 'hint' => 'Diagnostics & raw JSON'],
    ];
@endphp
<nav class="flex flex-wrap gap-2 border-b border-gray-200 pb-3 mb-6" aria-label="Intake review sections">
    @foreach ($tabs as $key => $tab)
        <button
            type="button"
            @click="tab = @js($key)"
            :class="tab === @js($key)
                ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm'
                : 'bg-white text-gray-700 border-gray-300 hover:border-indigo-300 hover:text-indigo-700'"
            class="inline-flex flex-col items-start rounded-lg border px-3 py-2 text-left transition-colors min-w-[8.5rem]"
        >
            <span class="text-sm font-semibold">{{ $tab['label'] }}</span>
            <span class="text-[10px] opacity-80 leading-tight mt-0.5">{{ $tab['hint'] }}</span>
        </button>
    @endforeach
</nav>
