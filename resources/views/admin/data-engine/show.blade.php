@extends('layouts.admin')

@section('content')
@php
    $sum = is_array($summary ?? null) ? $summary : [];
    $qs = $sum['quality_score'] ?? ($run->quality_score ?? null);
    $tier = $qs === null ? 'unknown' : ($qs > 80 ? 'good' : ($qs >= 50 ? 'mid' : 'bad'));
    $tierClass = match ($tier) {
        'good' => 'text-emerald-600 dark:text-emerald-400',
        'mid' => 'text-amber-600 dark:text-amber-400',
        'bad' => 'text-red-600 dark:text-red-400',
        default => 'text-gray-600 dark:text-gray-400',
    };
    $tierBg = match ($tier) {
        'good' => 'bg-emerald-50 dark:bg-emerald-950/30 border-emerald-200 dark:border-emerald-900',
        'mid' => 'bg-amber-50 dark:bg-amber-950/30 border-amber-200 dark:border-amber-900',
        'bad' => 'bg-red-50 dark:bg-red-950/30 border-red-200 dark:border-red-900',
        default => 'bg-gray-50 dark:bg-gray-900/40 border-gray-200 dark:border-gray-700',
    };
    $pri = is_array($sum['priority_summary'] ?? null) ? $sum['priority_summary'] : ($run->priority_summary ?? []);
    $intel = is_array($sum['profile_intelligence'] ?? null)
        ? $sum['profile_intelligence']
        : (is_array($run->profile_metrics) ? $run->profile_metrics : null);
    $conv = is_array($sum['conversion_intelligence'] ?? null)
        ? $sum['conversion_intelligence']
        : (is_array($run->conversion_metrics) ? $run->conversion_metrics : null);
    $anomaliesList = is_array($sum['anomalies'] ?? null) ? $sum['anomalies'] : [];
    $qualityDeltaVal = $run->quality_delta;
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6">
        <a href="{{ route('admin.data-engine.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">&larr; Back to runs</a>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">Run #{{ $run->id }}</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
            Mode <strong>{{ $run->mode }}</strong> · Status
            <strong>{{ $run->status }}</strong>
            @if ($run->report_path)
                · Report <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded break-all">{{ $run->report_path }}</code>
            @endif
        </p>
        @if ($run->report_path)
            <div class="mt-3">
                <a href="{{ route('admin.data-engine.download', $run) }}"
                   class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Download full report
                </a>
                <a href="{{ route('admin.data-engine.data-lineage') }}"
                   class="inline-flex items-center rounded-md bg-fuchsia-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-fuchsia-600 ml-2">
                    Data lineage tab
                </a>
                <a href="{{ route('admin.data-engine.data-integrity') }}"
                   class="inline-flex items-center rounded-md bg-violet-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-600 ml-2">
                    Data integrity tab
                </a>
                <a href="{{ route('admin.data-engine.marathi-columns') }}"
                   class="inline-flex items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-600 ml-2">
                    Open Marathi `_mr` tab
                </a>
                @if ($jsonTruncated ?? false)
                    <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">Preview below is truncated.</span>
                @endif
            </div>
        @endif
    </div>

    @if (! empty($anomaliesList))
        <div class="rounded-xl border border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-4 mb-8">
            <p class="text-sm font-semibold text-red-900 dark:text-red-100">⚠️ Data anomaly detected</p>
            <ul class="mt-2 list-disc list-inside text-sm text-red-800 dark:text-red-200 space-y-1">
                @foreach ($anomaliesList as $msg)
                    @if (is_string($msg) && $msg !== '')
                        <li>{{ $msg }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    @if ($qualityDeltaVal !== null && (int) $qualityDeltaVal < -10)
        <div class="rounded-xl border border-amber-300 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-4 mb-8">
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">Quality dropped significantly</p>
            <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                Versus the previous completed run (same mode): quality score changed by <strong>{{ sprintf('%+d', (int) $qualityDeltaVal) }}</strong> points.
            </p>
        </div>
    @endif

    @if ($qs !== null)
        <div class="rounded-2xl border {{ $tierBg }} px-6 py-6 mb-8">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Data quality score</p>
            <p class="mt-1 text-5xl font-black tabular-nums {{ $tierClass }}">{{ $qs }}</p>
            <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">&gt;80 green · 50–80 yellow · &lt;50 red</p>
        </div>
    @endif

    @if (!empty($pri) && is_array($pri))
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8">
            <div class="rounded-xl border border-red-200 dark:border-red-900 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Critical</p>
                <p class="mt-1 text-2xl font-bold text-red-700 dark:text-red-300">{{ number_format((int) ($pri['critical'] ?? 0)) }}</p>
            </div>
            <div class="rounded-xl border border-orange-200 dark:border-orange-900 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">High</p>
                <p class="mt-1 text-2xl font-bold text-orange-700 dark:text-orange-300">{{ number_format((int) ($pri['high'] ?? 0)) }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 dark:border-amber-900 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Medium</p>
                <p class="mt-1 text-2xl font-bold text-amber-700 dark:text-amber-300">{{ number_format((int) ($pri['medium'] ?? 0)) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Low</p>
                <p class="mt-1 text-2xl font-bold text-slate-700 dark:text-slate-300">{{ number_format((int) ($pri['low'] ?? 0)) }}</p>
            </div>
        </div>
    @endif

    @if (is_array($intel))
        @php
            $avgProf = $intel['average_completeness_score'] ?? null;
            $profTier = $avgProf === null ? 'unknown' : ($avgProf > 70 ? 'good' : ($avgProf >= 40 ? 'mid' : 'bad'));
            $profClass = match ($profTier) {
                'good' => 'text-teal-600 dark:text-teal-400',
                'mid' => 'text-amber-600 dark:text-amber-400',
                'bad' => 'text-rose-600 dark:text-rose-400',
                default => 'text-gray-600 dark:text-gray-400',
            };
            $profBg = match ($profTier) {
                'good' => 'bg-teal-50 dark:bg-teal-950/30 border-teal-200 dark:border-teal-900',
                'mid' => 'bg-amber-50 dark:bg-amber-950/30 border-amber-200 dark:border-amber-900',
                'bad' => 'bg-rose-50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-900',
                default => 'bg-gray-50 dark:bg-gray-900/40 border-gray-200 dark:border-gray-700',
            };
            $sampleN = (int) ($intel['sample_size'] ?? 0);
            $topInc = is_array($intel['top_incomplete'] ?? null) ? $intel['top_incomplete'] : [];
            $sugCounts = is_array($intel['suggestion_counts'] ?? null) ? $intel['suggestion_counts'] : [];
            arsort($sugCounts);
            $analysisRows = is_array($report['profile_analysis'] ?? null) ? $report['profile_analysis'] : [];
            $suggestionToUsers = [];
            foreach ($analysisRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $uid = $row['user_id'] ?? null;
                $pid = $row['profile_id'] ?? null;
                $score = (int) ($row['completeness_score'] ?? 0);
                $missing = is_array($row['missing_fields'] ?? null) ? $row['missing_fields'] : [];
                $list = is_array($row['improvement_suggestions'] ?? null) ? $row['improvement_suggestions'] : [];
                foreach ($list as $txt) {
                    if (! is_string($txt) || $txt === '') {
                        continue;
                    }
                    $suggestionToUsers[$txt] = $suggestionToUsers[$txt] ?? [];
                    $suggestionToUsers[$txt][] = [
                        'user_id' => $uid,
                        'profile_id' => $pid,
                        'score' => $score,
                        'missing_fields' => $missing,
                    ];
                }
            }
            $missCounts = is_array($intel['missing_field_counts'] ?? null) ? $intel['missing_field_counts'] : [];
            arsort($missCounts);
            $notifN = is_array($intel['notification_candidates'] ?? null) ? count($intel['notification_candidates']) : 0;
        @endphp
        <div class="rounded-2xl border border-violet-200 dark:border-violet-900/50 bg-violet-50/40 dark:bg-violet-950/20 px-6 py-6 mb-8">
            <h2 class="text-sm font-semibold text-violet-900 dark:text-violet-100 uppercase tracking-wide mb-4">Profile intelligence</h2>
            <p class="text-xs text-gray-600 dark:text-gray-400 mb-4">
                Completeness uses ten fields (10 pts each). Match readiness: score &gt; 70 and not flagged critical (duplicates / empty phone).
                Sample is capped in the engine for performance (see <code class="text-xs bg-white/60 dark:bg-gray-900 px-1 rounded">ENGINE_PROFILE_ANALYSIS_LIMIT</code>).
            </p>

            @if ($avgProf !== null)
                <div class="rounded-2xl border {{ $profBg }} px-6 py-5 mb-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Average profile completeness</p>
                    <p class="mt-1 text-5xl font-black tabular-nums {{ $profClass }}">{{ number_format((float) $avgProf, 1) }}</p>
                    <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                        Sample: <strong>{{ number_format($sampleN) }}</strong> profiles
                        @if ($sampleN > 0)
                            · &gt;70 aligns with match-ready threshold (excluding critical users)
                        @endif
                    </p>
                </div>
            @else
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white/60 dark:bg-gray-900/30 px-4 py-3 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    No completeness average (zero profiles in sample or table missing). Check the raw JSON for <code class="text-xs">profile_analysis</code> warnings.
                </div>
            @endif

            @if (! empty($topInc))
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm mb-6 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Lowest completeness (sample)</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Score</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Missing fields</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($topInc as $row)
                                    @if (is_array($row))
                                        <tr>
                                            <td class="px-4 py-2 font-mono text-gray-900 dark:text-gray-100">#{{ $row['user_id'] ?? '—' }}</td>
                                            <td class="px-4 py-2 tabular-nums font-semibold">{{ (int) ($row['completeness_score'] ?? 0) }}</td>
                                            <td class="px-4 py-2 text-gray-700 dark:text-gray-300">
                                                @php $mf = $row['missing_fields'] ?? []; @endphp
                                                {{ is_array($mf) && count($mf) ? implode(', ', $mf) : '—' }}
                                            </td>
                                            <td class="px-4 py-2">
                                                @if (! empty($row['profile_id']))
                                                    <a href="{{ route('admin.profiles.show', $row['profile_id']) }}"
                                                       class="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">
                                                        Open profile #{{ $row['profile_id'] }}
                                                    </a>
                                                @else
                                                    <span class="text-xs text-gray-500">No profile link</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if (! empty($sugCounts))
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm mb-6 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Improvement suggestions (aggregate + direct solve)</h3>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($sugCounts as $text => $cnt)
                            @php $targets = is_array($suggestionToUsers[$text] ?? null) ? $suggestionToUsers[$text] : []; @endphp
                            <li class="px-4 py-3">
                                <div class="flex justify-between gap-4">
                                    <span class="text-gray-800 dark:text-gray-200">{{ $text }}</span>
                                    <span class="shrink-0 tabular-nums text-xs font-semibold text-gray-500 dark:text-gray-400">{{ number_format((int) $cnt) }}×</span>
                                </div>
                                @if (! empty($targets))
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($targets as $t)
                                            @if (! empty($t['profile_id']))
                                                <a href="{{ route('admin.profiles.show', $t['profile_id']) }}"
                                                   class="inline-flex items-center rounded-md bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800 px-2 py-1 text-xs font-semibold text-indigo-700 dark:text-indigo-200 hover:bg-indigo-100 dark:hover:bg-indigo-900/40">
                                                    Profile #{{ $t['profile_id'] }} · User #{{ $t['user_id'] ?? '—' }} · Score {{ (int) ($t['score'] ?? 0) }}
                                                </a>
                                            @elseif (! empty($t['user_id']))
                                                <span class="inline-flex items-center rounded-md bg-gray-100 dark:bg-gray-700/60 px-2 py-1 text-xs text-gray-700 dark:text-gray-200">
                                                    User #{{ $t['user_id'] }} · profile not linked
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($missCounts))
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm mb-6 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Missing field frequency</h3>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($missCounts as $field => $cnt)
                            <li class="px-4 py-3 flex justify-between gap-4">
                                <span class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ $field }}</span>
                                <span class="shrink-0 tabular-nums text-xs font-semibold text-gray-500">{{ number_format((int) $cnt) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-lg border border-dashed border-violet-300 dark:border-violet-800 bg-white/50 dark:bg-gray-900/40 px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                <strong>Notifications (future):</strong>
                {{ number_format($notifN) }} user(s) in <code class="text-xs">notification_candidates</code> (not match-ready) — reserved for WhatsApp nudges / in-app prompts. Channels hinted per row in the downloaded JSON report.
            </div>
        </div>
    @endif

    @if (is_array($conv))
        @php
            $sig = is_array($conv['conversion_signals'] ?? null) ? $conv['conversion_signals'] : [];
            $actions = is_array($conv['recommended_actions'] ?? null) ? $conv['recommended_actions'] : [];
            $convNotif = is_array($conv['notification_candidates'] ?? null) ? $conv['notification_candidates'] : [];
        @endphp
        <div class="rounded-2xl border border-fuchsia-200 dark:border-fuchsia-900/50 bg-fuchsia-50/40 dark:bg-fuchsia-950/20 px-6 py-6 mb-8">
            <h2 class="text-sm font-semibold text-fuchsia-900 dark:text-fuchsia-100 uppercase tracking-wide mb-4">Conversion intelligence</h2>
            <p class="text-xs text-gray-600 dark:text-gray-400 mb-4">
                Signals from the profile sample and data quality score. Actions are recommendations only — no messages are sent automatically.
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Low profile score (&lt;50)</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((int) ($sig['low_profile_score_users'] ?? 0)) }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">No profile photo</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((int) ($sig['no_photo_users'] ?? 0)) }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">High intent (&gt;80 completeness)</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((int) ($sig['high_intent_users'] ?? 0)) }}</p>
                </div>
            </div>
            @if (! empty($actions))
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm mb-6 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Recommended actions</h3>
                    </div>
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($actions as $act)
                            @if (is_array($act))
                                <li class="px-4 py-3 text-sm">
                                    <span class="font-mono text-xs text-fuchsia-600 dark:text-fuchsia-400">{{ $act['type'] ?? '—' }}</span>
                                    <p class="mt-1 text-gray-800 dark:text-gray-200">{{ $act['action'] ?? '' }}</p>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="rounded-lg border border-dashed border-fuchsia-300 dark:border-fuchsia-800 bg-white/50 dark:bg-gray-900/40 px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                <strong>Notification candidates (future):</strong>
                {{ number_format(count($convNotif)) }} row(s) with <code class="text-xs">user_id</code> + <code class="text-xs">type</code> (e.g. <code class="text-xs">upload_photo</code>) — reserved for queued sends; not dispatched from this UI.
            </div>
        </div>
    @endif

    @if ($run->error_output)
        <div class="rounded-lg border border-red-200 dark:border-red-900 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm text-red-900 dark:text-red-100 mb-8 whitespace-pre-wrap font-mono">{{ $run->error_output }}</div>
    @endif

    @php $sug = $sum['suggestions'] ?? []; @endphp
    @if (!empty($sug))
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm mb-8 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Smart suggestions</h2>
            </div>
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($sug as $item)
                    @if (is_array($item))
                        <li class="px-4 py-3 text-sm">
                            <span class="font-mono text-xs text-indigo-600 dark:text-indigo-400">{{ $item['type'] ?? '—' }}</span>
                            <span class="text-gray-500 dark:text-gray-400"> · count {{ number_format((int) ($item['count'] ?? 0)) }}</span>
                            <p class="mt-1 text-gray-800 dark:text-gray-200">{{ $item['suggestion'] ?? '' }}</p>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    @if (isset($sum['duplicate_groups']))
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Duplicate groups</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($sum['duplicate_groups']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Validation errors</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($sum['validation_errors']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Mismatch rows</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($sum['mismatch_rows']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Schema issues</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($sum['schema_issues']) }}</p>
            </div>
        </div>
        <div class="rounded-xl border border-indigo-200 dark:border-indigo-900/50 bg-indigo-50/50 dark:bg-indigo-950/20 px-4 py-3 mb-8">
            <p class="text-sm text-gray-800 dark:text-gray-200">
                <strong>Total issues (stored):</strong> {{ number_format((int) $run->total_issues) }}
                @if ($run->mode === 'fix' && ($sum['fixed_rows'] ?? null) !== null)
                    · <strong>Rows updated (fix):</strong> {{ number_format((int) $sum['fixed_rows']) }}
                @endif
            </p>
        </div>
    @else
        <div class="rounded-lg border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-100 mb-8">
            Report file could not be loaded or parsed. Path: {{ $run->report_path ?? '—' }}
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Raw JSON</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">Read-only preview</span>
        </div>
        <pre class="text-xs font-mono p-4 overflow-x-auto max-h-[32rem] overflow-y-auto text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-900/40">{{ $jsonPreview ?? ($rawJson ?? '{}') }}</pre>
    </div>
</div>
@endsection
