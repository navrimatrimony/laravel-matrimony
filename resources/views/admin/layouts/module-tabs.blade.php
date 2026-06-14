@php
    $module = $module ?? null;
    $tabs = is_array($module['tabs'] ?? null) ? $module['tabs'] : [];
    $tabCount = count($tabs);
@endphp

@if ($module && count($tabs) > 0)
    <section class="mb-6 border-b border-gray-200 pb-0" aria-labelledby="admin-module-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $module['section_label'] }}</p>
                    <span class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ $tabCount }} tools</span>
                </div>
                <h1 id="admin-module-heading" class="mt-1 text-2xl font-semibold text-gray-950">{{ $module['active_tab_label'] ?? $module['section_label'] }}</h1>
                @if (! empty($module['section_description']))
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-gray-600">{{ $module['section_description'] }}</p>
                @endif
            </div>
            <button
                type="button"
                class="hidden min-w-[18rem] shrink-0 items-center justify-between gap-3 rounded-md border border-gray-200 bg-white px-3 py-2 text-left text-sm text-gray-600 shadow-sm hover:bg-gray-50 hover:text-gray-950 lg:inline-flex"
                aria-label="Search admin tools"
                title="Search admin tools"
                data-admin-command-toggle
                @click="openAdminCommandPalette()"
            >
                <span class="inline-flex min-w-0 items-center gap-2">
                    <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" /></svg>
                    <span class="truncate">Search admin tools</span>
                </span>
                <kbd class="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-[10px] font-semibold text-gray-500">Ctrl K</kbd>
            </button>
        </div>
        <nav class="-mb-px mt-4 flex max-w-full gap-1 overflow-x-auto" aria-label="Admin module tabs">
            @foreach ($tabs as $tab)
                @php
                    $active = (bool) ($tab['active'] ?? false);
                @endphp
                <a
                    href="{{ $tab['href'] }}"
                    @if ($active) aria-current="page" @endif
                    class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition {{ $active ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-gray-950' }}"
                >
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>
    </section>
@endif
