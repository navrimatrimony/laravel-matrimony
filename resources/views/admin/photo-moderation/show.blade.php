@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 max-w-5xl">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Photo #{{ $photo->id }}</h1>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                <a href="{{ route('admin.photo-moderation.index') }}" class="text-indigo-600 dark:text-indigo-400 underline">← Back to list</a>
                @if ($photo->profile)
                    · Profile <a href="{{ route('admin.profiles.show', $photo->profile) }}" class="text-indigo-600 dark:text-indigo-400 underline">{{ $photo->profile->id }}</a>
                    · User ID {{ $photo->profile->user_id ?? '—' }}
                @endif
            </p>
        </div>
        <img src="{{ $previewUrl }}" alt="" class="h-24 w-24 rounded-lg object-cover border border-gray-200 dark:border-gray-600">
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100">
            {{ $errors->first() }}
        </div>
    @endif
    @if (session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900 dark:border-green-800 dark:bg-green-950/40 dark:text-green-100">{{ session('success') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-2 mb-4">
        <div class="rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <h2 class="font-semibold text-gray-800 dark:text-gray-100 mb-2">Stored columns</h2>
            <dl class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                <dt class="text-gray-500 dark:text-gray-400">approved_status</dt><dd>{{ $photo->approved_status }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">admin_override_status</dt><dd>{{ $photo->admin_override_status ?? '—' }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">effective (display)</dt><dd class="font-semibold">{{ $photo->effectiveApprovedStatus() }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">moderation_version</dt><dd>{{ $photo->moderation_version ?? '—' }}</dd>
            </dl>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <h2 class="font-semibold text-gray-800 dark:text-gray-100 mb-2">Scan summary</h2>
            <dl class="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                <dt class="text-gray-500 dark:text-gray-400">api_status (Python)</dt><dd class="font-mono">{{ $headline['api_status'] ?? '—' }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">pipeline_safe</dt><dd>{{ $headline['pipeline_safe'] === null ? '—' : ($headline['pipeline_safe'] ? 'true' : 'false') }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">pipeline confidence</dt><dd>{{ $headline['confidence_pct'] !== null ? $headline['confidence_pct'].'%' : '—' }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">detection rows (stored)</dt><dd>{{ $headline['detection_count'] }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">Unsafe lock</dt><dd>{{ $unsafe ? 'Yes — approve blocked in Laravel' : 'No' }}</dd>
            </dl>
        </div>
    </div>

    <div class="mb-8 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
        <h2 class="font-semibold text-gray-800 dark:text-gray-100 mb-2">AI transparency (why safe / review / unsafe)</h2>
        @php($ex = $aiExplain ?? ['summary' => '', 'top_two' => [], 'risk_badge' => '—'])
        <p class="text-sm text-gray-800 dark:text-gray-200 mb-2">{{ $ex['summary'] }}</p>
        <div class="flex flex-wrap items-center gap-2 mb-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Risk</span>
            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold
                @if (($ex['risk_badge'] ?? '') === 'HIGH RISK') bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200
                @elseif (($ex['risk_badge'] ?? '') === 'MEDIUM RISK') bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100
                @elseif (($ex['risk_badge'] ?? '') === 'LOW RISK') bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200
                @else bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200 @endif">{{ $ex['risk_badge'] ?? '—' }}</span>
        </div>
        @if (! empty($ex['top_two']))
            <p class="text-xs text-gray-600 dark:text-gray-400 mb-1">Top non-face detections:</p>
            <ul class="text-xs font-mono text-gray-700 dark:text-gray-300 list-disc list-inside">
                @foreach ($ex['top_two'] as $row)
                    <li>{{ $row['class'] }} — {{ number_format((float) $row['score'], 4) }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mb-8">
        <h2 class="font-semibold text-gray-800 dark:text-gray-100 mb-2">Detections (by class)</h2>
        @if (count($detections) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No structured detections in stored JSON.</p>
        @else
            <div class="overflow-x-auto border border-gray-200 dark:border-gray-600 rounded-lg">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700/50 text-left">
                        <tr>
                            <th class="px-3 py-2">Class</th>
                            <th class="px-3 py-2">Boxes</th>
                            <th class="px-3 py-2">Max score %</th>
                            <th class="px-3 py-2">Sample %</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                        @foreach ($detections as $row)
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['class'] }}</td>
                                <td class="px-3 py-2">{{ $row['box_count'] }}</td>
                                <td class="px-3 py-2">{{ $row['max_score_pct'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs">{{ implode(', ', $row['scores_sample']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <form method="post" action="{{ route('admin.photo-moderation.action', $photo) }}" id="photo-moderation-detail-form"
        data-reject-suggestion="{{ e($rejectSuggestion) }}">
        @csrf
        <input type="hidden" name="action" id="moderation-action-input" value="">

        <div class="mb-4 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <h2 class="font-semibold text-gray-800 dark:text-gray-100 mb-2">Reason <span class="text-rose-600 dark:text-rose-400">(required, min 10 characters)</span></h2>
            <textarea name="reason" id="moderation-reason-input" rows="3" minlength="10" maxlength="2000" required
                class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-3 py-2 text-sm">{{ old('reason') }}</textarea>
            <p id="detail-reason-hint" class="mt-1 text-xs text-amber-700 dark:text-amber-300">Reason required (min 10 characters)</p>
        </div>

        <div class="mb-8 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <h2 class="font-semibold text-gray-800 dark:text-gray-100 mb-3">Actions</h2>
            <div class="flex flex-wrap gap-2">
                <button type="button" data-moderation-action="approve"
                    class="rounded px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50"
                    @if ($unsafe) disabled title="Unsafe scans cannot be approved" @endif
                >Approve</button>
                <button type="button" data-moderation-action="move_to_review"
                    class="rounded px-4 py-2 text-sm font-medium text-white bg-amber-500 hover:bg-amber-600"
                >Review</button>
                <button type="button" data-moderation-action="reject"
                    class="rounded px-4 py-2 text-sm font-medium text-white bg-rose-600 hover:bg-rose-700"
                >Reject</button>
                <button type="button" data-moderation-action="delete"
                    class="rounded px-4 py-2 text-sm font-medium text-white bg-gray-800 hover:bg-gray-900 dark:bg-gray-600"
                >Delete</button>
            </div>
            @if ($unsafe)
                <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">Approve is disabled while <code class="font-mono">api_status</code> is <code class="font-mono">unsafe</code>.</p>
            @endif
        </div>
    </form>

    <div>
        <h2 class="font-semibold text-gray-800 dark:text-gray-100 mb-2">Recent audit (photo_moderation_logs)</h2>
        @if ($logs->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No log rows yet.</p>
        @else
            <ul class="text-sm space-y-2 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600 rounded-lg divide-y divide-gray-200 dark:divide-gray-600">
                @foreach ($logs as $log)
                    <li class="px-3 py-2">
                        <span class="font-mono text-xs">{{ $log->created_at }}</span>
                        · {{ $log->old_status }} → <strong>{{ $log->new_status }}</strong>
                        @if ($log->reason)
                            · <span class="text-gray-500">{{ \Illuminate\Support\Str::limit($log->reason, 120) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('photo-moderation-detail-form');
    const reasonEl = document.getElementById('moderation-reason-input');
    const actionInput = document.getElementById('moderation-action-input');
    const hint = document.getElementById('detail-reason-hint');

    function reasonOk() {
        return reasonEl && reasonEl.value.trim().length >= 10;
    }
    function syncHint() {
        if (!hint) return;
        hint.classList.toggle('hidden', reasonOk());
    }
    reasonEl?.addEventListener('input', syncHint);
    syncHint();

    form?.querySelectorAll('[data-moderation-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const act = this.getAttribute('data-moderation-action');
            if (act === 'delete' && !confirm('Delete this photo and files?')) {
                return;
            }
            if (act === 'reject' && reasonEl && reasonEl.value.trim().length < 10) {
                const sug = form.getAttribute('data-reject-suggestion') || '';
                if (sug) reasonEl.value = sug;
            }
            if (!reasonOk()) {
                alert('Reason required (min 10 characters).');
                reasonEl?.focus();
                return;
            }
            if (actionInput) actionInput.value = act;
            form.submit();
        });
    });
})();
</script>
@endsection
