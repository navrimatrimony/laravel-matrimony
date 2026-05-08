@extends('layouts.admin')

@section('content')
@php
    $s = is_array($dl['summary'] ?? null) ? $dl['summary'] : [];
    $manifestErrors = is_array($dl['manifest_errors'] ?? null) ? $dl['manifest_errors'] : [];
    $wrong = is_array($dl['wrong_sources'] ?? null) ? $dl['wrong_sources'] : [];
    $multi = is_array($dl['multi_source_conflicts'] ?? null) ? $dl['multi_source_conflicts'] : [];
    $wpMismatch = is_array($dl['wizard_public_mismatches'] ?? null) ? $dl['wizard_public_mismatches'] : [];
    $missing = is_array($dl['missing_render_risks'] ?? null) ? $dl['missing_render_risks'] : [];
    $impl = is_array($dl['implementation'] ?? null) ? $dl['implementation'] : [];
    $health = isset($s['health_score']) && $s['health_score'] !== null ? (int) $s['health_score'] : null;
    $sevBadge = function (?string $sev): string {
        $s = strtolower((string) ($sev ?? ''));
        return match ($s) {
            'high' => 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950/30 dark:text-red-100',
            'medium' => 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/25 dark:text-amber-100',
            'low' => 'border-sky-300 bg-sky-50 text-sky-950 dark:border-sky-800 dark:bg-sky-950/25 dark:text-sky-100',
            default => 'border-gray-200 bg-gray-50 text-gray-800 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-200',
        };
    };
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <a href="{{ route('admin.data-engine.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">&larr; Back to data engine</a>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">Data lineage (PHASE 2)</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Manifest-driven audit: <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">python-data-engine/config/data_lineage.yml</code> defines canonical DB columns and Blade paths;
                the engine regex-scans those files (no AST). Run <strong>Analyze</strong> to refresh this section in the JSON report.
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

    @if ($latestRun)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 mb-6">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                Report source: <strong>Run #{{ $latestRun->id }}</strong> · {{ $latestRun->mode }} · {{ $latestRun->status }}
                <span class="mx-1">|</span>
                <a href="{{ route('admin.data-engine.show', $latestRun) }}" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 font-semibold">Open run JSON</a>
            </p>
        </div>
    @endif

    @if (! empty($engineRunning))
        <div class="mb-4 rounded-md bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-300 px-4 py-3 text-sm text-yellow-900 dark:text-yellow-100">
            A run is <strong>in progress</strong>; analyze is disabled until it completes.
        </div>
    @endif

    {{-- 1. Overall health --}}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-8">
        <div class="rounded-xl border border-violet-200 dark:border-violet-900/40 bg-violet-50 dark:bg-violet-950/30 p-4 col-span-2 md:col-span-1 lg:col-span-1">
            <p class="text-xs uppercase tracking-wide text-violet-800 dark:text-violet-200">Health score</p>
            <p class="mt-1 text-3xl font-bold text-violet-900 dark:text-violet-100">{{ $health !== null ? $health.'%' : '—' }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <p class="text-xs uppercase text-gray-500">Fields audited</p>
            <p class="mt-1 text-xl font-bold">{{ number_format((int) ($s['fields_audited'] ?? 0)) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <p class="text-xs uppercase text-gray-500">Manifest errors</p>
            <p class="mt-1 text-xl font-bold">{{ number_format((int) ($s['manifest_errors'] ?? count($manifestErrors))) }}</p>
        </div>
        <div class="rounded-xl border border-red-200 dark:border-red-900/40 bg-red-50 dark:bg-red-950/20 p-4">
            <p class="text-xs uppercase text-red-800 dark:text-red-200">Wrong source</p>
            <p class="mt-1 text-xl font-bold text-red-800 dark:text-red-200">{{ number_format((int) ($s['wrong_sources'] ?? count($wrong))) }}</p>
        </div>
        <div class="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50 dark:bg-amber-950/20 p-4">
            <p class="text-xs uppercase text-amber-900 dark:text-amber-100">Multi-source</p>
            <p class="mt-1 text-xl font-bold">{{ number_format((int) ($s['multi_source_conflicts'] ?? count($multi))) }}</p>
        </div>
        <div class="rounded-xl border border-orange-200 dark:border-orange-900/40 bg-orange-50 dark:bg-orange-950/20 p-4">
            <p class="text-xs uppercase text-orange-900 dark:text-orange-100">Wizard ↔ public</p>
            <p class="mt-1 text-xl font-bold">{{ number_format((int) ($s['wizard_public_mismatches'] ?? count($wpMismatch))) }}</p>
        </div>
        <div class="rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40 p-4">
            <p class="text-xs uppercase text-gray-600 dark:text-gray-300">Missing render</p>
            <p class="mt-1 text-xl font-bold">{{ number_format((int) ($s['missing_render_risks'] ?? count($missing))) }}</p>
        </div>
    </div>

    {{-- 2. Wizard ↔ Public mismatches --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Wizard ↔ public mismatches</h2>
            <p class="text-xs text-gray-500 mt-1">Manifest-declared bindings differ between wizard vs public profile.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Field</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Severity</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Wizard binding</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Public binding</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($wpMismatch as $row)
                        @if (is_array($row))
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row['field'] ?? '—' }}</td>
                                @php $sev = (string) ($row['severity'] ?? ''); @endphp
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $sevBadge($sev) }}">{{ $sev !== '' ? $sev : '—' }}</span>
                                </td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row['wizard'] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row['public'] ?? '—' }}</td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-gray-500">No wizard/public mismatches detected.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 3. Wrong sources --}}
    <div class="rounded-xl border border-red-200 dark:border-red-900/40 bg-red-50/50 dark:bg-red-950/20 px-4 py-4 mb-6">
        <h2 class="text-sm font-semibold text-red-900 dark:text-red-100">Wrong source warnings</h2>
        <p class="text-xs text-red-800/90 dark:text-red-200 mt-1">Example: expected <code class="text-xs">profile.height_cm</code> but detected <code class="text-xs">user.height</code>.</p>
        <ul class="mt-3 space-y-2 text-sm text-red-900 dark:text-red-100">
            @forelse ($wrong as $row)
                @if (is_array($row))
                    @php $sev = (string) ($row['severity'] ?? 'high'); @endphp
                    <li class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs">{{ $row['field'] ?? '?' }}</span>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $sevBadge($sev) }}">{{ $sev }}</span>
                        <span class="text-xs">expected</span>
                        <code class="text-[11px] bg-white/70 dark:bg-gray-900/40 px-1 rounded">{{ $row['expected'] ?? '—' }}</code>
                        <span class="text-xs">actual</span>
                        <code class="text-[11px] bg-white/70 dark:bg-gray-900/40 px-1 rounded">{{ $row['actual'] ?? '—' }}</code>
                    </li>
                @endif
            @empty
                <li class="text-red-700/80 dark:text-red-300/90">None.</li>
            @endforelse
        </ul>
    </div>

    {{-- 4. Multi-source conflicts --}}
    <div class="rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/50 dark:bg-amber-950/20 px-4 py-4 mb-6">
        <h2 class="text-sm font-semibold text-amber-900 dark:text-amber-100">Multi-source conflicts</h2>
        <p class="text-xs text-amber-800 dark:text-amber-200 mt-1">Patterns like <code class="text-xs">$profile->x ?? $user->y</code> in manifest blades.</p>
        <ul class="mt-3 space-y-2 text-sm">
            @forelse ($multi as $row)
                @if (is_array($row))
                    @php
                        $sev = (string) ($row['severity'] ?? 'medium');
                        $sources = is_array($row['sources'] ?? null) ? $row['sources'] : [];
                    @endphp
                    <li class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs text-amber-950 dark:text-amber-100">{{ $row['field'] ?? '' }}</span>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $sevBadge($sev) }}">{{ $sev }}</span>
                        <span class="text-xs text-amber-950/80 dark:text-amber-100/80">{{ $row['layer'] ?? '' }}</span>
                        <code class="text-[11px] bg-white/70 dark:bg-gray-900/40 px-1 rounded font-mono">{{ implode(' ?? ', array_map('strval', $sources)) }}</code>
                    </li>
                @endif
            @empty
                <li class="text-amber-800 dark:text-amber-200">None.</li>
            @endforelse
        </ul>
    </div>

    {{-- 5. Missing render risks --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Missing render risks</h2>
            <p class="text-xs text-gray-500 mt-1">Canonical source exists, but no expected render usage was detected in the manifest Blade paths.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Field</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Severity</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($missing as $row)
                        @if (is_array($row))
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $row['field'] ?? '—' }}</td>
                                @php $sev = (string) ($row['severity'] ?? 'medium'); @endphp
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $sevBadge($sev) }}">{{ $sev }}</span>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="2" class="px-4 py-6 text-center text-gray-500">None.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Manifest errors --}}
    @if ($manifestErrors !== [])
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-4 mb-6">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Manifest / schema errors</h2>
            <ul class="mt-2 list-disc list-inside text-sm text-gray-700 dark:text-gray-300 space-y-1">
                @foreach ($manifestErrors as $row)
                    @if (is_array($row))
                        <li>{{ json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($impl['notes']) && is_array($impl['notes']))
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
            @foreach ($impl['notes'] as $note)
                @if (is_string($note))<p class="mt-1">{{ $note }}</p>@endif
            @endforeach
        </div>
    @endif

    <div id="data-engine-loading" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/25" aria-live="polite">
        <div class="rounded-xl bg-white dark:bg-gray-800 px-6 py-5 shadow-xl border flex items-center gap-4">
            <svg class="animate-spin h-9 w-9 text-indigo-600 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">Running analyze…</span>
        </div>
    </div>
</div>
<script>
(function () {
    var overlay = document.getElementById('data-engine-loading');
    document.querySelectorAll('.js-data-engine-run-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            if (overlay) overlay.classList.remove('hidden');
            form.querySelectorAll('.js-data-engine-submit').forEach(function (btn) { btn.disabled = true; });
        });
    });
})();
</script>
@endsection
