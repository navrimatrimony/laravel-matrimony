@extends('layouts.admin')

@php
    $gv = $governanceView ?? [];
    $issueCards = $gv['issue_cards'] ?? [];
    $repeaterTables = $gv['repeater_tables'] ?? [];
    $lineageVisuals = $gv['lineage_visuals'] ?? [];
    $apiTab = $gv['api_tab'] ?? ['ok' => true, 'lines' => [], 'nested_cards' => [], 'checked_at' => ''];
    $repeaterAlerts = $gv['repeater_structure_alerts'] ?? [];
    $healthCards = $gv['health_cards'] ?? [];
    $timelineRows = $gv['issue_timeline'] ?? [];
    $silentLossRows = $gv['silent_loss_rows'] ?? [];
    $overviewCounters = $gv['overview_counters'] ?? [];
    $hasVerificationEvidence = ((int) ($overviewCounters['total_governed_fields'] ?? 0)) > 0;
    $repeaterPanels = $gv['repeater_panels'] ?? [];
    $apiProfile = is_array($snapshot['api']['profile'] ?? null) ? $snapshot['api']['profile'] : [];
    $checkedIsoInitial = $comparison['generated_at'] ?? '';
    $issueCenterUrl = $issueCenterUrl ?? route('admin.data-engine.issues', ['q' => $profileId]);
    $workflowsUrl = $workflowsUrl ?? route('admin.data-engine.workflows');
    $auditUrl = $auditUrl ?? route('admin.data-engine.audit');
    $rollbackUrl = $rollbackUrl ?? route('admin.data-engine.rollback');
@endphp

@section('content')
<div
    class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
    x-data="{
        tab: 'overview',
        badges: @js(array_values($initialBadges ?? [])),
        flash: '',
        flashOk: true,
        headline: '',
        resultSummary: null,
        diagOpen: false,
        diagText: '',
        diagLoading: false,
        issueCount: {{ count($issueCards) }},
        lastCheckedIso: @js($checkedIsoInitial !== '' ? $checkedIsoInitial : null),
        statusUrl: @js($statusUrl ?? ''),
        actionUrl: @js($actionUrl ?? ''),
        diagnosticsUrl: @js($diagnosticsUrl ?? ''),
        canOperate: @js($canOperateGovernance ?? false),
        csrfToken: @js(csrf_token()),
        pollTimer: null,
        actionLoading: false,
        profileId: {{ (int) $profileId }},
        badgeClass(state) {
            if (state === 'ok') return 'bg-emerald-100 text-emerald-900 border-emerald-200';
            if (state === 'warn') return 'bg-amber-100 text-amber-950 border-amber-200';
            return 'bg-rose-100 text-rose-900 border-rose-200';
        },
        sevClass(sev) {
            if (sev === 'critical') return 'bg-rose-900 text-white';
            if (sev === 'high') return 'bg-rose-600 text-white';
            if (sev === 'medium') return 'bg-amber-500 text-white';
            return 'bg-slate-500 text-white';
        },
        sevLabel(sev) {
            if (sev === 'critical') return 'Critical';
            if (sev === 'high') return 'Serious';
            if (sev === 'medium') return 'Warning';
            return 'Note';
        },
        relativeChecked() {
            if (!this.lastCheckedIso) return '—';
            const t = Date.parse(this.lastCheckedIso);
            if (Number.isNaN(t)) return '—';
            const s = Math.max(0, Math.floor((Date.now() - t) / 1000));
            if (s < 60) return 'just now';
            const m = Math.floor(s / 60);
            if (m < 60) return m + ' minute' + (m === 1 ? '' : 's') + ' ago';
            const h = Math.floor(m / 60);
            if (h < 48) return h + ' hour' + (h === 1 ? '' : 's') + ' ago';
            const d = Math.floor(h / 24);
            return d + ' day' + (d === 1 ? '' : 's') + ' ago';
        },
        relativeCheckedMr() {
            const en = this.relativeChecked();
            if (en === '—') return '—';
            if (en === 'just now') return 'आत्ताच';
            const mo = en.match(/^(\d+) minute/);
            if (mo) return mo[1] + ' मिनिटांपूर्वी';
            const ho = en.match(/^(\d+) hour/);
            if (ho) return ho[1] + ' तासांपूर्वी';
            const da = en.match(/^(\d+) day/);
            if (da) return da[1] + ' दिवसांपूर्वी';
            return en;
        },
        async poll() {
            if (!this.statusUrl) return;
            try {
                const r = await fetch(this.statusUrl, { headers: { Accept: 'application/json' } });
                const j = await r.json();
                if (j.badges) this.badges = j.badges;
                if (typeof j.issue_count === 'number') this.issueCount = j.issue_count;
                if (j.checked_at_iso) this.lastCheckedIso = j.checked_at_iso;
            } catch (e) {}
        },
        initPoll() {
            this.pollTimer = setInterval(() => this.poll(), 20000);
        },
        formatArtifactSummary(as) {
            if (!as || typeof as !== 'object') return '';
            const parts = [];
            if (as.rendered_field_count != null) parts.push('Captured details: ' + as.rendered_field_count);
            if (as.comparison_field_count != null) parts.push('Compared items: ' + as.comparison_field_count);
            if (as.profile_sections_captured != null) parts.push('Profile sections: ' + as.profile_sections_captured);
            if (as.repeater_cells_checked != null) parts.push('Section cells checked: ' + as.repeater_cells_checked);
            return parts.join(' · ');
        },
        async runAction(name) {
            if (!this.canOperate || !this.actionUrl) return;
            this.actionLoading = true;
            this.flash = '';
            this.headline = '';
            this.resultSummary = null;
            const token = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || this.csrfToken;
            try {
                const r = await fetch(this.actionUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ action: name }),
                });
                if (r.status === 419) {
                    throw new Error('Session expired. Refresh this page once and run the action again.');
                }
                const j = await r.json();
                this.flashOk = !!j.ok;
                this.flash = j.message || (j.ok ? 'Done.' : 'Something went wrong.');
                this.headline = j.headline || '';
                this.resultSummary = j.result || null;
                await this.poll();
            } catch (e) {
                this.flashOk = false;
                this.flash = e.message || 'Request failed.';
                this.headline = 'Action failed';
                this.resultSummary = { success: false, error: this.flash };
            }
            this.actionLoading = false;
        },
        reloadDiagnostics() {
            this.diagText = '';
            this.loadDiagnostics(true);
        },
        async loadDiagnostics(force = false) {
            if (!this.diagnosticsUrl) return;
            if (!force && this.diagText) return;
            this.diagLoading = true;
            try {
                const r = await fetch(this.diagnosticsUrl, { headers: { Accept: 'application/json' } });
                const j = await r.json();
                this.diagText = JSON.stringify(j, null, 2);
            } catch (e) {
                this.diagText = String(e);
            }
            this.diagLoading = false;
        },
    }"
    x-init="initPoll(); poll()"
