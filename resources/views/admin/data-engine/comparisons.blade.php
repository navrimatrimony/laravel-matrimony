@extends('layouts.admin')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Comparison governance</h1>
        <a href="{{ route('admin.data-engine.index') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">Back to data engine</a>
    </div>

    <form method="get" class="mb-4 flex flex-wrap gap-3 items-end rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
        <label class="text-sm">
            <span class="block text-gray-600 dark:text-gray-300 mb-1">Severity</span>
            <select name="severity" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">All</option>
                <option value="high" @selected($severity === 'high')>High</option>
                <option value="medium" @selected($severity === 'medium')>Medium</option>
                <option value="low" @selected($severity === 'low')>Low</option>
            </select>
        </label>
        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="suppressed" value="1" @checked($showSuppressed) class="rounded border-gray-300 dark:border-gray-600">
            Show suppressed rows
        </label>
        <button class="inline-flex items-center rounded-md bg-slate-700 px-3 py-2 text-xs font-semibold text-white">Apply filters</button>
    </form>

    @if ($latest)
        <div class="mb-4 rounded-lg border border-indigo-200 dark:border-indigo-800 bg-indigo-50/60 dark:bg-indigo-950/20 p-4 text-sm">
            <p><strong>Latest score:</strong> {{ $latest['health_score'] ?? '—' }}</p>
            <p><strong>Open mismatches:</strong> {{ (int) ($latest['summary']['mismatch_count'] ?? 0) }} · <strong>Serious:</strong> {{ (int) ($latest['summary']['high_severity_count'] ?? 0) }} · <strong>Hidden from list:</strong> {{ (int) ($latest['summary']['suppressed_count'] ?? 0) }}</p>
            @php $rel = is_array($latest['reliability'] ?? null) ? $latest['reliability'] : []; @endphp
            <p class="text-gray-700 dark:text-gray-300"><strong>Check quality:</strong> {{ ($rel['reliability_status'] ?? 'unknown') === 'ok' ? 'Good — comparison run looks reliable.' : 'Needs review — automated capture may be incomplete.' }} (support score {{ (int) ($rel['reliability_score'] ?? 0) }}/100)</p>
        </div>
    @endif

    <div class="mb-6 rounded-lg border border-red-200 dark:border-red-800 bg-red-50/60 dark:bg-red-950/20 p-4">
        <h2 class="font-semibold text-red-900 dark:text-red-100 mb-2">Persistent issues</h2>
        @if (empty($persistent))
            <p class="text-sm text-red-800/80 dark:text-red-200/80">No persistent issues in latest trend snapshot.</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($persistent as $p)
                    @php $pl = \App\Services\Governance\GovernanceProfilePresenter::fieldLabelPair((string) ($p['field'] ?? '')); @endphp
                    <li><span class="font-medium">{{ $pl['en'] }}</span> — still failing {{ (int) ($p['failure_count'] ?? 0) }} time(s) ({{ $p['first_seen'] ?? '—' }} → {{ $p['last_seen'] ?? '—' }})</li>
                @endforeach
            </ul>
        @endif
    </div>

    @forelse ($reports as $report)
        <div class="mb-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 text-sm">
                <strong>{{ $report['file'] }}</strong> · {{ $report['generated_at'] ?? '—' }} · score {{ $report['health_score'] ?? '—' }}
                @if (($report['reliability']['reliability_status'] ?? 'ok') !== 'ok')
                    <span class="ml-2 inline-flex rounded-full border border-amber-400 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-900">automated check may be incomplete — verify snapshot</span>
                @endif
                @if(!empty($report['profile_id']))
                    <a href="{{ route('admin.data-engine.profiles.show', ['profileId' => (int) $report['profile_id']]) }}" class="ml-2 inline-flex rounded border border-indigo-300 px-2 py-0.5 text-xs font-semibold text-indigo-800">Open governance profile</a>
                @endif
            </div>
            <details open>
                <summary class="cursor-pointer px-4 py-2 text-xs font-semibold text-gray-600 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700">Comparison rows</summary>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-3 py-2 text-left">Detail</th>
                            <th class="px-3 py-2 text-left">What we found</th>
                            <th class="px-3 py-2 text-left">Severity</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Suppressed</th>
                            <th class="px-3 py-2 text-left">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['comparisons'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700">
                                @php $fl = \App\Services\Governance\GovernanceProfilePresenter::fieldLabelPair((string) ($row['field'] ?? '')); @endphp
                                <td class="px-3 py-2">{{ $fl['en'] }}</td>
                                <td class="px-3 py-2">{{ \App\Services\Governance\GovernanceProfilePresenter::humanizeComparisonType($row['comparison_type'] ?? null) }}</td>
                                <td class="px-3 py-2">{{ $row['effective_severity'] ?? ($row['severity'] ?? '—') }}</td>
                                <td class="px-3 py-2">{{ $row['status'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ !empty($row['suppressed']) ? 'yes' : 'no' }}</td>
                                <td class="px-3 py-2">
                                    {{ $row['suppression_reason'] ?? '—' }}
                                    @if(!empty($report['profile_id']))
                                        <a href="{{ route('admin.data-engine.profiles.show', ['profileId' => (int) $report['profile_id']]) }}" class="ml-2 inline-flex rounded border border-indigo-300 px-2 py-0.5 text-xs">Open governance profile</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-gray-500">No rows for this filter set.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            </details>
        </div>
    @empty
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 text-sm text-gray-500">
            No comparison reports found yet.
        </div>
    @endforelse
</div>
@endsection

