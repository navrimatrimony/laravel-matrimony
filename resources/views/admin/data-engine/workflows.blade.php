@extends('layouts.admin')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <h1 class="text-xl font-semibold mb-4">Workflow Tracking</h1>
    <div id="live-status" data-url="{{ route('admin.data-engine.live-status') }}" class="mb-4 rounded border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs">
        Running: <span data-k="running">0</span> · Queued: <span data-k="queued">0</span> · Pending approval: <span data-k="pending_approval">0</span> · Failed: <span data-k="failed">0</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4 text-xs">
        <div class="rounded border bg-white px-3 py-2">Fix success rate: <span class="font-mono font-semibold">{{ $trends['fix_success_rate'] ?? 0 }}%</span></div>
        <div class="rounded border bg-white px-3 py-2">Recurring failures: <span class="font-mono font-semibold">{{ $trends['recurring_failures'] ?? 0 }}</span></div>
        <div class="rounded border bg-white px-3 py-2">Rollback frequency: <span class="font-mono font-semibold">{{ $trends['rollback_frequency'] ?? 0 }}</span></div>
        <div class="rounded border bg-white px-3 py-2">Worsening modules: <span class="font-mono font-semibold">{{ implode(', ', $trends['worsening_modules'] ?? []) ?: '—' }}</span></div>
    </div>
    <div class="rounded border bg-white dark:bg-gray-900 dark:border-gray-700 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-3 py-2 text-left">Action</th><th class="px-3 py-2 text-left">State</th><th class="px-3 py-2 text-left">Progress</th><th class="px-3 py-2 text-left">ETA</th><th class="px-3 py-2 text-left">Timestamp</th></tr></thead>
            <tbody>
            @forelse (array_reverse($workflows) as $row)
                <tr class="border-t dark:border-gray-700">
                    <td class="px-3 py-2 font-mono">{{ $row['action_id'] ?? '—' }}</td>
                    <td class="px-3 py-2"><span class="inline-flex rounded-full border px-2 py-0.5 text-xs">{{ $row['state'] ?? '—' }}</span></td>
                    <td class="px-3 py-2">{{ $row['progress_percent'] ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $row['eta_at'] ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $row['timestamp'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td class="px-3 py-4" colspan="5">No workflow events yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <details class="mt-4 rounded border bg-white px-3 py-2 text-xs">
        <summary class="cursor-pointer font-semibold">Live logs</summary>
        <div id="live-logs" class="mt-2 font-mono whitespace-pre-wrap">Live logs will appear here.</div>
    </details>
</div>
<script>
(function () {
    var el = document.getElementById('live-status');
    if (!el) return;
    var url = el.dataset.url;
    function tick() {
        fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.counts) return;
                ['running','queued','failed','pending_approval'].forEach(function (k) {
                    var n = el.querySelector('[data-k="' + k + '"]');
                    if (n) n.textContent = String(j.counts[k] || 0);
                });
                var logs = document.getElementById('live-logs');
                if (logs && Array.isArray(j.latest) && j.latest.length > 0) {
                    var l = j.latest[0];
                    var list = (l.result_payload && Array.isArray(l.result_payload.live_logs)) ? l.result_payload.live_logs : [];
                    logs.textContent = list.map(function (x) {
                        return '[' + (x.at || '') + '] ' + (x.state || '') + ' (' + (x.progress || 0) + '%) ' + (x.message || '');
                    }).join('\n') || 'No live logs yet.';
                }
            })
            .catch(function () {});
    }
    tick();
    setInterval(tick, 5000);
})();
</script>
@endsection

