@extends('layouts.admin')

@section('content')
@php
    $mrSummary = is_array($mr['summary'] ?? null) ? $mr['summary'] : [];
    $mrColumns = is_array($mr['columns'] ?? null) ? $mr['columns'] : [];
    $mrFix = is_array($mr['fix'] ?? null) ? $mr['fix'] : [];
    $updatedByColumn = is_array($mrFix['updated_by_column'] ?? null) ? $mrFix['updated_by_column'] : [];
    $skipped = is_array($mrFix['skipped'] ?? null) ? $mrFix['skipped'] : [];
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <a href="{{ route('admin.data-engine.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">&larr; Back to data engine</a>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">Marathi `_mr` columns tab</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Counts below are <strong>live from your database</strong> (refreshed every time you open this page). They match every column ending with <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">_mr</code> that also has a paired base column (e.g. <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">name</code> → <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">name_mr</code>).
                Use <strong>Run analyze</strong> to regenerate the full JSON report on the main data engine screen (large DBs may take several minutes).
            </p>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            <form method="post" action="{{ route('admin.data-engine.analyze') }}" class="inline js-data-engine-run-form">
                @csrf
                <button type="submit" @disabled(($engineRunning ?? false) || ! ($enginePowered ?? true))
                    class="js-data-engine-submit inline-flex items-center rounded-md bg-slate-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed">
                    Run analyze
                </button>
            </form>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-md bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-800 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    @if (! empty($engineRunning))
        <div class="mb-4 rounded-md bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-300 dark:border-yellow-800 px-4 py-3 text-sm text-yellow-900 dark:text-yellow-100">
            A run is currently <strong>running</strong>. Buttons are disabled until it finishes. If this stays stuck, wait past the configured timeout or restart the queue worker / PHP process — stale runs are cleared automatically after the timeout.
        </div>
    @endif

    @if ($latestRun)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 mb-6">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                Latest run: <strong>Run #{{ $latestRun->id }}</strong> · {{ $latestRun->mode }} · {{ $latestRun->status }}
                <span class="mx-1">|</span>
                <a href="{{ route('admin.data-engine.show', $latestRun) }}" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 font-semibold">Open run details</a>
            </p>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">`_mr` columns found</p>
            <p class="mt-1 text-2xl font-bold">{{ number_format((int) ($mrSummary['mr_columns_found'] ?? 0)) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <p class="text-xs uppercase tracking-wide text-gray-500">Expected cells (has base text)</p>
            <p class="mt-1 text-2xl font-bold">{{ number_format((int) ($mrSummary['expected_rows_total'] ?? 0)) }}</p>
        </div>
        <div class="rounded-xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/30 p-4">
            <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Filled Marathi</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ number_format((int) ($mrSummary['filled_rows_total'] ?? 0)) }}</p>
        </div>
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4">
            <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Still pending</p>
            <p class="mt-1 text-2xl font-bold text-amber-700 dark:text-amber-300">{{ number_format((int) ($mrSummary['pending_rows_total'] ?? 0)) }}</p>
        </div>
        <div class="rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-950/30 p-4">
            <p class="text-xs uppercase tracking-wide text-indigo-700 dark:text-indigo-300">Rows updated in fix run</p>
            <p class="mt-1 text-2xl font-bold text-indigo-700 dark:text-indigo-300">{{ number_format((int) ($mrFix['updated_rows'] ?? 0)) }}</p>
        </div>
    </div>

    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Per-column pending report</h2>
            <span class="text-xs text-gray-500">Opening this page refreshes counts</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Table</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Base column</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">MR column</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Expected</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Filled</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Pending</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($mrColumns as $row)
                        @if (is_array($row))
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row['table'] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row['base_column'] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row['mr_column'] ?? '—' }}</td>
                                <td class="px-4 py-2 tabular-nums">{{ number_format((int) ($row['expected_rows'] ?? 0)) }}</td>
                                <td class="px-4 py-2 tabular-nums text-emerald-700 dark:text-emerald-300 font-semibold">{{ number_format((int) ($row['filled_rows'] ?? 0)) }}</td>
                                <td class="px-4 py-2 tabular-nums font-semibold text-amber-700 dark:text-amber-300">{{ number_format((int) ($row['pending_rows'] ?? 0)) }}</td>
                                <td class="px-4 py-2">
                                    @if (((int) ($row['pending_rows'] ?? 0)) > 0)
                                        <a href="{{ route('admin.data-engine.mr-fill.index', ['table' => $row['table'] ?? '', 'base' => $row['base_column'] ?? '', 'mr' => $row['mr_column'] ?? '']) }}"
                                           class="inline-flex rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">
                                            Fill pending
                                        </a>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">No `_mr` columns with a matching base column were found (or tables have no numeric <code class="text-xs">id</code>).</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if (! empty($updatedByColumn))
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Fix updates by column</h2>
            </div>
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($updatedByColumn as $row)
                    @if (is_array($row))
                        <li class="px-4 py-3 text-sm flex items-center justify-between gap-3">
                            <span class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ $row['table'] ?? '—' }}.{{ $row['mr_column'] ?? '—' }}</span>
                            <span class="tabular-nums font-semibold text-emerald-700 dark:text-emerald-300">{{ number_format((int) ($row['updated_rows'] ?? 0)) }}</span>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($skipped))
        <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-4">
            <h2 class="text-sm font-semibold text-red-900 dark:text-red-100">Skipped items</h2>
            <ul class="mt-2 list-disc list-inside text-sm text-red-800 dark:text-red-200">
                @foreach ($skipped as $row)
                    @if (is_array($row))
                        <li>{{ ($row['table'] ?? '—') . '.' . ($row['mr_column'] ?? '—') }} — {{ $row['reason'] ?? 'skipped' }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    <div id="data-engine-loading" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/25 dark:bg-black/50" aria-live="polite" aria-busy="false">
        <div class="rounded-xl bg-white dark:bg-gray-800 px-6 py-5 shadow-xl border border-gray-200 dark:border-gray-700 flex items-center gap-4">
            <svg class="animate-spin h-9 w-9 text-indigo-600 dark:text-indigo-400 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <div>
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Running data engine…</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Wait for the page to reload.</p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('data-engine-loading');
    document.querySelectorAll('.js-data-engine-run-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            if (overlay) {
                overlay.classList.remove('hidden');
                overlay.setAttribute('aria-busy', 'true');
            }
            form.querySelectorAll('.js-data-engine-submit').forEach(function (btn) {
                btn.disabled = true;
            });
        });
    });
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted || !overlay) {
            return;
        }
        overlay.classList.add('hidden');
        overlay.setAttribute('aria-busy', 'false');
        document.querySelectorAll('.js-data-engine-submit').forEach(function (btn) {
            btn.disabled = false;
        });
    });
})();
</script>
@endsection
