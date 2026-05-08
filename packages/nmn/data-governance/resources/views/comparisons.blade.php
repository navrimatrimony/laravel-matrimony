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
            <p><strong>Mismatches:</strong> {{ (int) ($latest['summary']['mismatch_count'] ?? 0) }} · <strong>High severity:</strong> {{ (int) ($latest['summary']['high_severity_count'] ?? 0) }} · <strong>Suppressed:</strong> {{ (int) ($latest['summary']['suppressed_count'] ?? 0) }}</p>
        </div>
    @endif

    @forelse ($reports as $report)
        <div class="mb-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 text-sm">
                <strong>{{ $report['file'] }}</strong> · {{ $report['generated_at'] ?? '—' }} · score {{ $report['health_score'] ?? '—' }}
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-3 py-2 text-left">Field</th>
                            <th class="px-3 py-2 text-left">Comparison type</th>
                            <th class="px-3 py-2 text-left">Severity</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Suppressed</th>
                            <th class="px-3 py-2 text-left">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['comparisons'] as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700">
                                <td class="px-3 py-2 font-mono">{{ $row['field'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['comparison_type'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['effective_severity'] ?? ($row['severity'] ?? '—') }}</td>
                                <td class="px-3 py-2">{{ $row['status'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ !empty($row['suppressed']) ? 'yes' : 'no' }}</td>
                                <td class="px-3 py-2">{{ $row['suppression_reason'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-gray-500">No rows for this filter set.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 text-sm text-gray-500">
            No comparison reports found yet.
        </div>
    @endforelse
</div>
@endsection

