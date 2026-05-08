@extends('layouts.admin')

@section('content')
@php
    $s = is_array($di['summary'] ?? null) ? $di['summary'] : [];
    $missing = is_array($di['registry_vs_profile']['missing_columns'] ?? null) ? $di['registry_vs_profile']['missing_columns'] : [];
    $semantic = is_array($di['semantic_groups_triggered'] ?? null) ? $di['semantic_groups_triggered'] : [];
    $impl = is_array($di['implementation'] ?? null) ? $di['implementation'] : [];
    $roadmap = is_array($di['roadmap'] ?? null) ? $di['roadmap'] : [];
    $notYet = is_array($impl['not_in_scope_yet'] ?? null) ? $impl['not_in_scope_yet'] : [];
    $health = isset($s['health_score']) && $s['health_score'] !== null ? (int) $s['health_score'] : null;
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <a href="{{ route('admin.data-engine.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">&larr; Back to data engine</a>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">Data integrity (PHASE 1)</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Structural checks from the Python engine: <strong>field_registry</strong> CORE keys vs <strong><code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">matrimony_profiles</code></strong> columns (matches your <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">MutationService</code> contract), plus configurable semantic duplicate groups.
                Wizard / public-profile parity is not automated yet — see roadmap below.
            </p>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            <form method="post" action="{{ route('admin.data-engine.analyze') }}" class="inline js-data-engine-run-form">
                @csrf
                <button type="submit" @disabled(($engineRunning ?? false) || ! ($enginePowered ?? true))
                    class="js-data-engine-submit inline-flex items-center rounded-md bg-slate-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    Run analyze (refresh section)
                </button>
            </form>
        </div>
    </div>

    @if ($latestRun)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 mb-6">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                Report source: <strong>Run #{{ $latestRun->id }}</strong> · {{ $latestRun->mode }} · {{ $latestRun->status }}
                <span class="mx-1">|</span>
                <a href="{{ route('admin.data-engine.show', $latestRun) }}" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 font-semibold">Open run JSON</a>
            </p>
            @if ($latestRun->status !== 'completed' || ! $latestRun->report_path)
                <p class="text-xs text-amber-700 dark:text-amber-300 mt-2">Complete an analyze run to populate <code class="text-xs">data_integrity</code> in the report.</p>
            @endif
        </div>
    @endif

    @if (! empty($engineRunning))
        <div class="mb-4 rounded-md bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-300 dark:border-yellow-800 px-4 py-3 text-sm text-yellow-900 dark:text-yellow-100">
            A run is <strong>in progress</strong>; analyze button is disabled until it finishes.
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">Health score (schema)</p>
            <p class="mt-1 text-2xl font-bold">{{ $health !== null ? $health.'%' : '—' }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">CORE registry keys</p>
            <p class="mt-1 text-2xl font-bold">{{ number_format((int) ($s['registry_core_keys'] ?? 0)) }}</p>
        </div>
        <div class="rounded-xl border border-red-200 dark:border-red-900/40 bg-red-50 dark:bg-red-950/20 p-4">
            <p class="text-xs uppercase tracking-wide text-red-800 dark:text-red-200">Missing profile columns</p>
            <p class="mt-1 text-2xl font-bold text-red-800 dark:text-red-200">{{ number_format((int) ($s['missing_columns_for_core_registry'] ?? count($missing))) }}</p>
        </div>
        <div class="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50 dark:bg-amber-950/20 p-4">
            <p class="text-xs uppercase tracking-wide text-amber-800 dark:text-amber-200">Semantic group warnings</p>
            <p class="mt-1 text-2xl font-bold text-amber-800 dark:text-amber-200">{{ number_format((int) ($s['semantic_duplicate_group_warnings'] ?? count($semantic))) }}</p>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">CORE registry → <code class="text-xs">matrimony_profiles</code> column</h2>
            <p class="text-xs text-gray-500 mt-1">Each CORE <code class="text-xs">field_key</code> should exist as a column — otherwise manual wizard / intake cannot persist that field on the profile row.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">field_key</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Severity</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($missing as $row)
                        @if (is_array($row))
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row['field_key'] ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs font-semibold text-red-700 dark:text-red-300">{{ $row['severity'] ?? 'high' }}</td>
                                <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-300">{{ $row['detail'] ?? '' }}</td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-gray-500">No missing columns detected — registry aligns with profile table (or run analyze after upgrading).</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Semantic duplicate groups (config-driven)</h2>
            <p class="text-xs text-gray-500 mt-1">Defined in <code class="text-xs">python-data-engine/config/data_integrity.json</code>. Multiple columns from one group existing on the same table increases dual-source risk.</p>
        </div>
        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
            @forelse ($semantic as $row)
                @if (is_array($row))
                    <li class="px-4 py-3 text-sm">
                        <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $row['label'] ?? ($row['group_id'] ?? 'Group') }}</p>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mt-1 font-mono">{{ implode(', ', $row['columns_defined'] ?? []) }}</p>
                        @if (! empty($row['note']))
                            <p class="text-xs text-gray-500 mt-1">{{ $row['note'] }}</p>
                        @endif
                    </li>
                @endif
            @empty
                <li class="px-4 py-6 text-center text-gray-500 text-sm">No semantic groups triggered (or none configured).</li>
            @endforelse
        </ul>
    </div>

    @if ($notYet !== [])
        <div class="rounded-xl border border-indigo-200 dark:border-indigo-900/50 bg-indigo-50/50 dark:bg-indigo-950/20 px-4 py-4 mb-6">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Not in PHASE 1 (engine report lists scope)</h2>
            <ul class="mt-2 list-disc list-inside text-sm text-gray-700 dark:text-gray-300 space-y-1">
                @foreach ($notYet as $line)
                    @if (is_string($line) && $line !== '')
                        <li>{{ $line }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    @if ($roadmap !== [])
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-4 mb-6">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Roadmap (config + report)</h2>
            <ul class="mt-2 space-y-3 text-sm text-gray-700 dark:text-gray-300">
                @foreach ($roadmap as $item)
                    @if (is_array($item))
                        <li>
                            <span class="font-semibold">Phase {{ $item['phase'] ?? '?' }}:</span> {{ $item['title'] ?? '' }}
                            @if (! empty($item['detail']))
                                <p class="text-xs text-gray-500 mt-1">{{ $item['detail'] }}</p>
                            @endif
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    <div id="data-engine-loading" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/25 dark:bg-black/50" aria-live="polite">
        <div class="rounded-xl bg-white dark:bg-gray-800 px-6 py-5 shadow-xl border border-gray-200 dark:border-gray-700 flex items-center gap-4">
            <svg class="animate-spin h-9 w-9 text-indigo-600 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Running analyze…</p>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('data-engine-loading');
    document.querySelectorAll('.js-data-engine-run-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            if (overlay) { overlay.classList.remove('hidden'); }
            form.querySelectorAll('.js-data-engine-submit').forEach(function (btn) { btn.disabled = true; });
        });
    });
})();
</script>
@endsection
