@extends('layouts.admin')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <h1 class="text-xl font-semibold mb-4">Issue Center</h1>
    <form method="get" class="flex gap-2 mb-4">
        <input name="q" value="{{ $q }}" placeholder="Search issues" class="rounded border px-3 py-2 text-sm w-80">
        <select name="severity" class="rounded border px-3 py-2 text-sm">
            <option value="">All severity</option>
            @foreach (['low','medium','high','critical'] as $sev)
                <option value="{{ $sev }}" @selected($severity === $sev)>{{ strtoupper($sev) }}</option>
            @endforeach
        </select>
        <button class="rounded bg-indigo-700 text-white px-3 py-2 text-sm">Filter</button>
    </form>
    <form method="get" action="{{ route('admin.data-engine.index') }}" onsubmit="this.action='/admin/data-engine/profiles/'+(this.profile_id.value||207)" class="mb-4 inline-flex items-center gap-2 text-sm" title="Enter matrimony profile ID (matrimony_profiles.id), not user ID">
        <label for="de-issues-profile-id" class="text-xs text-gray-700 dark:text-gray-300">Matrimony profile&nbsp;ID</label>
        <input id="de-issues-profile-id" type="number" name="profile_id" min="1" value="{{ is_numeric($q ?? null) ? (int) $q : 207 }}" placeholder="matrimony profile id" class="rounded border px-2 py-1.5 w-28" />
        <button type="submit" class="rounded border border-indigo-300 px-3 py-1.5 text-indigo-800">Open governance profile</button>
    </form>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4 text-xs">
        <div class="rounded border bg-white p-3">Fix success rate: <span class="font-mono font-semibold">{{ $trends['fix_success_rate'] ?? 0 }}%</span></div>
        <div class="rounded border bg-white p-3">Recurring failures: <span class="font-mono font-semibold">{{ $trends['recurring_failures'] ?? 0 }}</span></div>
        <div class="rounded border bg-white p-3">Rollback frequency: <span class="font-mono font-semibold">{{ $trends['rollback_frequency'] ?? 0 }}</span></div>
        <div class="rounded border bg-white p-3">Worsening modules: <span class="font-mono font-semibold">{{ implode(', ', $trends['worsening_modules'] ?? []) ?: '—' }}</span></div>
    </div>
    <div class="rounded border bg-gray-50 px-3 py-2 text-xs mb-4 text-gray-700 dark:text-gray-300">
        <strong>Quick guide:</strong>
        <span class="ml-2">“API issue” means the app response does not match saved profile data.</span>
        <span class="ml-2">“Needs review” flags items that may affect members or trust.</span>
        <span class="ml-2">Rollback tells you whether a safe undo path exists before running fixes.</span>
        <span class="block mt-1 text-gray-600 dark:text-gray-400">मार्गदर्शन: API समस्या = जतन आणि अ‍ॅप माहिती जुळत नाही · तपासणी आवश्यक = धोरणात्मक पुनर्क्षेत्र.</span>
    </div>
    <div class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs mb-4 text-emerald-900">
        <strong>What to do first (safe order):</strong>
        <span class="ml-2">1) Open issue details</span>
        <span class="ml-2">2) Run Preview Fix</span>
        <span class="ml-2">3) Run Dry Run</span>
        <span class="ml-2">4) Execute Fix only if risk is acceptable</span>
        <span class="ml-2">5) Validate and re-open governance profile</span>
        <span class="block mt-1 text-emerald-800">चुकल्यास त्वरित Rollback route वापरा.</span>
    </div>
    <div class="space-y-3">
        @forelse ($issues as $issue)
            <div class="rounded border bg-white dark:bg-gray-900 dark:border-gray-700 p-4 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold">{{ $issue['issue'] ?? 'Issue' }}</span>
                    <span class="inline-flex rounded-full border px-2 py-0.5 text-xs uppercase">{{ $issue['severity'] ?? 'low' }}</span>
                    @if (!empty($issue['critical_badge']))
                        <span class="inline-flex rounded-full border border-rose-500 bg-rose-50 px-2 py-0.5 text-xs uppercase text-rose-700">Critical</span>
                    @endif
                    <span class="text-xs text-gray-500">Affected: {{ (int) ($issue['affected_count'] ?? 0) }}</span>
                    <span class="text-xs text-gray-500">Frequency: {{ (int) ($issue['recurring_frequency'] ?? 0) }}</span>
                    <span class="text-xs {{ !empty($issue['auto_fix_available']) ? 'text-emerald-700' : 'text-gray-500' }}">Auto-fix {{ !empty($issue['auto_fix_available']) ? 'available' : 'manual' }}</span>
                    <span class="text-xs {{ !empty($issue['rollback_available']) ? 'text-violet-700' : 'text-gray-500' }}">Rollback {{ !empty($issue['rollback_available']) ? 'available' : 'not available' }}</span>
                </div>
                <p class="mt-1 text-gray-700 dark:text-gray-300"><strong>Business impact:</strong> {{ $issue['business_impact'] ?? '—' }}</p>
                <p class="mt-1 text-gray-700 dark:text-gray-300"><strong>Estimated impact:</strong> {{ $issue['estimated_business_impact'] ?? 'Low' }}</p>
                <p class="mt-1 text-gray-700 dark:text-gray-300"><strong>Affected modules:</strong> {{ implode(', ', $issue['affected_modules'] ?? []) }}</p>
                <p class="mt-1 text-gray-700 dark:text-gray-300"><strong>Cross impact:</strong> {{ implode(', ', $issue['cross_module_impact'] ?? []) }}</p>
                <p class="mt-1 text-gray-700 dark:text-gray-300"><strong>Recommended next action:</strong> {{ $issue['recommended_next_action'] ?? 'Run preview first' }}</p>
                <p class="mt-1 text-gray-700 dark:text-gray-300"><strong>Root cause:</strong> {{ $issue['root_cause'] ?? '—' }}</p>
                <p class="mt-1 text-gray-700 dark:text-gray-300"><strong>Suggested fix:</strong> {{ $issue['suggested_fix'] ?? '—' }}</p>
                <details class="mt-2 rounded border border-indigo-200 bg-indigo-50 px-2 py-2 text-xs">
                    <summary class="cursor-pointer font-semibold">Simulation and safety details</summary>
                    <div class="mt-2">
                        <strong>Simulation:</strong>
                        Rows {{ (int) ($issue['simulation']['affected_rows'] ?? 0) }},
                        Changes {{ (int) ($issue['simulation']['estimated_changes'] ?? 0) }},
                        Confidence {{ (float) ($issue['simulation']['confidence_score'] ?? 0) }},
                        Risk {{ strtoupper((string) ($issue['simulation']['destructive_risk'] ?? 'low')) }},
                        Rollback {{ !empty($issue['simulation']['rollback_availability']) ? 'YES' : 'NO' }},
                        Health +{{ (int) ($issue['simulation']['expected_health_improvement'] ?? 0) }}
                    </div>
                </details>
                <details class="mt-3 rounded border border-gray-200 px-2 py-2 text-xs">
                    <summary class="cursor-pointer font-semibold">Fix actions</summary>
                    <div class="mt-2 flex flex-wrap gap-2">
                    @if(is_numeric($q ?? null))
                        <a href="{{ route('admin.data-engine.profiles.show', ['profileId' => (int) $q]) }}" class="rounded border border-indigo-300 px-2 py-1 text-xs">Open governance profile</a>
                    @endif
                    <form method="post" action="{{ route('admin.data-engine.governance-action') }}">@csrf
                        <input type="hidden" name="action" value="preview_fix">
                        <input type="hidden" name="recipe" value="{{ $issue['recommended_recipe'] ?? 'stale_indexes' }}">
                        <button class="rounded border px-2 py-1 text-xs">Preview Fix</button>
                    </form>
                    <form method="post" action="{{ route('admin.data-engine.governance-action') }}">@csrf
                        <input type="hidden" name="action" value="run_dry_run">
                        <input type="hidden" name="recipe" value="{{ $issue['recommended_recipe'] ?? 'stale_indexes' }}">
                        <button class="rounded border px-2 py-1 text-xs">Run Dry Run</button>
                    </form>
                    <form method="post" action="{{ route('admin.data-engine.governance-action') }}">@csrf
                        <input type="hidden" name="action" value="execute_fix">
                        <input type="hidden" name="recipe" value="{{ $issue['recommended_recipe'] ?? 'stale_indexes' }}">
                        <button class="rounded bg-amber-600 text-white px-2 py-1 text-xs" onclick="return confirm('Affected rows: {{ (int) ($issue['simulation']['affected_rows'] ?? 0) }}\nEstimated changes: {{ (int) ($issue['simulation']['estimated_changes'] ?? 0) }}\nConfidence: {{ (float) ($issue['simulation']['confidence_score'] ?? 0) }}\nDestructive risk: {{ strtoupper((string) ($issue['simulation']['destructive_risk'] ?? 'low')) }}\nRollback availability: {{ !empty($issue['simulation']['rollback_availability']) ? 'YES' : 'NO' }}\nExpected health improvement: +{{ (int) ($issue['simulation']['expected_health_improvement'] ?? 0) }}\n\nExecute fix?')">Execute Fix</button>
                    </form>
                    <form method="post" action="{{ route('admin.data-engine.governance-action') }}">@csrf
                        <input type="hidden" name="action" value="validate_fix">
                        <input type="hidden" name="recipe" value="{{ $issue['recommended_recipe'] ?? 'stale_indexes' }}">
                        <button class="rounded border px-2 py-1 text-xs">Validate</button>
                    </form>
                    <form method="post" action="{{ route('admin.data-engine.governance-action') }}">@csrf
                        <input type="hidden" name="action" value="auto_self_heal">
                        <input type="hidden" name="recipe" value="{{ $issue['recommended_recipe'] ?? 'stale_indexes' }}">
                        <input type="hidden" name="min_confidence" value="0.70">
                        <input type="hidden" name="max_risk" value="medium">
                        <button class="rounded bg-indigo-700 text-white px-2 py-1 text-xs">Self-Heal (gated)</button>
                    </form>
                    </div>
                </details>
            </div>
        @empty
            <div class="rounded border bg-white p-4 text-sm">No issues found.</div>
        @endforelse
    </div>
</div>
@endsection

