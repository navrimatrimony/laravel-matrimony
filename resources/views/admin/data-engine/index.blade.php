@extends('layouts.admin')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    @php
        $mon = is_array($monitor ?? null) ? $monitor : [];
        $mh = $mon['health']['state'] ?? 'online';
        $mhCls = match ($mh) {
            'disabled', 'off' => 'border-rose-400 bg-rose-50 text-rose-950 dark:border-rose-600 dark:bg-rose-950/45 dark:text-rose-100',
            'online' => 'border-emerald-400 bg-emerald-50 text-emerald-950 dark:border-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-100',
            'online_warnings' => 'border-amber-400 bg-amber-50 text-amber-950 dark:border-amber-600 dark:bg-amber-950/40 dark:text-amber-100',
            'analyze_running' => 'border-sky-400 bg-sky-50 text-sky-950 dark:border-sky-600 dark:bg-sky-950/40 dark:text-sky-100',
            'fix_running' => 'border-violet-400 bg-violet-50 text-violet-950 dark:border-violet-600 dark:bg-violet-950/40 dark:text-violet-100',
            'critical_failure', 'failed' => 'border-zinc-500 bg-zinc-100 text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100',
            default => 'border-gray-300 bg-gray-50 text-gray-800 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200',
        };
        $mhLabel = match ($mh) {
            'disabled', 'off' => 'Disabled',
            'online' => 'Online',
            'online_warnings' => 'Online · optional module warnings',
            'analyze_running' => 'Analyze running',
            'fix_running' => 'Fix running',
            'critical_failure', 'failed' => 'Critical · no completed run yet',
            default => 'Online',
        };
    @endphp

    <div class="mb-4 flex flex-wrap gap-2 text-xs">
        <a href="#de-section-control" class="rounded border px-2.5 py-1 bg-white dark:bg-gray-900">Control</a>
        <a href="#de-section-governance" class="rounded border px-2.5 py-1 bg-white dark:bg-gray-900">Governance</a>
        <a href="#de-section-health" class="rounded border px-2.5 py-1 bg-white dark:bg-gray-900">Health & Ops</a>
        <a href="#de-section-runtime" class="rounded border px-2.5 py-1 bg-white dark:bg-gray-900">Live Runtime</a>
        <a href="#de-section-history" class="rounded border px-2.5 py-1 bg-white dark:bg-gray-900">History</a>
    </div>

    <details id="de-section-control" open class="mb-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/30">
        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700">Control</summary>
        <div class="p-4">
    {{-- Engine controls: runtime state badge is not historical run outcome --}}
    <div class="mb-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/40 px-4 py-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-start lg:justify-between">
            <div class="min-w-0 max-w-xl">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Engine control</p>
                <p class="text-[11px] text-gray-600 dark:text-gray-400 mt-1">
                    The <span class="font-medium text-gray-800 dark:text-gray-200">database power</span> switch allows or blocks runs. The badge shows <span class="font-medium text-gray-800 dark:text-gray-200">current engine availability</span> only (polls every 5s).
                </p>
            </div>
            <div class="flex flex-col items-stretch sm:items-end gap-1.5 shrink-0">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 sm:text-right">Current engine state</p>
                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                <span id="data-engine-health-badge"
                      role="status"
                      data-health-state="{{ $mh }}"
                      class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold cursor-default select-none {{ $mhCls }}">
                    {{ $mhLabel }}
                </span>
                <span id="data-engine-queue-badge"
                      class="inline-flex items-center rounded-full border border-violet-300 dark:border-violet-700 bg-violet-50 dark:bg-violet-950/50 px-2.5 py-1 text-[11px] font-medium text-violet-900 dark:text-violet-100 {{ ! empty($mon['queue_mode']) ? '' : 'hidden' }}"
                      title="Production runs may execute via the queue worker">
                    🧵 Queue mode
                </span>
                <span id="data-engine-lock-badge"
                      class="{{ ! empty($mon['lock_active']) ? '' : 'hidden' }} inline-flex items-center rounded-full border border-amber-400 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/50 px-2.5 py-1 text-[11px] font-medium text-amber-950 dark:text-amber-100"
                      title="CLI execution active">
                    🔒 Run lock
                </span>
                <form method="post" action="{{ route('admin.data-engine.toggle-engine') }}" class="inline-flex items-center shrink-0"
                      title="Saves admin_settings.data_engine_enabled — allows or blocks analyze/fix">
                    @csrf
                    @if ($engineDbOn ?? true)
                        <button type="submit"
                            class="inline-flex items-center rounded-lg border-2 border-emerald-600 bg-white px-3 py-1.5 text-sm font-semibold text-emerald-800 shadow-sm hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:bg-gray-900 dark:text-emerald-300 dark:border-emerald-500 dark:hover:bg-emerald-950/50 dark:focus:ring-offset-gray-900">
                            Database power: ON
                            <span class="ml-2 text-xs font-normal opacity-80">(click → OFF)</span>
                        </button>
                    @else
                        <button type="submit"
                            class="inline-flex items-center rounded-lg border-2 border-red-500 bg-white px-3 py-1.5 text-sm font-semibold text-red-800 shadow-sm hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:bg-gray-900 dark:text-red-300 dark:border-red-500 dark:hover:bg-red-950/40 dark:focus:ring-offset-gray-900">
                            Database power: OFF
                            <span class="ml-2 text-xs font-normal opacity-80">(click → ON)</span>
                        </button>
                    @endif
                </form>
                </div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 sm:text-right max-w-md sm:ml-auto leading-snug">
                    Current state reflects runtime availability. Execution failures are historical events (see execution history below).
                </p>
            </div>
        </div>
    </div>

    @if(!empty($governanceSimpleHealth))
        <div class="mb-6 rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50/70 dark:bg-indigo-950/30 px-4 py-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-800 dark:text-indigo-200">Profile check health (last dashboard refresh)</p>
            <p class="text-xs text-indigo-900/80 dark:text-indigo-100/80 mt-1">सदस्य प्रोफाइल तुलना — शेवटचा डॅशबोर्ड रिफ्रेश</p>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                <div class="rounded-lg bg-white/90 dark:bg-gray-900/60 border border-indigo-100 dark:border-indigo-900 px-3 py-2">
                    <p class="text-[11px] text-gray-500 uppercase">Overall score</p>
                    <p class="text-2xl font-bold text-indigo-900 dark:text-indigo-100">{{ $governanceSimpleHealth['score'] ?? '—' }}<span class="text-sm font-normal text-gray-500">/100</span></p>
                </div>
                <div class="rounded-lg bg-white/90 dark:bg-gray-900/60 border border-indigo-100 dark:border-indigo-900 px-3 py-2">
                    <p class="text-[11px] text-gray-500 uppercase">Items to review</p>
                    <p class="text-2xl font-bold text-indigo-900 dark:text-indigo-100">{{ (int) ($governanceSimpleHealth['items_needing_review'] ?? 0) }}</p>
                    <p class="text-[11px] text-gray-500">Serious: {{ (int) ($governanceSimpleHealth['serious_items'] ?? 0) }}</p>
                </div>
                <div class="rounded-lg bg-white/90 dark:bg-gray-900/60 border border-indigo-100 dark:border-indigo-900 px-3 py-2">
                    <p class="text-[11px] text-gray-500 uppercase">Snapshots saved</p>
                    <p class="text-2xl font-bold text-indigo-900 dark:text-indigo-100">{{ (int) ($governanceSimpleHealth['snapshots_on_disk'] ?? 0) }}</p>
                </div>
                <div class="rounded-lg bg-white/90 dark:bg-gray-900/60 border border-indigo-100 dark:border-indigo-900 px-3 py-2">
                    <p class="text-[11px] text-gray-500 uppercase">Last refresh</p>
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $governanceSimpleHealth['last_dashboard_refresh'] ?? '—' }}</p>
                </div>
            </div>
            <p class="text-xs text-indigo-900/70 dark:text-indigo-200/80 mt-3">Use <strong>Refresh dashboard</strong> below after running snapshots or comparisons. Open a profile from the comparisons list to see plain-language cards.</p>
        </div>
    @endif

    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-6">
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Python data engine</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Runs <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">python-data-engine/scripts/runner.py</code> with the same DB as Laravel.
                Future HTTP API is reserved on port <strong>{{ config('data_engine.http_port') }}</strong> (not active yet).
            </p>
            @if (! ($envEngineAllows ?? true))
                <p class="text-xs text-amber-800 dark:text-amber-200 mt-2 rounded-md border border-amber-300 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-3 py-2">
                    <strong>Environment:</strong> <code class="text-[11px]">DATA_ENGINE_ENABLED=false</code> — the engine stays off until configuration allows it (database switch cannot override).
                </p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2 shrink-0 items-start lg:justify-end lg:max-w-xl">
            <a href="{{ route('admin.data-engine.data-lineage') }}"
               class="inline-flex items-center rounded-md bg-fuchsia-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-fuchsia-600">
                Data lineage
            </a>
            <a href="{{ route('admin.data-engine.comparisons') }}"
               class="inline-flex items-center rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-600">
                Comparisons
            </a>
            <a href="{{ route('admin.data-engine.data-integrity') }}"
               class="inline-flex items-center rounded-md bg-violet-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-600">
                Data integrity
            </a>
            <a href="{{ route('admin.data-engine.marathi-columns') }}"
               class="inline-flex items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-600">
                Marathi `_mr` report
            </a>
            <form method="post" action="{{ route('admin.data-engine.run-analyze') }}" class="inline js-data-engine-run-form">
                @csrf
                <button type="submit" @disabled($engineRunning || ! ($enginePowered ?? true))
                    class="js-data-engine-submit inline-flex items-center rounded-md bg-slate-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-600 dark:bg-slate-600 dark:hover:bg-slate-500 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-slate-700">
                    Run analyze
                </button>
            </form>
            <form method="post" action="{{ route('admin.data-engine.run-fix') }}" class="inline js-data-engine-run-form" id="data-engine-fix-form"
                  @if (($enginePowered ?? true) && ! $engineRunning)
                      data-confirm-fix="1"
                  @endif>
                @csrf
                <button type="submit" @disabled($engineRunning || ! ($enginePowered ?? true))
                    class="js-data-engine-submit inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-amber-600">
                    Run fix
                </button>
            </form>
        </div>
    </div>
        </div>
    </details>

    <details id="de-section-governance" open class="mb-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/30">
        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700">Governance</summary>
        <div class="p-4">
    <div class="mb-4 rounded-xl border border-indigo-200 dark:border-indigo-900 bg-indigo-50/60 dark:bg-indigo-950/20 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-indigo-950 dark:text-indigo-100">Select matrimony profile (no ID needed)</h3>
            <form method="get" action="{{ route('admin.data-engine.index') }}" class="flex items-center gap-2">
                <input type="text" name="profile_search" value="{{ (string) ($profilePickerQuery ?? '') }}" placeholder="Search by name, mobile, email, or matrimony profile ID" class="w-72 rounded border border-indigo-300 px-2 py-1.5 text-xs dark:bg-gray-900">
                <button type="submit" class="rounded bg-indigo-700 px-2.5 py-1.5 text-xs font-semibold text-white">Search</button>
            </form>
        </div>
        <p class="mt-1 text-[11px] text-indigo-900/80 dark:text-indigo-200/80">All IDs below are <strong>matrimony profile IDs</strong> (SSOT) — the same ID governance snapshots, comparisons and the public profile route use. <span class="opacity-80">User ID is shown only as a secondary reference.</span></p>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead>
                    <tr class="text-left border-b border-indigo-200 dark:border-indigo-800">
                        <th class="py-2 pr-3">Matrimony profile ID</th>
                        <th class="py-2 pr-3">Name</th>
                        <th class="py-2 pr-3">Mobile</th>
                        <th class="py-2 pr-3">Email</th>
                        <th class="py-2 pr-3">User ID</th>
                        <th class="py-2 pr-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($profilePickerRows ?? []) as $pr)
                        @php
                            $displayName = trim((string) ($pr->full_name ?? '')) !== ''
                                ? $pr->full_name
                                : (trim((string) ($pr->user_name ?? '')) !== '' ? $pr->user_name : '—');
                        @endphp
                        <tr class="border-b border-indigo-100/70 dark:border-indigo-900/40">
                            <td class="py-2 pr-3 font-mono">#{{ (int) $pr->id }}</td>
                            <td class="py-2 pr-3">{{ $displayName }}</td>
                            <td class="py-2 pr-3">{{ $pr->mobile ?: '—' }}</td>
                            <td class="py-2 pr-3">{{ $pr->email ?: '—' }}</td>
                            <td class="py-2 pr-3 font-mono text-gray-500">{{ $pr->user_id ? 'u#'.(int) $pr->user_id : '—' }}</td>
                            <td class="py-2 pr-3">
                                <a href="{{ route('admin.data-engine.profiles.show', ['profileId' => (int) $pr->id]) }}" class="inline-flex rounded border border-indigo-300 px-2 py-1">Open governance profile</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-gray-600">No matrimony profiles found for this search.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @php
        $govCards = is_array($governanceHealthCards ?? null) ? ($governanceHealthCards['module_health_score'] ?? []) : [];
        $govRisk = is_array($governanceRiskSummary ?? null) ? $governanceRiskSummary : [];
        $issueRows = is_array($governanceIssueSummaries ?? null) ? $governanceIssueSummaries : [];
        $coverage = is_array($governanceDashboard['coverage'] ?? null) ? $governanceDashboard['coverage'] : [];
        $snapshotIntegrity = is_array($governanceDashboard['snapshot_integrity'] ?? null) ? $governanceDashboard['snapshot_integrity'] : [];
        $coverageTotals = is_array($coverage['totals'] ?? null) ? $coverage['totals'] : [];
        $sectionCoverage = is_array($coverage['section_coverage'] ?? null) ? $coverage['section_coverage'] : [];
        $silentLossAlerts = is_array($coverage['silent_data_loss_alerts'] ?? null) ? $coverage['silent_data_loss_alerts'] : [];
        $repeaterAlerts = is_array($coverage['repeater_mismatches'] ?? null) ? $coverage['repeater_mismatches'] : [];
    @endphp
    <div class="rounded-xl border border-sky-200 dark:border-sky-800/50 bg-sky-50/50 dark:bg-sky-950/20 px-4 py-4 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-sky-950 dark:text-sky-100">Governance dashboard</h2>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.data-engine.issues') }}" class="inline-flex items-center rounded-md bg-white dark:bg-gray-900 px-2.5 py-1.5 text-xs font-semibold border border-sky-200 dark:border-sky-700">Issue center</a>
                <a href="{{ route('admin.data-engine.workflows') }}" class="inline-flex items-center rounded-md bg-white dark:bg-gray-900 px-2.5 py-1.5 text-xs font-semibold border border-sky-200 dark:border-sky-700">Workflows</a>
                <a href="{{ route('admin.data-engine.audit') }}" class="inline-flex items-center rounded-md bg-white dark:bg-gray-900 px-2.5 py-1.5 text-xs font-semibold border border-sky-200 dark:border-sky-700">Audit</a>
                <a href="{{ route('admin.data-engine.system-health') }}" class="inline-flex items-center rounded-md bg-white dark:bg-gray-900 px-2.5 py-1.5 text-xs font-semibold border border-sky-200 dark:border-sky-700">System health</a>
                <a href="{{ route('admin.data-engine.rollback') }}" class="inline-flex items-center rounded-md bg-white dark:bg-gray-900 px-2.5 py-1.5 text-xs font-semibold border border-sky-200 dark:border-sky-700">Rollback</a>
                <form method="get" action="{{ route('admin.data-engine.index') }}" onsubmit="this.action='/admin/data-engine/profiles/'+(this.profile_id.value||207)" class="inline-flex items-center gap-1" title="Enter matrimony profile ID (matrimony_profiles.id), not user ID">
                    <label class="text-[11px] text-sky-900 dark:text-sky-200" for="de-gov-profile-id">Matrimony profile&nbsp;ID</label>
                    <input id="de-gov-profile-id" type="number" name="profile_id" min="1" value="{{ (int) request('profile_id', 207) }}" placeholder="matrimony profile id" class="w-24 rounded border border-sky-300 px-2 py-1 text-xs dark:bg-gray-900" />
                    <button type="submit" class="inline-flex items-center rounded-md bg-white dark:bg-gray-900 px-2.5 py-1.5 text-xs font-semibold border border-sky-200 dark:border-sky-700">Open governance profile</button>
                </form>
                <form method="post" action="{{ route('admin.data-engine.refresh-dashboard') }}">@csrf
                    <button class="inline-flex items-center rounded-md bg-sky-700 px-2.5 py-1.5 text-xs font-semibold text-white">Refresh artifacts</button>
                </form>
            </div>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-6 gap-3 mt-3 text-xs">
            <div class="rounded border border-sky-200 dark:border-sky-800 px-3 py-2 bg-white dark:bg-gray-900">Overall health: <span class="font-mono font-semibold">{{ (int) ($govRisk['overall_platform_health'] ?? 0) }}</span></div>
            <div class="rounded border border-sky-200 dark:border-sky-800 px-3 py-2 bg-white dark:bg-gray-900">Critical issues: <span class="font-mono font-semibold">{{ (int) ($govRisk['critical_issue_count'] ?? 0) }}</span></div>
            <div class="rounded border border-sky-200 dark:border-sky-800 px-3 py-2 bg-white dark:bg-gray-900">Warnings: <span class="font-mono font-semibold">{{ (int) ($warningIssueCount ?? 0) }}</span></div>
            <div class="rounded border border-sky-200 dark:border-sky-800 px-3 py-2 bg-white dark:bg-gray-900">Failed workflows: <span class="font-mono font-semibold">{{ (int) ($failedWorkflowCount ?? 0) }}</span></div>
            <div class="rounded border border-sky-200 dark:border-sky-800 px-3 py-2 bg-white dark:bg-gray-900">Scheduler: <span class="font-mono font-semibold">{{ ($opsUnhealthy ?? false) ? 'warning' : 'healthy' }}</span></div>
            <div class="rounded border border-sky-200 dark:border-sky-800 px-3 py-2 bg-white dark:bg-gray-900">Last fix run: <span class="font-mono font-semibold">{{ optional($runs->first())->mode === 'fix' ? optional($runs->first())->created_at?->format('Y-m-d H:i') : '—' }}</span></div>
        </div>
        @if ($govCards)
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-2 mt-3 text-xs">
                @foreach ($govCards as $k => $v)
                    <div class="rounded border border-gray-200 dark:border-gray-700 px-2 py-1 bg-white dark:bg-gray-900">{{ str_replace('_', ' ', (string) $k) }}: <span class="font-mono font-semibold">{{ (int) $v }}</span></div>
                @endforeach
            </div>
        @endif
        @if ($issueRows)
            <div class="mt-3 space-y-2">
                @foreach (array_slice($issueRows, 0, 3) as $row)
                    @php $friendly = $friendlyImpact[$row['issue'] ?? ''] ?? ['en' => $row['impact'] ?? '', 'mr' => '']; @endphp
                    <div class="rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-xs">
                        <p><span class="font-semibold">{{ $row['issue'] ?? 'Issue' }}</span> · <span class="uppercase">{{ $row['severity'] ?? 'low' }}</span></p>
                        <p class="text-gray-700 dark:text-gray-300">{{ $friendly['en'] }}</p>
                        <p class="text-gray-600 dark:text-gray-400">{{ $friendly['mr'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif
        @if ($coverage)
            <div class="mt-4 rounded border border-sky-200 dark:border-sky-800 bg-white dark:bg-gray-900 p-3 text-xs">
                <p><span class="font-semibold">Coverage score:</span> <span class="font-mono">{{ $coverage['coverage_label'] ?? '0 / 0 fields audited' }}</span></p>
                <p class="mt-1">
                    Not checked yet: <span class="font-mono">{{ (int) ($coverageTotals['unaudited_fields'] ?? 0) }}</span> ·
                    Section mapping needs review: <span class="font-mono">{{ (int) ($coverageTotals['unsupported_repeaters'] ?? 0) }}</span> ·
                    Relationship mapping needs review: <span class="font-mono">{{ (int) ($coverageTotals['unsupported_relations'] ?? 0) }}</span>
                </p>
                <div class="overflow-x-auto mt-2">
                    <table class="min-w-full text-xs">
                        <thead><tr><th class="text-left pr-3">Section</th><th class="text-left pr-3">Coverage</th><th class="text-left">Audited / Detected</th></tr></thead>
                        <tbody>
                            @foreach ($sectionCoverage as $row)
                                <tr>
                                    <td class="pr-3">{{ $row['section'] ?? 'unknown' }}</td>
                                    <td class="pr-3 font-mono">{{ (float) ($row['coverage_percent'] ?? 0) }}%</td>
                                    <td class="font-mono">{{ (int) ($row['audited'] ?? 0) }} / {{ (int) ($row['detected'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($silentLossAlerts)
                    <div class="mt-2 rounded border border-rose-200 dark:border-rose-800 bg-rose-50 dark:bg-rose-950/30 px-2 py-2">
                        <p class="font-semibold">Silent data loss alerts</p>
                        <ul class="list-disc list-inside">
                            @foreach (array_slice($silentLossAlerts, 0, 5) as $a)
                                <li class="flex flex-wrap items-center gap-2">
                                    <span>{{ $a['type'] ?? 'alert' }} · {{ $a['field'] ?? 'field' }}</span>
                                    <a href="{{ route('admin.data-engine.profiles.show', ['profileId' => (int) request('profile_id', 207)]) }}" class="inline-flex rounded border border-rose-300 px-2 py-0.5 text-[11px]">Open governance profile</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($repeaterAlerts)
                    <div class="mt-2 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-2 py-2">
                        <p class="font-semibold">Repeater mismatch alerts</p>
                        <ul class="list-disc list-inside">
                            @foreach (array_slice($repeaterAlerts, 0, 5) as $a)
                                <li class="flex flex-wrap items-center gap-2">
                                    <span>{{ $a['repeater'] ?? 'repeater' }} · {{ $a['type'] ?? 'mismatch' }}</span>
                                    <a href="{{ route('admin.data-engine.profiles.show', ['profileId' => (int) request('profile_id', 207)]) }}" class="inline-flex rounded border border-amber-300 px-2 py-0.5 text-[11px]">Open governance profile</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
        @if ($snapshotIntegrity)
            <div class="mt-3 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/20 px-3 py-2 text-xs">
                <p><span class="font-semibold">Snapshot trust check:</span> valid {{ (int) ($snapshotIntegrity['valid_snapshots'] ?? 0) }} · needs review {{ (int) ($snapshotIntegrity['invalid_snapshots'] ?? 0) }} · extra folders {{ count($snapshotIntegrity['orphan_snapshot_directories'] ?? []) }}</p>
                @if ((int) ($snapshotIntegrity['invalid_snapshots'] ?? 0) > 0)
                    <p class="text-amber-900 dark:text-amber-100 mt-1">Invalid snapshot warnings detected. Comparison uses eligible snapshots only.</p>
                @endif
            </div>
        @endif
    </div>
        </div>
    </details>

    <details id="de-section-health" open class="mb-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/30">
        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700">Health & Ops</summary>
        <div class="p-4">

    @php
        $dl = is_array($latestLineage ?? null) ? $latestLineage : null;
        $dls = is_array($dl['summary'] ?? null) ? $dl['summary'] : null;
        $dlHealth = isset($dls['health_score']) ? (int) $dls['health_score'] : null;
        $dlMismatch = isset($dls['wizard_public_mismatches']) ? (int) $dls['wizard_public_mismatches'] : 0;
        $dlConf = isset($dls['multi_source_conflicts']) ? (int) $dls['multi_source_conflicts'] : 0;
    @endphp
    @if ($dls !== null)
        <div class="mb-4 rounded-md border border-fuchsia-200 dark:border-fuchsia-900/40 bg-fuchsia-50/60 dark:bg-fuchsia-950/20 px-4 py-3 text-xs text-fuchsia-950 dark:text-fuchsia-100">
            <span class="font-semibold">Data lineage</span>
            <span class="mx-2 opacity-70">|</span>
            <span>Health: <span class="font-mono font-semibold">{{ $dlHealth !== null ? $dlHealth.'%' : '—' }}</span></span>
            <span class="mx-2 opacity-70">|</span>
            <span>Mismatches: <span class="font-mono font-semibold">{{ $dlMismatch }}</span></span>
            <span class="mx-2 opacity-70">|</span>
            <span>Conflicts: <span class="font-mono font-semibold">{{ $dlConf }}</span></span>
        </div>
    @endif

    @php
        $snapshotMeta = is_array($snapshotMeta ?? null) ? $snapshotMeta : null;
        $snapshotCount = (int) ($snapshotCount ?? 0);
        $snapshotHealth = (string) ($snapshotHealth ?? 'empty');
    @endphp
    <div class="mb-4 rounded-md border {{ $snapshotHealth === 'ready' ? 'border-emerald-200 dark:border-emerald-800/50 bg-emerald-50/60 dark:bg-emerald-950/20 text-emerald-950 dark:text-emerald-100' : 'border-amber-200 dark:border-amber-800/50 bg-amber-50/60 dark:bg-amber-950/20 text-amber-950 dark:text-amber-100' }} px-4 py-3 text-xs">
        <span class="font-semibold">Snapshot engine</span>
        <span class="mx-2 opacity-70">|</span>
        <span>Health: <span class="font-mono font-semibold">{{ $snapshotHealth === 'ready' ? 'ready' : 'waiting first capture' }}</span></span>
        <span class="mx-2 opacity-70">|</span>
        <span>Total snapshots: <span class="font-mono font-semibold">{{ $snapshotCount }}</span></span>
        <span class="mx-2 opacity-70">|</span>
        <span>Latest: <span class="font-mono font-semibold">{{ $snapshotMeta['timestamp'] ?? '—' }}</span></span>
    </div>
    @php $cmp = is_array($comparisonSummary ?? null) ? $comparisonSummary : null; @endphp
    <div class="mb-4 rounded-md border {{ $cmp ? 'border-indigo-200 dark:border-indigo-800/50 bg-indigo-50/60 dark:bg-indigo-950/20 text-indigo-950 dark:text-indigo-100' : 'border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-900/20 text-gray-700 dark:text-gray-300' }} px-4 py-3 text-xs">
        <span class="font-semibold">Comparison engine</span>
        @if ($cmp)
            <span class="mx-2 opacity-70">|</span>
            <span>Score: <span class="font-mono font-semibold">{{ $cmp['health_score'] ?? '—' }}</span></span>
            <span class="mx-2 opacity-70">|</span>
            <span>Mismatches: <span class="font-mono font-semibold">{{ $cmp['mismatch_count'] ?? 0 }}</span></span>
            <span class="mx-2 opacity-70">|</span>
            <span>High severity: <span class="font-mono font-semibold">{{ $cmp['high_severity_count'] ?? 0 }}</span></span>
            <span class="mx-2 opacity-70">|</span>
            <span>Latest: <span class="font-mono font-semibold">{{ $cmp['generated_at'] ?? '—' }}</span></span>
        @else
            <span class="mx-2 opacity-70">|</span>
            <span>No comparison report yet. Run <code class="text-[11px]">python runner.py compare --latest</code>.</span>
        @endif
    </div>

    @php
        $hb = is_array($heartbeat ?? null) ? $heartbeat : [];
        $storage = is_array($storageHealth ?? null) ? $storageHealth : [];
        $retention = is_array($retentionPolicy ?? null) ? $retentionPolicy : [];
        $unhealthy = (bool) ($opsUnhealthy ?? false);
    @endphp
    <div class="mb-4 rounded-md border {{ $unhealthy ? 'border-red-300 dark:border-red-800/60 bg-red-50/60 dark:bg-red-950/20 text-red-950 dark:text-red-100' : 'border-emerald-300 dark:border-emerald-800/60 bg-emerald-50/60 dark:bg-emerald-950/20 text-emerald-950 dark:text-emerald-100' }} px-4 py-3 text-xs">
        <span class="font-semibold">Engine heartbeat</span>
        <span class="mx-2 opacity-70">|</span>
        <span>Status: <span class="font-mono font-semibold">{{ $unhealthy ? 'unhealthy' : 'healthy' }}</span></span>
        <span class="mx-2 opacity-70">|</span>
        <span>Warning failure streak: <span class="font-mono font-semibold">{{ (int) config('data_engine.ops.warning_failure_streak', 2) }}</span></span>
        <span class="mx-2 opacity-70">|</span>
        <span>Stale threshold: <span class="font-mono font-semibold">{{ (int) config('data_engine.ops.stale_hours', 24) }}h</span></span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Scheduler health</h3>
            <div class="space-y-2 text-xs">
                @foreach (['analyze', 'snapshot', 'compare', 'cleanup', 'notify'] as $op)
                    @php $row = is_array($hb[$op] ?? null) ? $hb[$op] : []; @endphp
                    <div class="rounded border border-gray-200 dark:border-gray-700 px-2 py-1.5">
                        <span class="font-semibold uppercase">{{ $op }}</span>
                        <span class="mx-2 opacity-60">|</span>
                        <span>last success: <span class="font-mono">{{ $row['last_success_at'] ?? '—' }}</span></span>
                        <span class="mx-2 opacity-60">|</span>
                        <span>last failure: <span class="font-mono">{{ $row['last_failed_at'] ?? '—' }}</span></span>
                        <span class="mx-2 opacity-60">|</span>
                        <span>failure streak: <span class="font-mono font-semibold">{{ (int) ($row['failure_streak'] ?? 0) }}</span></span>
                        <span class="mx-2 opacity-60">|</span>
                        <span>avg runtime: <span class="font-mono">{{ (int) ($row['avg_duration_ms'] ?? 0) }} ms</span></span>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Retention & storage health</h3>
            <div class="text-xs space-y-1">
                <p><span class="font-semibold">Snapshots size:</span> <span class="font-mono">{{ number_format((int) (($storage['snapshots_bytes'] ?? 0) / 1024 / 1024), 2) }} MB</span></p>
                <p><span class="font-semibold">Comparisons size:</span> <span class="font-mono">{{ number_format((int) (($storage['comparisons_bytes'] ?? 0) / 1024 / 1024), 2) }} MB</span></p>
                <p><span class="font-semibold">Reports size:</span> <span class="font-mono">{{ number_format((int) (($storage['reports_bytes'] ?? 0) / 1024 / 1024), 2) }} MB</span></p>
                <hr class="my-2 border-gray-200 dark:border-gray-700">
                <p><span class="font-semibold">Snapshot retention:</span> keep {{ (int) ($retention['snapshot_keep_per_entity'] ?? 0) }} per entity, max age {{ (int) ($retention['snapshot_max_age_days'] ?? 0) }} days</p>
                <p><span class="font-semibold">Comparison retention:</span> keep {{ (int) ($retention['comparison_keep_files'] ?? 0) }} files, max age {{ (int) ($retention['comparison_max_age_days'] ?? 0) }} days</p>
                <p><span class="font-semibold">Report/log retention:</span> reports {{ (int) ($retention['report_max_age_days'] ?? 0) }} days, logs {{ (int) ($retention['log_max_age_days'] ?? 0) }} days</p>
                <p><span class="font-semibold">Quarantine dir:</span> <code class="text-[11px]">python-data-engine/output/quarantine</code></p>
            </div>
        </div>
    </div>
        </div>
    </details>

    <details id="de-section-runtime" open class="mb-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/30">
        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700">Live Runtime & Guidance</summary>
        <div class="p-4">

    @if (session('engine_toggle'))
        <div class="mb-4 rounded-md border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-950/40 px-4 py-3 text-sm text-sky-950 dark:text-sky-100">
            @if (session('engine_toggle') === 'db_on')
                <strong>Database power saved:</strong> ON — runs are allowed when configuration also permits. This does <em>not</em> erase execution history; the badge above shows only <em>current</em> engine availability.
            @else
                <strong>Database power saved:</strong> OFF — analyze and fix stay disabled until you turn this back ON.
            @endif
        </div>
    @endif

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

    <div id="data-engine-monitor"
         class="mb-6 space-y-4"
         data-status-url="{{ route('admin.data-engine.status') }}"
         data-poll-interval-ms="5000"
         aria-live="polite">

        <div id="data-engine-running-banner" class="{{ ! empty($mon['running']) ? '' : 'hidden' }} rounded-md bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-300 dark:border-yellow-800 px-4 py-3 text-sm text-yellow-900 dark:text-yellow-100">
            <strong>Live:</strong> engine process active — analyze/fix buttons stay disabled until this clears.
        </div>

        @php
            $mwInit = is_array($mon['module_warnings'] ?? null) ? $mon['module_warnings'] : [];
            $histFailInit = ! empty($mon['last_run']) && (($mon['last_run']['status'] ?? '') === 'failed');
        @endphp
        <div id="data-engine-module-warnings-shell"
             class="{{ ! empty($mwInit) ? '' : 'hidden' }} rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-950 dark:text-amber-100">
            <p class="font-semibold text-amber-950 dark:text-amber-50">Optional modules — warnings only</p>
            <p class="text-xs text-amber-900/90 dark:text-amber-200/90 mt-0.5">
                These do not mark the engine as failed; the full analyze report still ran when the last completed run succeeded.
            </p>
            <ul id="data-engine-module-warnings-list" class="mt-2 list-disc list-inside space-y-0.5 text-xs font-mono">
                @foreach ($mwInit as $mwRow)
                    @if (is_array($mwRow) && isset($mwRow['module'], $mwRow['warning']))
                        <li><span class="font-semibold">{{ $mwRow['module'] }}</span>: {{ $mwRow['warning'] }}</li>
                    @endif
                @endforeach
            </ul>
        </div>

        <div id="data-engine-panel-current-shell" class="{{ ! empty($mon['running']) ? '' : 'hidden' }} rounded-xl border border-amber-300 dark:border-amber-800 bg-amber-50/90 dark:bg-amber-950/25 px-4 py-4">
            <div class="flex items-center gap-2 mb-3">
                <svg class="animate-spin h-5 w-5 text-amber-700 dark:text-amber-300 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <h2 class="text-sm font-bold uppercase tracking-wide text-amber-950 dark:text-amber-100">Current run</h2>
            </div>
            <div id="data-engine-panel-current" class="text-sm text-amber-950 dark:text-amber-50 space-y-1 font-mono tabular-nums">
                @if (! empty($mon['current_run']))
                    @php
                        $cr = $mon['current_run'];
                        $ems = (int) ($cr['elapsed_ms'] ?? 0);
                        $sec = intdiv(max(0, $ems), 1000);
                        $h = intdiv($sec, 3600);
                        $m = intdiv($sec % 3600, 60);
                        $s = $sec % 60;
                        $hms = sprintf('%02d:%02d:%02d', $h, $m, $s);
                        $stDisp = ! empty($cr['started_at'])
                            ? \Illuminate\Support\Carbon::parse($cr['started_at'])->timezone(config('app.timezone'))->format('g:i A')
                            : '—';
                    @endphp
                    <div><span class="text-amber-800 dark:text-amber-200 font-sans font-semibold">Mode:</span> {{ $cr['mode'] ?? '—' }}</div>
                    <div><span class="text-amber-800 dark:text-amber-200 font-sans font-semibold">Started:</span> {{ $stDisp }}</div>
                    <div><span class="text-amber-800 dark:text-amber-200 font-sans font-semibold">Elapsed:</span> {{ $hms }}</div>
                @endif
            </div>
        </div>

        <div id="data-engine-last-run-shell" class="{{ ! empty($mon['last_run']) ? '' : 'hidden' }} rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/80 px-4 py-3 text-sm text-gray-800 dark:text-gray-200">
            <p class="font-semibold text-gray-900 dark:text-gray-100">Last finished execution</p>
            <div id="data-engine-last-run-mini" class="mt-2 text-xs">
                @if (! empty($mon['last_run']))
                    @php $lr = $mon['last_run']; $stOk = ($lr['status'] ?? '') === 'success'; @endphp
                    <dl class="mt-1 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <div><dt class="inline text-gray-500 dark:text-gray-400">Run ID:</dt>
                            <dd class="inline font-mono font-semibold">#{{ (int) ($lr['run_id'] ?? 0) }}</dd></div>
                        <div><dt class="inline text-gray-500 dark:text-gray-400">Status:</dt>
                            <dd class="inline font-medium">{{ $stOk ? 'success' : 'failed' }}</dd></div>
                        <div><dt class="inline text-gray-500 dark:text-gray-400">Exit code:</dt>
                            <dd class="inline font-mono">{{ isset($lr['exit_code']) ? (int) $lr['exit_code'] : '—' }}</dd></div>
                        <div><dt class="inline text-gray-500 dark:text-gray-400">Duration:</dt>
                            <dd class="inline font-mono">
                                @if (isset($lr['duration_ms']))
                                    {{ number_format(((int) $lr['duration_ms']) / 1000, 2) }} s ({{ number_format((int) $lr['duration_ms']) }} ms)
                                @else
                                    —
                                @endif
                            </dd></div>
                        <div class="sm:col-span-2"><dt class="inline text-gray-500 dark:text-gray-400">Finished:</dt>
                            <dd class="inline font-mono">{{ $lr['finished_at'] ?? '—' }}</dd></div>
                    </dl>
                @endif
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Full stdout/stderr are appended to <code class="text-[11px]">storage/logs/data-engine.log</code>.</p>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Execution history <span class="text-xs font-normal text-gray-500">(latest 5)</span></h2>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">Includes successes and failures — auto-refreshes every 5s.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Run</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Mode</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Duration</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Started</th>
                        </tr>
                    </thead>
                    <tbody id="data-engine-recent-runs-body" class="divide-y divide-gray-200 dark:divide-gray-700">
                        @php $lrRuns = $mon['latest_runs'] ?? []; @endphp
                        @forelse ($lrRuns as $rr)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                                <td class="px-4 py-2 font-mono text-indigo-600 dark:text-indigo-400 font-semibold">#{{ (int) ($rr['run_id'] ?? 0) }}</td>
                                <td class="px-4 py-2">{{ $rr['mode'] ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $rr['status'] ?? '—' }}</td>
                                <td class="px-4 py-2 font-mono tabular-nums">
                                    @if (! empty($rr['duration_ms']))
                                        {{ number_format(((int) $rr['duration_ms']) / 1000, 2) }}s
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-gray-600 dark:text-gray-400 whitespace-nowrap text-xs">
                                    @if (! empty($rr['started_at']))
                                        {{ \Illuminate\Support\Carbon::parse($rr['started_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400 text-sm">No runs recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div id="data-engine-last-execution-failure-shell"
             class="{{ $histFailInit ? '' : 'hidden' }} rounded-xl border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-4">
            <p class="text-sm font-bold text-red-900 dark:text-red-100">Last execution failure</p>
            <p class="text-[11px] text-red-800/90 dark:text-red-200/90 mt-0.5">Historical — does not change <span class="font-medium">current engine state</span> in the header.</p>
            <div id="data-engine-last-execution-failure-body" class="mt-2 text-xs text-red-900 dark:text-red-100 whitespace-pre-wrap break-words font-mono">
                @if ($histFailInit && ! empty($mon['last_run']))
                    @php
                        $lr = $mon['last_run'];
                        $failTxt = 'Exit code: '.($lr['exit_code'] ?? '—');
                        if (! empty($lr['finished_at'])) {
                            $failTxt .= "\n\nFinished: ".$lr['finished_at'];
                        }
                        $failTxt .= "\n\n".($lr['stderr_preview'] ?? '(no stderr captured on run row)');
                    @endphp
                    {{ $failTxt }}
                @endif
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-amber-200 dark:border-amber-900/50 bg-amber-50/80 dark:bg-amber-950/20 px-4 py-3 text-sm text-amber-900 dark:text-amber-100 mb-6">
        <strong>Safety:</strong> Daily cron runs <em>analyze</em> only. Fix is never scheduled automatically.
    </div>

    @php
        $g = is_array($latestGuidance ?? null) ? $latestGuidance : null;
        $b = is_array($g['breakdown'] ?? null) ? $g['breakdown'] : [];
        $quality = isset($g['quality']) ? $g['quality'] : null;
        $issues = isset($g['issues']) ? (int) $g['issues'] : 0;
        $an = is_array($g['anomalies'] ?? null) ? $g['anomalies'] : [];
        $na = is_array($g['next_action'] ?? null) ? $g['next_action'] : null;
    @endphp
    @if ($g !== null && $latestRun)
        <div class="rounded-xl border border-indigo-200 dark:border-indigo-900/50 bg-indigo-50/50 dark:bg-indigo-950/20 px-5 py-5 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Latest run quick summary (simple)</h2>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                        <strong>Run ID:</strong> #{{ $latestRun->id }} (this is not profile ID) · {{ $latestRun->mode }} · {{ $latestRun->status }}
                        @if ($quality !== null) · Quality <strong>{{ (int) $quality }}</strong> @endif
                        · Total issues <strong>{{ $issues }}</strong>
                    </p>
                </div>
                <a href="{{ route('admin.data-engine.show', $latestRun) }}"
                   class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500">
                    Open full report
                </a>
            </div>

            @if (! empty($an))
                <div class="mt-4 rounded-lg border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-3 py-3 text-sm text-red-900 dark:text-red-100">
                    <p class="font-semibold">⚠️ Data anomaly detected</p>
                    <ul class="list-disc list-inside mt-1 space-y-0.5">
                        @foreach ($an as $msg)
                            @if (is_string($msg) && $msg !== '')
                                <li>{{ $msg }}</li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($na !== null)
                @php
                    $sev = (string) ($na['severity'] ?? 'mid');
                    $sevCls = $sev === 'high'
                        ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/30 text-red-900 dark:text-red-100'
                        : ($sev === 'good'
                            ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30 text-emerald-900 dark:text-emerald-100'
                            : 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30 text-amber-900 dark:text-amber-100');
                @endphp
                <div class="mt-4 rounded-lg border px-4 py-4 {{ $sevCls }}">
                    <p class="text-xs uppercase tracking-wide font-semibold">Auto suggested next action</p>
                    <p class="mt-1 text-sm font-semibold">{{ $na['title'] ?? 'Next action' }}</p>
                    <p class="mt-1 text-xs opacity-90">{{ $na['reason'] ?? '' }}</p>
                    @if (! empty($na['route']) && ! empty($na['button']))
                        <a href="{{ $na['route'] }}"
                           class="inline-flex items-center mt-3 rounded-md bg-white/80 dark:bg-gray-900/60 px-3 py-2 text-xs font-semibold border border-current/20 hover:bg-white dark:hover:bg-gray-900">
                            {{ $na['button'] }}
                        </a>
                    @endif
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
                <div class="rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-3">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Duplicate groups</p>
                    <p class="text-2xl font-bold mt-1">{{ (int) ($b['duplicate_groups'] ?? 0) }}</p>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">Same phone/email multiple users.</p>
                </div>
                <div class="rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-3">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Validation errors</p>
                    <p class="text-2xl font-bold mt-1">{{ (int) ($b['validation_errors'] ?? 0) }}</p>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                        Empty phone: {{ (int) ($g['empty_phone_count'] ?? 0) }} · Missing name: {{ (int) ($g['missing_name_count'] ?? 0) }}
                    </p>
                </div>
                <div class="rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-3">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Mismatch rows</p>
                    <p class="text-2xl font-bold mt-1">{{ (int) ($b['mismatch_rows'] ?? 0) }}</p>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">Profile city vs address city mismatch.</p>
                </div>
                <div class="rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-3">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Schema issues</p>
                    <p class="text-2xl font-bold mt-1">{{ (int) ($b['schema_issues'] ?? 0) }}</p>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">Columns with very high null ratio.</p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('admin.duplicate-phones.index') }}"
                   class="inline-flex items-center rounded-md bg-white dark:bg-gray-800 px-3 py-2 text-xs font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                    Resolve duplicate phones
                </a>
                <a href="{{ route('admin.monitoring.index') }}"
                   class="inline-flex items-center rounded-md bg-white dark:bg-gray-800 px-3 py-2 text-xs font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                    Open monitoring / logs
                </a>
                <a href="{{ route('admin.data-engine.show', $latestRun) }}"
                   class="inline-flex items-center rounded-md bg-white dark:bg-gray-800 px-3 py-2 text-xs font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                    See step-by-step suggestions
                </a>
            </div>
        </div>
    @endif
        </div>
    </details>

    <details id="de-section-history" open class="mb-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/30">
        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700">History</summary>
        <div class="p-4">

    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Run ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mode</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quality</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg profile</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Issues</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fixed</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($runs as $run)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                            <td class="px-4 py-3 text-sm font-mono">
                                <a href="{{ route('admin.data-engine.show', $run) }}" class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 font-semibold">Run #{{ $run->id }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $run->mode === 'fix' ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' : 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100' }}">
                                    {{ $run->mode }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @php $st = $run->status; @endphp
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                    @if ($st === 'completed') bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100
                                    @elseif ($st === 'failed') bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100
                                    @elseif ($st === 'running') bg-yellow-100 text-yellow-900 dark:bg-yellow-900/40 dark:text-yellow-100
                                    @else bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-100 @endif">
                                    {{ $st }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm font-bold tabular-nums">
                                @if ($run->quality_score !== null)
                                    @php $q = (int) $run->quality_score; @endphp
                                    <span class="@if ($q > 80) text-emerald-600 dark:text-emerald-400 @elseif ($q >= 50) text-amber-600 dark:text-amber-400 @else text-red-600 dark:text-red-400 @endif">{{ $q }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm font-bold tabular-nums">
                                @php $ap = is_array($run->profile_metrics) ? ($run->profile_metrics['average_completeness_score'] ?? null) : null; @endphp
                                @if ($ap !== null && is_numeric($ap))
                                    @php $apf = (float) $ap; @endphp
                                    <span class="@if ($apf > 70) text-teal-600 dark:text-teal-400 @elseif ($apf >= 40) text-amber-600 dark:text-amber-400 @else text-rose-600 dark:text-rose-400 @endif">{{ number_format($apf, 1) }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ number_format((int) $run->total_issues) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                {{ $run->total_fixed !== null ? number_format((int) $run->total_fixed) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $run->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No runs yet. Run analyze to generate the first report.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($runs->hasPages())
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                {{ $runs->links() }}
            </div>
        @endif
    </div>
        </div>
    </details>

    <div id="data-engine-loading" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/25 dark:bg-black/50" aria-live="polite" aria-busy="false">
        <div class="rounded-xl bg-white dark:bg-gray-800 px-6 py-5 shadow-xl border border-gray-200 dark:border-gray-700 flex items-center gap-4">
            <svg class="animate-spin h-9 w-9 text-indigo-600 dark:text-indigo-400 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <div>
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Running data engine…</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Wait for the page to reload; do not close this tab.</p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('data-engine-loading');
    document.querySelectorAll('.js-data-engine-run-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.getAttribute('data-confirm-fix') === '1') {
                if (!confirm('FIX mode runs transactional UPDATE rules on the database. Continue?')) {
                    e.preventDefault();
                    return;
                }
            }
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
<script>
(function () {
    var root = document.getElementById('data-engine-monitor');
    if (!root || !root.dataset.statusUrl) {
        return;
    }
    var url = root.dataset.statusUrl;
    var ms = parseInt(root.dataset.pollIntervalMs || '5000', 10) || 5000;

    var BADGE_BASE =
        'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold cursor-default select-none ';
    var BADGE_CLASS = {
        disabled:
            BADGE_BASE +
            'border-rose-400 bg-rose-50 text-rose-950 dark:border-rose-600 dark:bg-rose-950/45 dark:text-rose-100',
        off: BADGE_BASE + 'border-rose-400 bg-rose-50 text-rose-950 dark:border-rose-600 dark:bg-rose-950/45 dark:text-rose-100',
        online:
            BADGE_BASE +
            'border-emerald-400 bg-emerald-50 text-emerald-950 dark:border-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-100',
        online_warnings:
            BADGE_BASE +
            'border-amber-400 bg-amber-50 text-amber-950 dark:border-amber-600 dark:bg-amber-950/40 dark:text-amber-100',
        analyze_running:
            BADGE_BASE +
            'border-sky-400 bg-sky-50 text-sky-950 dark:border-sky-600 dark:bg-sky-950/40 dark:text-sky-100',
        fix_running:
            BADGE_BASE +
            'border-violet-400 bg-violet-50 text-violet-950 dark:border-violet-600 dark:bg-violet-950/40 dark:text-violet-100',
        critical_failure:
            BADGE_BASE +
            'border-zinc-500 bg-zinc-100 text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100',
        failed:
            BADGE_BASE +
            'border-zinc-500 bg-zinc-100 text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100',
    };
    var BADGE_LABEL = {
        disabled: 'Disabled',
        off: 'Disabled',
        online: 'Online',
        online_warnings: 'Online · optional module warnings',
        analyze_running: 'Analyze running',
        fix_running: 'Fix running',
        critical_failure: 'Critical · no completed run yet',
        failed: 'Critical · no completed run yet',
    };

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function formatHMS(msVal) {
        var ms = Math.max(0, parseInt(msVal, 10) || 0);
        var totalSec = Math.floor(ms / 1000);
        var h = Math.floor(totalSec / 3600);
        var m = Math.floor((totalSec % 3600) / 60);
        var sec = totalSec % 60;
        return [h, m, sec].map(function (n) {
            return String(n).padStart(2, '0');
        }).join(':');
    }

    function formatStarted(iso) {
        if (!iso) {
            return '—';
        }
        try {
            var d = new Date(iso);
            return d.toLocaleString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
        } catch (e) {
            return iso;
        }
    }

    function render(j) {
        var hb = document.getElementById('data-engine-health-badge');
        if (hb && j.health && j.health.state) {
            var st = j.health.state;
            if (st === 'off') {
                st = 'disabled';
            }
            if (st === 'failed') {
                st = 'critical_failure';
            }
            hb.setAttribute('data-health-state', j.health.state);
            hb.textContent = BADGE_LABEL[st] || BADGE_LABEL.online;
            hb.className = BADGE_CLASS[st] || BADGE_CLASS.online;
            hb.setAttribute('role', 'status');
        }

        var qb = document.getElementById('data-engine-queue-badge');
        if (qb) {
            if (j.queue_mode) {
                qb.classList.remove('hidden');
            } else {
                qb.classList.add('hidden');
            }
        }

        var lb = document.getElementById('data-engine-lock-badge');
        if (lb) {
            if (j.lock_active) {
                lb.classList.remove('hidden');
            } else {
                lb.classList.add('hidden');
            }
        }

        var rb = document.getElementById('data-engine-running-banner');
        if (rb) {
            if (j.running) {
                rb.classList.remove('hidden');
            } else {
                rb.classList.add('hidden');
            }
        }

        var mwShell = document.getElementById('data-engine-module-warnings-shell');
        var mwList = document.getElementById('data-engine-module-warnings-list');
        if (mwShell && mwList) {
            var mwa = Array.isArray(j.module_warnings) ? j.module_warnings : [];
            if (mwa.length === 0) {
                mwShell.classList.add('hidden');
                mwList.innerHTML = '';
            } else {
                mwShell.classList.remove('hidden');
                mwList.innerHTML = '';
                mwa.forEach(function (row) {
                    if (!row || typeof row.module !== 'string' || typeof row.warning !== 'string') {
                        return;
                    }
                    var li = document.createElement('li');
                    var mod = document.createElement('span');
                    mod.className = 'font-semibold';
                    mod.textContent = row.module;
                    li.appendChild(mod);
                    li.appendChild(document.createTextNode(': ' + row.warning));
                    mwList.appendChild(li);
                });
            }
        }

        var curShell = document.getElementById('data-engine-panel-current-shell');
        var curEl = document.getElementById('data-engine-panel-current');
        if (curShell && curEl) {
            if (j.running && j.current_run) {
                curShell.classList.remove('hidden');
                var cr = j.current_run;
                curEl.innerHTML =
                    '<div><span class="text-amber-800 dark:text-amber-200 font-sans font-semibold">Mode:</span> ' +
                    esc(cr.mode) +
                    '</div>' +
                    '<div><span class="text-amber-800 dark:text-amber-200 font-sans font-semibold">Started:</span> ' +
                    esc(formatStarted(cr.started_at)) +
                    '</div>' +
                    '<div><span class="text-amber-800 dark:text-amber-200 font-sans font-semibold">Elapsed:</span> ' +
                    esc(formatHMS(cr.elapsed_ms)) +
                    '</div>';
            } else {
                curShell.classList.add('hidden');
                curEl.innerHTML = '';
            }
        }

        var histFailShell = document.getElementById('data-engine-last-execution-failure-shell');
        var histFailBody = document.getElementById('data-engine-last-execution-failure-body');
        var showHistFail = j.last_run && j.last_run.status === 'failed';
        if (histFailShell && histFailBody) {
            if (showHistFail && j.last_run) {
                histFailShell.classList.remove('hidden');
                var lrf = j.last_run;
                var chunkf = 'Exit code: ' + (lrf.exit_code != null ? lrf.exit_code : '—');
                if (lrf.finished_at) {
                    chunkf += '\n\nFinished: ' + lrf.finished_at;
                }
                chunkf += '\n\n' + (lrf.stderr_preview || '(no stderr captured on run row)');
                histFailBody.textContent = chunkf;
            } else {
                histFailShell.classList.add('hidden');
                histFailBody.textContent = '';
            }
        }

        var lrShell = document.getElementById('data-engine-last-run-shell');
        var lrMini = document.getElementById('data-engine-last-run-mini');
        if (lrShell && lrMini) {
            if (j.last_run) {
                lrShell.classList.remove('hidden');
                var x = j.last_run;
                var st = x.status === 'success' ? 'success' : 'failed';
                var dur =
                    x.duration_ms != null
                        ? (parseInt(x.duration_ms, 10) / 1000).toFixed(2) + ' s (' + x.duration_ms + ' ms)'
                        : '—';
                lrMini.innerHTML =
                    '<dl class="mt-1 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-xs">' +
                    '<div><dt class="inline text-gray-500 dark:text-gray-400">Run ID:</dt> <dd class="inline font-mono font-semibold">#' +
                    esc(String(x.run_id != null ? x.run_id : '—')) +
                    '</dd></div>' +
                    '<div><dt class="inline text-gray-500 dark:text-gray-400">Status:</dt> <dd class="inline font-medium">' +
                    esc(st) +
                    '</dd></div>' +
                    '<div><dt class="inline text-gray-500 dark:text-gray-400">Exit code:</dt> <dd class="inline font-mono">' +
                    esc(String(x.exit_code != null ? x.exit_code : '—')) +
                    '</dd></div>' +
                    '<div><dt class="inline text-gray-500 dark:text-gray-400">Duration:</dt> <dd class="inline font-mono">' +
                    esc(dur) +
                    '</dd></div>' +
                    '<div class="sm:col-span-2"><dt class="inline text-gray-500 dark:text-gray-400">Finished:</dt> <dd class="inline font-mono">' +
                    esc(x.finished_at || '—') +
                    '</dd></div></dl>';
            } else {
                lrShell.classList.add('hidden');
                lrMini.innerHTML = '';
            }
        }

        var tbody = document.getElementById('data-engine-recent-runs-body');
        if (tbody && Array.isArray(j.latest_runs)) {
            tbody.innerHTML = '';
            if (j.latest_runs.length === 0) {
                var tr0 = document.createElement('tr');
                tr0.innerHTML =
                    '<td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400 text-sm">No runs recorded yet.</td>';
                tbody.appendChild(tr0);
            } else {
                j.latest_runs.forEach(function (row) {
                    var tr = document.createElement('tr');
                    tr.className = 'hover:bg-gray-50 dark:hover:bg-gray-900/30';
                    var dur =
                        row.duration_ms != null
                            ? (parseInt(row.duration_ms, 10) / 1000).toFixed(2) + 's'
                            : '—';
                    tr.innerHTML =
                        '<td class="px-4 py-2 font-mono text-indigo-600 dark:text-indigo-400 font-semibold">#' +
                        esc(String(row.run_id)) +
                        '</td>' +
                        '<td class="px-4 py-2">' +
                        esc(row.mode) +
                        '</td>' +
                        '<td class="px-4 py-2">' +
                        esc(row.status) +
                        '</td>' +
                        '<td class="px-4 py-2 font-mono tabular-nums">' +
                        esc(dur) +
                        '</td>' +
                        '<td class="px-4 py-2 text-gray-600 dark:text-gray-400 whitespace-nowrap text-xs">' +
                        esc(row.started_at || '—') +
                        '</td>';
                    tbody.appendChild(tr);
                });
            }
        }

        var disabled = !j.powered || j.running;
        document.querySelectorAll('.js-data-engine-submit').forEach(function (btn) {
            btn.disabled = disabled;
        });
    }

    function tick() {
        fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('bad response');
                }
                return r.json();
            })
            .then(render)
            .catch(function () {});
    }

    tick();
    setInterval(tick, ms);
})();
</script>
@endsection
