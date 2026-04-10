@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Moderation learning analytics</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
            Aggregates <code class="text-xs bg-gray-100 dark:bg-gray-900 px-1 rounded">photo_learning_dataset</code> rows that include detection data (null JSON and empty detections are ignored).
            Suggestions use baseline <strong>moderation_nsfw_score_min</strong> = {{ number_format($thresholdBaseline, 4) }} — read-only; thresholds are not auto-updated.
            <a href="{{ route('admin.moderation-engine-settings.index') }}" class="text-indigo-600 dark:text-indigo-400 underline">Edit stored thresholds</a>
            · <a href="{{ route('admin.photo-moderation.index') }}" class="text-indigo-600 dark:text-indigo-400 underline">Photo moderation</a>
        </p>
    </div>

    @php
        $a = (int) ($decisionCounts['approved'] ?? 0);
        $r = (int) ($decisionCounts['rejected'] ?? 0);
        $v = (int) ($decisionCounts['review'] ?? 0);
        $barDenom = max(1, (int) $barTotal);
    @endphp

    <div class="mb-8 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Learning samples (with detections)</h2>
        @if ((int) $barTotal < 1)
            <p class="text-sm text-gray-500 dark:text-gray-400">No bar yet — no learning rows with non-empty detections.</p>
        @else
            <div class="flex h-8 w-full max-w-xl overflow-hidden rounded-md ring-1 ring-gray-200 dark:ring-gray-600">
                <div class="flex items-center justify-center bg-emerald-500/90 text-[11px] font-bold text-white"
                    style="width: {{ round(100 * $a / $barDenom, 2) }}%"
                    title="Approved: {{ $a }}">
                    @if ($a > 0)<span class="px-1 truncate">{{ $a }} appr.</span>@endif
                </div>
                <div class="flex items-center justify-center bg-rose-500/90 text-[11px] font-bold text-white"
                    style="width: {{ round(100 * $r / $barDenom, 2) }}%"
                    title="Rejected: {{ $r }}">
                    @if ($r > 0)<span class="px-1 truncate">{{ $r }} rej.</span>@endif
                </div>
                <div class="flex items-center justify-center bg-amber-500/90 text-[11px] font-bold text-white"
                    style="width: {{ round(100 * $v / $barDenom, 2) }}%"
                    title="Review: {{ $v }}">
                    @if ($v > 0)<span class="px-1 truncate">{{ $v }} rev.</span>@endif
                </div>
            </div>
        @endif
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Approved {{ $a }} · Rejected {{ $r }} · Review {{ $v }} (per admin actions on samples with stored detections)</p>
    </div>

    @if (count($rows) === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">No class-level statistics yet. Perform photo moderation actions with NudeNet detections stored on the row.</p>
    @else
        <div class="overflow-x-auto border border-gray-200 dark:border-gray-600 rounded-lg">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-left text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2">Class</th>
                        <th class="px-3 py-2">Total detections</th>
                        <th class="px-3 py-2">Approved %</th>
                        <th class="px-3 py-2">Rejected %</th>
                        <th class="px-3 py-2">Review %</th>
                        <th class="px-3 py-2">Avg score</th>
                        <th class="px-3 py-2">Suggested action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 align-top">
                            <td class="px-3 py-2 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $row['class'] }}</td>
                            <td class="px-3 py-2">{{ $row['total'] }}</td>
                            <td class="px-3 py-2">{{ $row['approved_pct'] }}%</td>
                            <td class="px-3 py-2">{{ $row['rejected_pct'] }}%</td>
                            <td class="px-3 py-2">{{ $row['review_pct'] }}%</td>
                            <td class="px-3 py-2">{{ number_format($row['avg_score'], 4) }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $row['suggestion'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