>
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Profile checks — Matrimony profile&nbsp;#{{ $profileId }}</h1>
            <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">Plain-language view of saved data, the app API, and the public profile page.</p>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">ID shown above is <strong>matrimony_profiles.id</strong> (SSOT) — not the user ID.</p>
            <p class="text-xs text-gray-500 mt-1" x-show="lastCheckedIso">
                Last checked: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="relativeChecked()"></span>
                <span class="text-gray-400"> · </span>
                <span class="text-gray-600 dark:text-gray-400" x-text="relativeCheckedMr()"></span>
            </p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs items-start">
            <template x-for="meta in badges" :key="meta.key">
                <span class="inline-flex items-center gap-1 rounded-full border px-3 py-1 font-medium" :class="badgeClass(meta.state)" x-text="meta.label"></span>
            </template>
        </div>
    </div>

    <aside class="mt-4 rounded-xl border border-indigo-100 dark:border-indigo-900/50 bg-indigo-50/70 dark:bg-indigo-950/25 px-4 py-3 text-sm">
        <p class="font-semibold text-indigo-950 dark:text-indigo-100">How to use this page</p>
        <ol class="list-decimal list-inside mt-2 space-y-1 text-indigo-900 dark:text-indigo-100/95">
            <li>Review serious (red) issues first.</li>
            <li>Open the public profile or full form if you need to verify what members see.</li>
            <li>Use the action buttons to rebuild the snapshot or run checks again.</li>
            <li>Watch the status badges update — no need to reload the whole page.</li>
            <li>Open Developer diagnostics only if you need raw technical detail.</li>
        </ol>
        <p class="text-xs text-indigo-900/85 dark:text-indigo-200/90 mt-2">प्रथम गंभीर (लाल) समस्या बघा · नंतर स्नॅपशॉट/तपासणी चालवा · स्थिती बॅजने स्वयं अद्यतने · तांत्रिक तपशील फक्त Developer खाली.</p>
    </aside>

    <section class="mt-4 rounded-xl border border-emerald-200 dark:border-emerald-900 bg-emerald-50/60 dark:bg-emerald-950/20 px-4 py-3 text-sm">
        <h2 class="font-semibold text-emerald-950 dark:text-emerald-100">Fix and reverse workflow (safe mode)</h2>
        <ol class="list-decimal list-inside mt-2 space-y-1 text-emerald-900 dark:text-emerald-100/95">
            <li>Run <strong>Rebuild snapshot</strong> and <strong>Re-run comparison</strong> to confirm live issue.</li>
            <li>Open <strong>Issue center</strong> for this profile and use <strong>Preview Fix</strong> / <strong>Dry Run</strong> first.</li>
            <li>If you execute a fix and result looks wrong, open <strong>Rollback Center</strong> and restore from manifest.</li>
            <li>Use <strong>Workflow Tracking</strong> and <strong>Audit</strong> to verify state/history before and after.</li>
        </ol>
        <p class="text-xs text-emerald-900/80 dark:text-emerald-200/90 mt-2">चूक झाली तर Rollback Center मधून manifest restore करून बदल मागे नेता येतात.</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <a href="{{ $issueCenterUrl }}" class="inline-flex rounded border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold">Open issue center (fix)</a>
            <a href="{{ $workflowsUrl }}" class="inline-flex rounded border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold">Open workflow tracking</a>
            <a href="{{ $auditUrl }}" class="inline-flex rounded border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold">Open audit trail</a>
            <a href="{{ $rollbackUrl }}" class="inline-flex rounded border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800">Open rollback center</a>
        </div>
    </section>

    <div x-show="actionLoading" class="mt-3 text-sm text-indigo-800 dark:text-indigo-200 font-medium" x-cloak>Working… please wait (this can take up to a few minutes).</div>

    <div x-show="flash || headline" class="mt-3 rounded-lg border px-4 py-3 text-sm" :class="flashOk ? 'border-emerald-200 bg-emerald-50/90 dark:border-emerald-800 dark:bg-emerald-950/30 text-emerald-950 dark:text-emerald-100' : 'border-rose-200 bg-rose-50/90 dark:border-rose-900 dark:bg-rose-950/30 text-rose-950 dark:text-rose-100'">
        <p class="font-semibold" x-show="headline" x-text="headline"></p>
        <p x-show="flash" x-text="flash"></p>
        <p x-show="!flashOk && (flash || headline)" class="text-xs mt-2 opacity-90">Try refresh once and run action again. If still failing, open workflow tracking and rollback center.</p>
        <template x-if="resultSummary && resultSummary.artifact_summary">
            <p class="text-xs mt-2 opacity-90" x-text="'Matrimony profile #' + profileId + ' — ' + formatArtifactSummary(resultSummary.artifact_summary)"></p>
        </template>
        <template x-if="resultSummary && resultSummary.api_check && !resultSummary.api_check.passed">
            <div class="mt-2 text-xs">
                <p class="font-semibold">Missing in app API:</p>
                <ul class="list-disc list-inside mt-1">
                    <template x-for="(lbl, idx) in (resultSummary.api_check.missing_labels_en || [])" :key="idx">
                        <li x-text="lbl"></li>
                    </template>
                </ul>
                <p class="mt-2">Try <strong>Rebuild snapshot</strong> after fixing the API, then <strong>Check API parity</strong> again.</p>
            </div>
        </template>
    </div>

    <div class="mt-4 flex flex-wrap gap-2 text-sm items-center">
        <div class="w-full text-xs text-gray-600 dark:text-gray-300 mb-1">
            <span class="inline-flex rounded border border-indigo-300 px-2 py-0.5 mr-1">Profile-level fix: Rebuild snapshot / Re-run comparison / API parity / Section check</span>
            <span class="inline-flex rounded border border-amber-300 px-2 py-0.5">Platform refresh: Refresh coverage summary</span>
        </div>
        @if($canOperateGovernance ?? false)
            <button type="button" :disabled="actionLoading" @click="runAction('rebuild_snapshot')" class="rounded-lg bg-indigo-600 text-white px-4 py-2 shadow hover:bg-indigo-500 disabled:opacity-50">Rebuild snapshot</button>
            <button type="button" :disabled="actionLoading" @click="runAction('rerun_comparison')" class="rounded-lg bg-indigo-600 text-white px-4 py-2 shadow hover:bg-indigo-500 disabled:opacity-50">Re-run comparison</button>
            <button type="button" :disabled="actionLoading" @click="runAction('validate_api_parity')" class="rounded-lg border border-indigo-300 bg-white dark:bg-gray-900 px-4 py-2 text-indigo-800 dark:text-indigo-200 disabled:opacity-50">Check API parity</button>
            <button type="button" :disabled="actionLoading" @click="runAction('refresh_coverage')" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-2 disabled:opacity-50">Refresh coverage summary</button>
            <button type="button" :disabled="actionLoading" @click="runAction('rerun_repeater_diff')" class="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-2 disabled:opacity-50">Re-run section check</button>
        @else
            <p class="text-xs text-gray-500">Ask a data admin to run snapshot or comparison actions.</p>
        @endif
        <a href="{{ $wizardUrl ?? url('/matrimony/profile/wizard/full?all=1') }}" class="rounded-lg border px-4 py-2">Open full form (wizard)</a>
        @if($publicProfileExists ?? false)
            <a href="{{ $publicProfileUrl }}" class="rounded-lg border px-4 py-2">Open public profile</a>
        @else
            <span class="rounded-lg border border-amber-300 bg-amber-50/70 text-amber-900 px-4 py-2 text-xs">Public profile not available yet for this ID</span>
        @endif
        <a href="{{ $issueCenterUrl }}" class="rounded-lg border px-4 py-2">Issue center</a>
        <a href="{{ $rollbackUrl }}" class="rounded-lg border border-rose-300 text-rose-800 px-4 py-2">Rollback center</a>
    </div>

    <div class="mt-6 border-b border-gray-200 dark:border-gray-700 overflow-x-auto whitespace-nowrap flex gap-1">
        @foreach (['overview' => 'Summary', 'repeaters' => 'Profile sections', 'lineage' => 'How data flows', 'api' => 'App API', 'explain' => 'What it means', 'history' => 'Admin actions', 'developer' => 'Developer diagnostics'] as $tk => $tl)
            <button type="button" @click="tab='{{ $tk }}'" class="px-3 py-2 text-sm rounded-t-md" :class="tab==='{{ $tk }}' ? 'bg-white dark:bg-gray-900 border border-b-0 border-gray-200 dark:border-gray-700 text-indigo-700 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-800'">{{ $tl }}</button>
        @endforeach
    </div>

    {{-- Overview: issue cards --}}
    <div x-show="tab==='overview'" class="mt-6 space-y-4">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/40 p-4 text-sm">
            <p class="font-semibold text-gray-900 dark:text-gray-100">Open items: <span x-text="issueCount"></span></p>
            <p class="text-gray-600 dark:text-gray-400 text-xs mt-1">उघड समस्या: <span x-text="issueCount"></span> · Serious items are listed first.</p>
        </div>

        @if(!empty($overviewCounters))
            <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Governance overview counters</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">Total governed fields</p><p class="text-xl font-semibold">{{ (int) ($overviewCounters['total_governed_fields'] ?? 0) }}</p></div>
                    <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">Matched fields</p><p class="text-xl font-semibold text-emerald-700">{{ (int) ($overviewCounters['matched_fields'] ?? 0) }}</p></div>
                    <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">Mismatched fields</p><p class="text-xl font-semibold text-rose-700">{{ (int) ($overviewCounters['mismatched_fields'] ?? 0) }}</p></div>
                    <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">Missing in API</p><p class="text-xl font-semibold">{{ (int) ($overviewCounters['missing_in_api'] ?? 0) }}</p></div>
                    <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">Missing publicly</p><p class="text-xl font-semibold">{{ (int) ($overviewCounters['missing_publicly'] ?? 0) }}</p></div>
                    <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">Repeater issues</p><p class="text-xl font-semibold">{{ (int) ($overviewCounters['repeater_issues'] ?? 0) }}</p></div>
                    <div class="rounded-lg border p-3"><p class="text-xs text-gray-500">Unsupported fields</p><p class="text-xl font-semibold">{{ (int) ($overviewCounters['unsupported_fields'] ?? 0) }}</p></div>
                </div>
                <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div class="rounded-lg border p-3 bg-indigo-50/40 dark:bg-indigo-950/20"><p class="text-xs text-gray-500">Total saved data points (DB)</p><p class="text-xl font-semibold">{{ (int) ($overviewCounters['total_saved_data_points'] ?? 0) }}</p></div>
                    <div class="rounded-lg border p-3 bg-emerald-50/40 dark:bg-emerald-950/20"><p class="text-xs text-gray-500">Filled in DB</p><p class="text-xl font-semibold text-emerald-700">{{ (int) ($overviewCounters['filled_data_points'] ?? 0) }}</p></div>
                    <div class="rounded-lg border p-3 bg-slate-50/40 dark:bg-slate-900/20"><p class="text-xs text-gray-500">Empty in DB</p><p class="text-xl font-semibold">{{ (int) ($overviewCounters['empty_data_points'] ?? 0) }}</p></div>
                    <div class="rounded-lg border p-3 bg-indigo-50/40 dark:bg-indigo-950/20"><p class="text-xs text-gray-500">DB fill %</p><p class="text-xl font-semibold">{{ (int) ($overviewCounters['saved_data_fill_percent'] ?? 0) }}%</p></div>
                </div>
                <p class="mt-3 text-[11px] leading-relaxed text-gray-600 dark:text-gray-400">
                    <strong class="text-gray-700 dark:text-gray-300">How this number is counted:</strong>
                    Every column in every row across <span class="whitespace-nowrap">matrimony_profiles</span> plus related tables
                    (education history, career rows, siblings, children, relatives, property assets, contacts, preferences&nbsp;…)
                    counts as one “data point”. So one sibling with 15 columns adds <strong>15</strong> slots — repeaters multiply the total.
                    This is <em>not</em> the same as “How many scalar lineage cards” on the next tab.
                </p>
                <p class="mt-1 text-[11px] leading-relaxed text-gray-500 dark:text-gray-500">
                    संक्षेप: ही संख्या प्रत्येक टेबलमधील प्रत्येक सेल मोजते — रिपीटरच्या प्रत्येक ओळीचे सर्व कॉलम वेगळे गणले जातात.
                </p>
            </section>
        @endif

        @if(!empty($healthCards))
            <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($healthCards as $hc)
                    @php
                        $st = strtoupper((string) ($hc['state'] ?? 'WARNING'));
                        $tone = $st === 'HEALTHY'
                            ? 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20'
                            : ($st === 'CRITICAL'
                                ? 'border-rose-300 bg-rose-50/70 dark:border-rose-900 dark:bg-rose-950/25'
                                : 'border-amber-300 bg-amber-50/70 dark:border-amber-900 dark:bg-amber-950/25');
                    @endphp
                    <article class="rounded-xl border p-4 {{ $tone }}">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $hc['title'] ?? 'Status' }}</h3>
                            <span class="text-xs font-bold uppercase rounded px-2 py-0.5 border {{ $st === 'HEALTHY' ? 'border-emerald-500 text-emerald-800' : ($st === 'CRITICAL' ? 'border-rose-500 text-rose-800' : 'border-amber-500 text-amber-800') }}">{{ $st }}</span>
                        </div>
                        <p class="text-sm mt-2 text-gray-700 dark:text-gray-300">{{ $hc['evidence'] ?? '' }}</p>
                    </article>
                @endforeach
            </section>
        @endif

        @if(!$hasVerificationEvidence)
            <section class="rounded-xl border border-amber-300 dark:border-amber-900 bg-amber-50/70 dark:bg-amber-950/25 p-4">
                <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-100">Verification incomplete</h3>
                <p class="text-sm text-amber-900/90 dark:text-amber-200/95 mt-1">This profile currently has zero compared items, so healthy-looking counters are not reliable yet.</p>
                <p class="text-xs text-amber-900/80 dark:text-amber-200/90 mt-1">या प्रोफाइलसाठी तुलना डेटा अजून तयार नाही. आधी snapshot आणि comparison चालवून मगच परिणामावर विश्वास ठेवा.</p>
            </section>
        @endif

        @if(!empty($timelineRows))
            <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/40 p-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Issue timeline</h3>
                <div class="space-y-2">
                    @foreach($timelineRows as $t)
                        @php
                            $sev = strtoupper((string) ($t['severity'] ?? 'INFO'));
                            $sevTone = $sev === 'CRITICAL' ? 'text-rose-700' : ($sev === 'WARNING' ? 'text-amber-700' : 'text-slate-700');
                        @endphp
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                            <p class="text-xs font-bold {{ $sevTone }}">[{{ $sev }}]</p>
                            <p class="text-sm text-gray-800 dark:text-gray-200 mt-1">{{ $t['message'] ?? '' }}</p>
                            <p class="text-xs text-gray-500 mt-1">Layer: {{ $t['layer'] ?? '—' }} · Field: {{ $t['field'] ?? '—' }}</p>
                            <p class="text-xs text-indigo-700 dark:text-indigo-300 mt-1">{{ $t['action'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if(!empty($silentLossRows))
            <section class="rounded-xl border border-rose-200 dark:border-rose-900 bg-rose-50/60 dark:bg-rose-950/20 p-4">
                <h3 class="text-sm font-semibold text-rose-900 dark:text-rose-100">Silent data loss risk</h3>
                <p class="text-xs text-rose-800/80 dark:text-rose-200/90 mt-1">Saved value exists in database but is missing downstream.</p>
                <div class="overflow-x-auto mt-3">
                    <table class="min-w-full text-sm">
                        <thead class="text-xs uppercase text-rose-900/80 border-b border-rose-200/80">
                            <tr><th class="py-2 pr-3 text-left">Field</th><th class="py-2 pr-3 text-left">DB value</th><th class="py-2 pr-3 text-left">Missing layer</th><th class="py-2 pr-3 text-left">Probable failure point</th><th class="py-2 text-left">Action</th></tr>
                        </thead>
                        <tbody class="divide-y divide-rose-100 dark:divide-rose-900/40">
                            @foreach($silentLossRows as $sl)
                                <tr>
                                    <td class="py-2 pr-3 font-medium">{{ $sl['label_en'] ?? $sl['field'] }}</td>
                                    <td class="py-2 pr-3 break-all">{{ is_scalar($sl['db_value'] ?? null) ? $sl['db_value'] : 'Present' }}</td>
                                    <td class="py-2 pr-3">{{ !empty($sl['api_missing']) && !empty($sl['public_missing']) ? 'API + Public profile' : (!empty($sl['api_missing']) ? 'API' : 'Public profile') }}</td>
                                    <td class="py-2 pr-3">{{ $sl['probable_failure_point'] ?? 'Needs review' }}</td>
                                    <td class="py-2">
                                        <a href="{{ $issueCenterUrl }}" class="inline-flex rounded border border-indigo-300 px-2 py-1 text-xs">Open issue center (fix)</a>
                                        <a href="{{ $rollbackUrl }}" class="ml-1 inline-flex rounded border border-rose-300 px-2 py-1 text-xs text-rose-800">Open rollback center</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
        @forelse($issueCards as $card)
            @php
                $sev = $card['severity'] ?? 'low';
                $borderRing = $sev === 'critical' ? 'border-rose-900 ring-2 ring-rose-400/50' : '';
                $cardTone = match ($sev) {
                    'critical', 'high' => 'border-rose-200 bg-rose-50/50 dark:border-rose-900/40 dark:bg-rose-950/20',
                    'medium' => 'border-amber-200 bg-amber-50/40 dark:border-amber-900/30 dark:bg-amber-950/15',
                    default => 'border-slate-200 bg-slate-50/50 dark:border-slate-700 dark:bg-slate-900/30',
                };
            @endphp
            <article class="rounded-xl border shadow-sm overflow-hidden {{ $cardTone }} {{ $borderRing }}">
                <div class="px-5 py-4 flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Affected detail</p>
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $card['affected_label_en'] ?? '' }}</p>
                        @if(($card['affected_label_mr'] ?? '') !== '')
                            <p class="text-xs text-gray-600 dark:text-gray-400">{{ $card['affected_label_mr'] }}</p>
                        @endif
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 leading-snug mt-2">{{ $card['title_en'] }}</h2>
                        @if(($card['title_mr'] ?? '') !== '')
                            <p class="text-sm text-gray-700 dark:text-gray-300 mt-1">{{ $card['title_mr'] }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 text-xs font-bold uppercase tracking-wide px-2 py-1 rounded {{ $sev === 'critical' ? 'bg-rose-900 text-white' : (($sev === 'high') ? 'bg-rose-600 text-white' : (($sev === 'medium') ? 'bg-amber-500 text-white' : 'bg-slate-500 text-white')) }}">
                        @if($sev === 'critical') Critical @elseif($sev === 'high') Serious @elseif($sev === 'medium') Warning @else Note @endif
                    </span>
                </div>
                <div class="px-5 pb-4 space-y-3 text-sm text-gray-800 dark:text-gray-200">
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Where it showed up</p>
                        <p>{{ $card['layer_en'] ?? '' }}</p>
                        @if(($card['layer_mr'] ?? '') !== '')
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">{{ $card['layer_mr'] }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">What we saw</p>
                        <p>{{ $card['what_en'] ?? '' }}</p>
                        @if(($card['what_mr'] ?? '') !== '')
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">{{ $card['what_mr'] }}</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Why it matters</p>
                        <p>{{ $card['impact_en'] ?? '' }}</p>
                        @if(($card['impact_mr'] ?? '') !== '')
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">{{ $card['impact_mr'] }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2 pt-2">
                        @if($canOperateGovernance ?? false)
                            <button type="button" :disabled="actionLoading" @click="runAction('rebuild_snapshot')" class="text-xs rounded-md bg-white dark:bg-gray-800 border px-3 py-1.5 disabled:opacity-50">Rebuild snapshot</button>
                            <button type="button" :disabled="actionLoading" @click="runAction('rerun_comparison')" class="text-xs rounded-md bg-white dark:bg-gray-800 border px-3 py-1.5 disabled:opacity-50">Compare again</button>
                        @endif
                        <a href="{{ $publicProfileUrl ?? '#' }}" class="text-xs rounded-md bg-white dark:bg-gray-800 border px-3 py-1.5">Open public profile</a>
                        <a href="{{ $wizardUrl ?? '#' }}" class="text-xs rounded-md bg-white dark:bg-gray-800 border px-3 py-1.5">Open full form</a>
                    </div>
                </div>
            </article>
        @empty
            @if($hasVerificationEvidence)
                <p class="text-sm text-gray-600 dark:text-gray-400">No open issues for this profile in the latest check.</p>
                <p class="text-xs text-gray-500">या प्रोफाइलसाठी सध्या कोणतीही उघड समस्या नाही.</p>
            @else
                <p class="text-sm text-amber-700 dark:text-amber-300">No issue cards yet because verification data is incomplete.</p>
                <p class="text-xs text-amber-700/90">तपासणी डेटा पूर्ण नाही, म्हणून issue cards दिसत नाहीत.</p>
            @endif
        @endforelse
    </div>

    {{-- Repeaters: tables --}}
    <div x-show="tab==='repeaters'" class="mt-6 space-y-8" style="display:none;">
        @if(!empty($repeaterPanels))
            <section class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($repeaterPanels as $rp)
                    @php
                        $rs = strtoupper((string) ($rp['status'] ?? 'WARNING'));
                        $rtone = $rs === 'HEALTHY'
                            ? 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20'
                            : ($rs === 'CRITICAL'
                                ? 'border-rose-300 bg-rose-50/70 dark:border-rose-900 dark:bg-rose-950/25'
                                : 'border-amber-300 bg-amber-50/70 dark:border-amber-900 dark:bg-amber-950/25');
                    @endphp
                    <article class="rounded-xl border p-4 {{ $rtone }}">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $rp['title_en'] }}</h3>
                            <span class="text-xs font-bold uppercase">{{ $rs }}</span>
                        </div>
                        <p class="text-xs text-gray-700 mt-1">{{ $rp['title_mr'] ?? '' }}</p>
                        <div class="mt-2 text-sm grid grid-cols-2 gap-2">
                            <p title="Live count from the matrimony_profiles repeater table for this profile">DB rows (live): <strong>{{ (int) ($rp['db_rows'] ?? 0) }}</strong></p>
                            <p>API rows: <strong>{{ (int) ($rp['api_rows'] ?? 0) }}</strong></p>
                            <p>Public rows: <strong>{{ (int) ($rp['public_rows'] ?? 0) }}</strong></p>
                            <p>Missing rows: <strong>{{ (int) ($rp['missing_rows'] ?? 0) }}</strong></p>
                            <p>Mismatched rows: <strong>{{ (int) ($rp['mismatched_rows'] ?? 0) }}</strong></p>
                            <p>Duplicate rows: <strong>{{ (int) ($rp['duplicate_rows'] ?? 0) }}</strong></p>
                        </div>
                        @if(!empty($rp['snapshot_stale']))
                            <p class="mt-2 rounded border border-amber-300 bg-amber-50/80 dark:border-amber-700 dark:bg-amber-950/30 px-2 py-1 text-[11px] text-amber-900 dark:text-amber-100">
                                <strong>Snapshot stale:</strong>
                                live rows {{ (int) ($rp['live_db_rows'] ?? 0) }} vs snapshot rows {{ (int) ($rp['snapshot_db_rows'] ?? 0) }}.
                                Rebuild snapshot, then re-run section check.
                            </p>
                        @endif
                        <p class="text-xs mt-2 text-indigo-700 dark:text-indigo-300">{{ $rp['guidance'] ?? '' }}</p>
                    </article>
                @endforeach
            </section>
        @endif

        @forelse($repeaterTables as $repName => $rows)
            @php
                $repLabel = \App\Services\Governance\GovernanceProfilePresenter::repeaterLabel($repName);
            @endphp
            <section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/40 p-4">
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $repLabel['en'] }}</h3>
                @if($repLabel['mr'] !== '')
                    <p class="text-xs text-gray-600 dark:text-gray-400 mb-3">{{ $repLabel['mr'] }}</p>
                @endif
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-gray-500 border-b border-gray-200 dark:border-gray-600">
                                <th class="py-2 pr-3">Row</th>
                                <th class="py-2 pr-3">Detail</th>
                                <th class="py-2 pr-3">Full form</th>
                                <th class="py-2 pr-3">App API</th>
                                <th class="py-2 pr-3">Public page</th>
                                <th class="py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($rows as $r)
                                <tr class="{{ !($r['ok'] ?? false) ? 'bg-rose-50/80 dark:bg-rose-950/20' : '' }}">
                                    <td class="py-2 pr-3 text-xs">{{ $r['row'] }}</td>
                                    <td class="py-2 pr-3">{{ str_replace('_', ' ', $r['field']) }}</td>
                                    <td class="py-2 pr-3 break-all">{{ is_scalar($r['wizard'] ?? null) ? $r['wizard'] : '—' }}</td>
                                    <td class="py-2 pr-3 break-all">{{ is_scalar($r['api'] ?? null) ? $r['api'] : '—' }}</td>
                                    <td class="py-2 pr-3 break-all">{{ is_scalar($r['public'] ?? null) ? $r['public'] : '—' }}</td>
                                    <td class="py-2">{{ ($r['ok'] ?? false) ? '✅ Match' : '❌ Mismatch' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @empty
            <p class="text-sm text-gray-600">No row-level differences were recorded for profile sections in the latest comparison.</p>
            <p class="text-xs text-gray-500">शेवटच्या तुलनेत विभागांमध्ये ओळ-स्तरावरील फरक नोंदवलेले नाहीत.</p>
        @endforelse

        @if(count($repeaterAlerts) > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/20 p-4">
                <h4 class="font-semibold text-amber-950 dark:text-amber-100">Section layout notices</h4>
                <p class="text-xs text-amber-900/80 dark:text-amber-200/90 mb-2">आकार किंवा क्रमाबद्दल सूचना</p>
                <ul class="list-disc list-inside text-sm space-y-2 text-amber-950 dark:text-amber-100">
                    @foreach($repeaterAlerts as $a)
                        <li>
                            <span class="font-medium">{{ $a['title_en'] ?? '' }}</span>
                            @if(($a['what_en'] ?? '') !== '')
                                — {{ $a['what_en'] }}
                            @endif
                            <a href="{{ route('admin.data-engine.profiles.show', ['profileId' => $profileId]) }}" class="ml-2 inline-flex rounded border border-amber-300 px-2 py-0.5 text-[11px]">Open governance profile</a>
                            @if(($a['what_mr'] ?? '') !== '')
                                <div class="text-xs text-amber-900/80 mt-0.5">{{ $a['what_mr'] }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    {{-- Lineage --}}
    @php
        // Pre-compute search haystack + counts so we can offer instant filter
        // and a stable summary even before Alpine hydrates.
        $lineageRows = [];
        $lineageCounts = ['fail' => 0, 'stale' => 0, 'partial' => 0, 'equivalent' => 0, 'empty' => 0, 'pass' => 0];
        foreach (($lineageVisuals ?? []) as $lv) {
            $valuesText = '';
            foreach (($lv['steps'] ?? []) as $st) {
                $vv = $st['value'] ?? null;
                if (is_scalar($vv)) {
                    $valuesText .= ' '.(string) $vv;
                }
                $rl = $st['resolved_label'] ?? null;
                if (is_string($rl)) {
                    $valuesText .= ' '.$rl;
                }
            }
            $lineageRows[] = [
                'lv' => $lv,
                'haystack' => mb_strtolower(trim(($lv['field'] ?? '').' '.($lv['title_en'] ?? '').' '.($lv['title_mr'] ?? '').' '.$valuesText)),
            ];
            $st = (string) ($lv['overall_status'] ?? 'partial');
            if (isset($lineageCounts[$st])) {
                $lineageCounts[$st]++;
            }
        }
        $stepShortLabels = ['wizard' => 'WIZ', 'db' => 'DB', 'api' => 'API', 'public' => 'PUB'];
        $stepShortLabelsMr = ['wizard' => 'विझार्ड', 'db' => 'डेटाबेस', 'api' => 'API', 'public' => 'सार्वजनिक'];
        // Helper to choose icon + tone classes per step state.
        $stepStateIcon = ['ok' => '✓', 'equivalent' => '≈', 'stale' => '?', 'mismatch' => '✗', 'missing' => '✗'];
        $stepStateClasses = [
            'ok' => 'bg-emerald-100 text-emerald-700 border border-emerald-300 dark:bg-emerald-950/40 dark:text-emerald-200 dark:border-emerald-700',
            'equivalent' => 'bg-teal-100 text-teal-800 border border-teal-300 dark:bg-teal-950/40 dark:text-teal-200 dark:border-teal-700',
            'stale' => 'bg-amber-100 text-amber-800 border border-amber-300 dark:bg-amber-950/40 dark:text-amber-200 dark:border-amber-700',
            'mismatch' => 'bg-rose-100 text-rose-700 border border-rose-300 dark:bg-rose-950/40 dark:text-rose-200 dark:border-rose-700',
            'missing' => 'bg-rose-100 text-rose-700 border border-rose-300 dark:bg-rose-950/40 dark:text-rose-200 dark:border-rose-700',
        ];
        $stepStateTitleEn = [
            'ok' => 'Verified present (exact match)',
            'equivalent' => 'Same meaning as database — different format/representation only',
            'stale' => 'Likely present (snapshot did not capture this layer; live database has a value)',
            'mismatch' => 'Mismatch — comparison engine flagged this layer and values do NOT mean the same thing',
            'missing' => 'Missing in this layer',
        ];
    @endphp
    <div x-show="tab==='lineage'" x-data="{ lineageFilter: '', lineageMode: 'compact', lineageStatus: 'all' }" class="mt-6 space-y-4" style="display:none;">
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/40 p-3 space-y-2 text-xs">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <p class="text-gray-700 dark:text-gray-300">
                    <strong class="text-gray-900 dark:text-gray-100">{{ count($lineageRows) }}</strong> field{{ count($lineageRows) === 1 ? '' : 's' }} flowing through Wizard → Database → API → Public profile.
                    <span class="text-gray-500 dark:text-gray-400">Use search/filter below; serious items are listed first.</span>
                </p>
                <div class="flex flex-wrap items-center gap-1 shrink-0">
                    <input type="search" x-model.debounce.150ms="lineageFilter" placeholder="filter by field, label or value…" class="rounded border border-gray-300 dark:border-gray-600 px-2 py-1 text-xs w-56 dark:bg-gray-900" />
                    <select x-model="lineageStatus" class="rounded border border-gray-300 dark:border-gray-600 px-2 py-1 text-xs dark:bg-gray-900">
                        <option value="all">All ({{ count($lineageRows) }})</option>
                        <option value="fail">Needs review ({{ $lineageCounts['fail'] }})</option>
                        <option value="stale">Snapshot stale ({{ $lineageCounts['stale'] }})</option>
                        <option value="equivalent">Same meaning ({{ $lineageCounts['equivalent'] }})</option>
                        <option value="partial">Partial ({{ $lineageCounts['partial'] }})</option>
                        <option value="empty">Empty ({{ $lineageCounts['empty'] }})</option>
                        <option value="pass">Healthy ({{ $lineageCounts['pass'] }})</option>
                    </select>
                    <div class="inline-flex items-center rounded border border-gray-300 dark:border-gray-600 overflow-hidden text-[11px]">
                        <button type="button" @click="lineageMode='compact'" :class="lineageMode==='compact' ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200'" class="px-2 py-1">Compact</button>
                        <button type="button" @click="lineageMode='detailed'" :class="lineageMode==='detailed' ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200'" class="px-2 py-1">Detailed</button>
                    </div>
                </div>
            </div>
            <p class="text-[11px] leading-relaxed text-gray-600 dark:text-gray-400 border-t border-gray-100 dark:border-gray-700 pt-2">
                <strong class="text-gray-700 dark:text-gray-300">Why fewer than “Total saved data points”?</strong>
                This tab shows one card per <strong>scalar profile path</strong> (main profile row + 1:1 tables like About me / Horoscope / Partner criteria).
                Related-table <strong>repeaters</strong> (multiple education rows, sibling rows, relatives&nbsp;…) are tracked under <strong>Profile sections</strong>, not as separate cards here.
                Several DB columns also merge into one logical card (aliases). Internal audit columns are hidden.
            </p>
            <p class="text-[11px] text-gray-500 dark:text-gray-500">
                ‘Total saved data points’ ही संख्या रिपीटरच्या प्रत्येक ओळीतील सर्व कॉलम एकत्र मोजते; इथे त्या मागची लॉजिक वेगळी आहे.
            </p>
        </div>

        @if (count($lineageRows) === 0)
            <p class="text-sm text-gray-600">No flow diagram is available until a comparison exists for this profile.</p>
        @endif

        {{-- Compact grid: many fields per row, 4 mini-stages each. --}}
        <div x-show="lineageMode==='compact' && {{ count($lineageRows) }} > 0" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-2">
            @foreach ($lineageRows as $row)
                @php
                    $lv = $row['lv'];
                    $st = (string) ($lv['overall_status'] ?? 'partial');
                    $cardTone = match ($st) {
                        'fail' => 'border-rose-300 bg-rose-50/60 dark:border-rose-800 dark:bg-rose-950/25',
                        'stale' => 'border-amber-300 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/25',
                        'partial' => 'border-amber-300 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/20',
                        'equivalent' => 'border-teal-300 bg-teal-50/60 dark:border-teal-800 dark:bg-teal-950/25',
                        'empty' => 'border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-900/30',
                        default => 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-800 dark:bg-emerald-950/20',
                    };
                    $badgeTone = match ($st) {
                        'fail' => 'bg-rose-600 text-white',
                        'stale' => 'bg-amber-500 text-white',
                        'partial' => 'bg-amber-500 text-white',
                        'equivalent' => 'bg-teal-600 text-white',
                        'empty' => 'bg-slate-400 text-white',
                        default => 'bg-emerald-600 text-white',
                    };
                    $badgeText = match ($st) {
                        'fail' => 'Needs review',
                        'stale' => 'Snapshot stale',
                        'partial' => 'Partial',
                        'equivalent' => 'Same meaning',
                        'empty' => 'Empty',
                        default => 'Healthy',
                    };
                @endphp
                <article
                    x-show="(lineageStatus==='all' || lineageStatus==='{{ $st }}') && (lineageFilter.trim()==='' || @js($row['haystack']).includes(lineageFilter.toLowerCase().trim()))"
                    class="rounded-md border p-2 text-[11px] {{ $cardTone }}">
                    <div class="flex items-start justify-between gap-1">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-[11px] text-gray-900 dark:text-gray-100 truncate" title="{{ $lv['title_en'] ?? $lv['field'] }} ({{ $lv['field'] }})">{{ $lv['title_en'] ?? $lv['field'] }}</p>
                            @if(($lv['title_mr'] ?? '') !== '')
                                <p class="text-[10px] text-gray-500 dark:text-gray-400 truncate" title="{{ $lv['title_mr'] }}">{{ $lv['title_mr'] }}</p>
                            @endif
                        </div>
                        <span class="shrink-0 text-[9px] uppercase font-bold rounded px-1.5 py-0.5 {{ $badgeTone }}">{{ $badgeText }}</span>
                    </div>
                    <div class="mt-1.5 flex items-center justify-between gap-0.5">
                        @foreach (($lv['steps'] ?? []) as $idx => $step)
                            @php
                                $key = (string) ($step['key'] ?? '');
                                $short = $stepShortLabels[$key] ?? strtoupper(substr($key, 0, 3));
                                $stepState = (string) ($step['state'] ?? ($step['ok'] ? 'ok' : 'missing'));
                                $stepIcon = $stepStateIcon[$stepState] ?? ($step['ok'] ? '✓' : '✗');
                                $stepClass = $stepStateClasses[$stepState] ?? $stepStateClasses['missing'];
                                $stepTitleBase = $stepStateTitleEn[$stepState] ?? '';
                                $val = $step['value'] ?? null;
                                $resolvedLbl = is_string($step['resolved_label'] ?? null) ? (string) $step['resolved_label'] : '';
                                $valDisplay = is_scalar($val) && (string) $val !== '' ? (string) $val : '';
                                if ($valDisplay !== '' && $resolvedLbl !== '' && $resolvedLbl !== $valDisplay) {
                                    $valDisplay .= ' (= '.$resolvedLbl.')';
                                } elseif ($valDisplay === '' && $resolvedLbl !== '') {
                                    $valDisplay = $resolvedLbl;
                                }
                                $valStr = $valDisplay !== ''
                                    ? \Illuminate\Support\Str::limit($valDisplay, 100)
                                    : ($stepState === 'ok' ? 'Present' : ($stepState === 'stale' ? '(snapshot pending)' : 'MISSING'));
                            @endphp
                            <div class="flex flex-col items-center text-[8px] uppercase min-w-0">
                                <span class="text-gray-500 dark:text-gray-400 leading-none">{{ $short }}</span>
                                <span class="mt-0.5 w-5 h-5 rounded-full inline-flex items-center justify-center text-[10px] font-bold {{ $stepClass }}" title="{{ ($step['label_en'] ?? '').': '.$valStr.' — '.$stepTitleBase }}">{{ $stepIcon }}</span>
                            </div>
                            @if($idx < count($lv['steps'] ?? []) - 1)
                                <span class="text-gray-400 text-[10px] -mb-2">→</span>
                            @endif
                        @endforeach
                    </div>
                    <details class="mt-1.5 text-[10px]">
                        <summary class="cursor-pointer text-gray-500 dark:text-gray-400 select-none">Values & verify</summary>
                        <div class="mt-1 space-y-0.5">
                            @foreach (($lv['steps'] ?? []) as $step)
                                @php
                                    $stepState = (string) ($step['state'] ?? ($step['ok'] ? 'ok' : 'missing'));
                                    $val = $step['value'] ?? null;
                                    $resolvedLbl = is_string($step['resolved_label'] ?? null) ? (string) $step['resolved_label'] : '';
                                    $valDisplay = is_scalar($val) && (string) $val !== '' ? (string) $val : '';
                                    if ($valDisplay !== '' && $resolvedLbl !== '' && $resolvedLbl !== $valDisplay) {
                                        $valDisplay .= ' (= '.$resolvedLbl.')';
                                    } elseif ($valDisplay === '' && $resolvedLbl !== '') {
                                        $valDisplay = $resolvedLbl;
                                    }
                                    $valStr = $valDisplay !== ''
                                        ? \Illuminate\Support\Str::limit($valDisplay, 200)
                                        : ($stepState === 'ok' ? 'Present' : ($stepState === 'stale' ? '(snapshot pending — DB has value)' : 'MISSING'));
                                    $valTone = match ($stepState) {
                                        'ok' => '',
                                        'equivalent' => 'text-teal-700 dark:text-teal-300',
                                        'stale' => 'text-amber-700 dark:text-amber-300',
                                        default => 'text-rose-700 dark:text-rose-300',
                                    };
                                @endphp
                                <p class="break-words">
                                    <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $step['label_en'] ?? '' }}:</span>
                                    <span class="{{ $valTone }}">{{ $valStr }}</span>
                                    @if($stepState === 'equivalent')
                                        <span class="text-[9px] uppercase tracking-wide text-teal-700 dark:text-teal-300 ml-1">≈ same as DB</span>
                                    @endif
                                </p>
                            @endforeach
                            @if(!empty($lv['root_cause']))
                                @php
                                    $rcTone = match ($st) {
                                        'fail' => 'text-rose-700 dark:text-rose-300',
                                        'equivalent' => 'text-teal-700 dark:text-teal-300',
                                        default => 'text-amber-800 dark:text-amber-200',
                                    };
                                @endphp
                                <p class="pt-1 mt-1 border-t border-current/10 {{ $rcTone }}">
                                    {{ $lv['root_cause']['en'] ?? '' }}
                                </p>
                            @endif
                            <p class="pt-1 mt-1 border-t border-current/10 flex flex-wrap gap-1.5 text-[10px]">
                                <button type="button" @click="tab='api'" class="rounded border border-indigo-300 px-1.5 py-0.5 text-indigo-700 dark:text-indigo-300 dark:border-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30">Verify in App API tab</button>
                                <a href="{{ $wizardUrl ?? '#' }}#field={{ $lv['field'] }}" target="_blank" rel="noopener" class="rounded border border-slate-300 px-1.5 py-0.5 text-slate-700 dark:text-slate-200 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800/30">Open wizard</a>
                                @if(!empty($publicProfileUrl))
                                    <a href="{{ $publicProfileUrl }}#field={{ $lv['field'] }}" target="_blank" rel="noopener" class="rounded border border-slate-300 px-1.5 py-0.5 text-slate-700 dark:text-slate-200 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800/30">Open public profile</a>
                                @endif
                            </p>
                        </div>
                    </details>
                </article>
            @endforeach
        </div>

        {{-- Detailed: original vertical chain layout (one field per section). --}}
        <div x-show="lineageMode==='detailed' && {{ count($lineageRows) }} > 0" class="space-y-8" style="display:none;">
            @foreach ($lineageRows as $row)
                @php $lv = $row['lv']; $st = (string) ($lv['overall_status'] ?? 'partial'); @endphp
                <section
                    x-show="(lineageStatus==='all' || lineageStatus==='{{ $st }}') && (lineageFilter.trim()==='' || @js($row['haystack']).includes(lineageFilter.toLowerCase().trim()))"
                    class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900/40">
                    <h3 class="text-base font-semibold">{{ $lv['title_en'] ?? $lv['field'] }} <span class="text-[11px] font-normal text-gray-500">({{ $lv['field'] }})</span></h3>
                    @if(($lv['title_mr'] ?? '') !== '')
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-4">{{ $lv['title_mr'] }}</p>
                    @endif
                    <div class="flex flex-col items-center gap-2 text-sm">
                        @foreach (($lv['steps'] ?? []) as $idx => $step)
                            @php
                                $stepState = (string) ($step['state'] ?? (($step['ok'] ?? false) ? 'ok' : 'missing'));
                                $stepBox = match ($stepState) {
                                    'ok' => 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30',
                                    'equivalent' => 'border-teal-300 bg-teal-50 dark:border-teal-800 dark:bg-teal-950/30',
                                    'stale' => 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30',
                                    default => 'border-rose-300 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/30',
                                };
                                $stepStatusText = match ($stepState) {
                                    'ok' => 'HEALTHY ✅',
                                    'equivalent' => 'SAME MEANING ≈ (different format)',
                                    'stale' => 'SNAPSHOT PENDING ⏳ (live DB has value)',
                                    'mismatch' => 'MISMATCH ❌',
                                    default => 'MISSING ❌',
                                };
                                $rawVal = $step['value'] ?? null;
                                $resolvedLbl = is_string($step['resolved_label'] ?? null) ? (string) $step['resolved_label'] : '';
                                $stepValueDisplay = is_scalar($rawVal) && (string) $rawVal !== '' ? (string) $rawVal : '';
                                if ($stepValueDisplay !== '' && $resolvedLbl !== '' && $resolvedLbl !== $stepValueDisplay) {
                                    $stepValueDisplay .= ' (= '.$resolvedLbl.')';
                                } elseif ($stepValueDisplay === '' && $resolvedLbl !== '') {
                                    $stepValueDisplay = $resolvedLbl;
                                }
                                if ($stepValueDisplay === '') {
                                    $stepValueDisplay = $stepState === 'stale' ? '(snapshot pending — DB has value)' : 'MISSING';
                                }
                            @endphp
                            <div class="w-full max-w-md rounded-lg border px-4 py-3 text-center {{ $stepBox }}">
                                <div class="font-medium">{{ $step['label_en'] }}</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">{{ $step['label_mr'] }}</div>
                                <div class="text-xs mt-1 break-all">{{ $stepValueDisplay }}</div>
                                <div class="text-xs mt-1 font-semibold">{{ $stepStatusText }}</div>
                            </div>
                            @if($idx < count($lv['steps'] ?? []) - 1)
                                <span class="text-gray-400 text-xl">↓</span>
                            @endif
                        @endforeach
                    </div>
                    @if(!empty($lv['root_cause']))
                        @php
                            $detailBg = match ($st) {
                                'fail' => 'bg-rose-50 dark:bg-rose-950/30 text-rose-900 dark:text-rose-100',
                                'equivalent' => 'bg-teal-50 dark:bg-teal-950/30 text-teal-900 dark:text-teal-100',
                                default => 'bg-amber-50 dark:bg-amber-950/30 text-amber-900 dark:text-amber-100',
                            };
                            $headerLbl = $st === 'equivalent' ? 'Same meaning' : 'Likely cause';
                        @endphp
                        <div class="mt-4 rounded-md {{ $detailBg }} p-3 text-sm">
                            <p class="font-semibold text-xs uppercase opacity-70">{{ $headerLbl }}</p>
                            <p>{{ $lv['root_cause']['en'] ?? '' }}</p>
                            @if(($lv['root_cause']['mr'] ?? '') !== '')
                                <p class="text-xs opacity-80 mt-1">{{ $lv['root_cause']['mr'] }}</p>
                            @endif
                        </div>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <button type="button" @click="tab='api'" class="rounded border border-indigo-300 px-2 py-1 text-indigo-700 dark:text-indigo-300 dark:border-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30">Verify in App API tab</button>
                        <a href="{{ $wizardUrl ?? '#' }}#field={{ $lv['field'] }}" target="_blank" rel="noopener" class="rounded border border-slate-300 px-2 py-1 text-slate-700 dark:text-slate-200 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800/30">Open wizard</a>
                        @if(!empty($publicProfileUrl))
                            <a href="{{ $publicProfileUrl }}#field={{ $lv['field'] }}" target="_blank" rel="noopener" class="rounded border border-slate-300 px-2 py-1 text-slate-700 dark:text-slate-200 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800/30">Open public profile</a>
                        @endif
                        @if($canOperateGovernance ?? false)
                            <button type="button" :disabled="actionLoading" @click="runAction('rebuild_snapshot')" class="rounded border border-amber-300 px-2 py-1 text-amber-800 dark:text-amber-200 dark:border-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/30 disabled:opacity-50">Rebuild snapshot</button>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    {{-- API parity (human-first) --}}
    <div x-show="tab==='api'" class="mt-6 space-y-4" style="display:none;">
        <div class="rounded-xl border p-4 bg-white dark:bg-gray-900/40">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">App API status</h3>
            <p class="text-xs text-gray-600 dark:text-gray-400 mb-3">अ‍ॅप API स्थिती</p>
            @if(($apiTab['ok'] ?? false))
                <p class="text-emerald-700 dark:text-emerald-300 font-medium">No missing API information detected for checked items.</p>
                <p class="text-xs text-gray-600">तपासलेल्या गोष्टी API मध्ये उपलब्ध आहेत.</p>
            @else
                <p class="text-rose-700 dark:text-rose-300 font-medium">{{ $apiTab['failure_title_en'] ?? 'Missing in app API' }}</p>
                <p class="text-xs text-gray-600">{{ $apiTab['failure_body_en'] ?? '' }}</p>
                @if(($apiTab['failure_body_mr'] ?? '') !== '')
                    <p class="text-xs text-gray-500 mt-1">{{ $apiTab['failure_body_mr'] }}</p>
                @endif
            @endif
            @if(($apiTab['checked_at'] ?? '') !== '')
                <p class="text-xs text-gray-500 mt-2">Based on snapshot captured at: {{ $apiTab['checked_at'] }}</p>
            @endif
            @if($canOperateGovernance ?? false)
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" :disabled="actionLoading" @click="runAction('validate_api_parity')" class="text-sm rounded-md bg-indigo-600 text-white px-3 py-1.5 disabled:opacity-50">Refresh API check</button>
                    <button type="button" :disabled="actionLoading" @click="runAction('rebuild_snapshot')" class="text-sm rounded-md border border-gray-300 dark:border-gray-600 px-3 py-1.5 disabled:opacity-50">Rebuild snapshot</button>
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach($apiTab['lines'] ?? [] as $line)
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm {{ ($line['ok'] ?? false) ? '' : 'bg-rose-50/70 dark:bg-rose-950/20' }}">
                    <div>
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $line['label_en'] }}</span>
                        @if(($line['label_mr'] ?? '') !== '')
                            <span class="text-xs text-gray-500 block">{{ $line['label_mr'] }}</span>
                        @endif
                    </div>
                    <span class="text-sm font-medium {{ ($line['ok'] ?? false) ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                        {{ ($line['ok'] ?? false) ? 'Present ✅' : 'Missing ❌' }}
                    </span>
                </div>
            @endforeach
        </div>

        @foreach($apiTab['nested_cards'] ?? [] as $ncard)
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900/40 p-4">
                <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ $ncard['title_en'] }}</h4>
                @if(($ncard['title_mr'] ?? '') !== '')
                    <p class="text-xs text-gray-500 mb-2">{{ $ncard['title_mr'] }}</p>
                @endif
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    @foreach($ncard['rows'] ?? [] as $nr)
                        <dt class="text-gray-500">{{ $nr['label_en'] }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100 break-words">{{ $nr['value_en'] }}</dd>
                    @endforeach
                </dl>
            </div>
        @endforeach

        <details class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 text-sm">
            <summary class="cursor-pointer px-4 py-2 font-medium text-gray-700 dark:text-gray-300">All raw API keys (technical)</summary>
            <div class="px-4 pb-4 pt-1 overflow-x-auto max-h-56 overflow-y-auto text-xs border-t border-gray-200 dark:border-gray-700">
                <table class="min-w-full">
                    @forelse($apiProfile as $k => $v)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1 pr-4 text-gray-600">{{ $k }}</td>
                            <td class="py-1 break-all">{{ is_scalar($v) ? $v : 'Nested — see Developer diagnostics' }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-gray-500">No API snapshot keys loaded.</td></tr>
                    @endforelse
                </table>
            </div>
        </details>
    </div>

    {{-- Explain --}}
    <div x-show="tab==='explain'" class="mt-6 space-y-4 text-sm" style="display:none;">
        <p class="text-gray-700 dark:text-gray-300">This page compares three places: what is saved on the profile, what the app API returns, and what appears on the public profile page. When they do not match, we show a card above.</p>
        <p class="text-xs text-gray-600">हे पृष्ठ तीन ठिकाणे तुलना करते: जतन, अ‍ॅप API, सार्वजनिक पृष्ठ.</p>
        @if(count($repeaterAlerts) > 0)
            <div class="rounded-lg border p-4">
                <h4 class="font-semibold mb-2">About section messages</h4>
                <p class="text-gray-700 dark:text-gray-300">If education, career, or family blocks are mentioned, the long form and public page may show different text. Rebuild the snapshot after fixing the page, then compare again.</p>
            </div>
        @endif
    </div>

    {{-- History --}}
    <div x-show="tab==='history'" class="mt-6" style="display:none;">
        <table class="min-w-full text-sm">
            <thead><tr class="text-left text-xs uppercase text-gray-500 border-b"><th class="py-2 pr-3">ID</th><th class="py-2 pr-3">Action</th><th class="py-2 pr-3">Status</th><th class="py-2">When</th></tr></thead>
            <tbody>
                @forelse($history as $h)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-2 pr-3">{{ $h->id }}</td>
                        <td class="py-2 pr-3">{{ $h->action }}</td>
                        <td class="py-2 pr-3">{{ $h->status }}</td>
                        <td class="py-2">{{ $h->created_at }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-4 text-gray-500">No admin actions logged for this profile yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Developer --}}
    <div x-show="tab==='developer'" class="mt-6 space-y-4" style="display:none;">
        <p class="text-sm text-gray-600">Technical bundle for developers. Not needed for day-to-day profile review.</p>
        <div class="flex gap-2">
            <button type="button" class="text-sm rounded-md bg-gray-800 text-white px-4 py-2" @click="diagOpen = true; loadDiagnostics()" x-show="!diagText">Load technical bundle</button>
            <button type="button" class="text-sm rounded-md border border-gray-400 px-4 py-2" @click="diagOpen = true; reloadDiagnostics()" x-show="diagText">Reload technical bundle</button>
        </div>
        <p class="text-xs text-gray-500" x-show="diagLoading">Loading…</p>
        <pre class="text-xs bg-gray-900 text-gray-100 p-4 rounded-lg overflow-auto max-h-[32rem]" x-show="diagText" x-text="diagText"></pre>
    </div>
</div>
@endsection
