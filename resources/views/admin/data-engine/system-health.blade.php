@extends('layouts.admin')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <h1 class="text-xl font-semibold mb-4">Scheduler & System Health</h1>
    @php $h = is_array($health ?? null) ? $health : []; $checks = is_array($h['recovery_checks'] ?? null) ? $h['recovery_checks'] : []; @endphp
    <div class="rounded border bg-white dark:bg-gray-900 dark:border-gray-700 p-4 text-sm space-y-2">
        <p><strong>Cron heartbeat:</strong> {{ !empty($checks['scheduler_heartbeat_ok']) ? 'Healthy' : 'Stale' }}</p>
        <p><strong>Stale runs:</strong> {{ !empty($checks['stale_run_detected']) ? 'Detected' : 'Not detected' }}</p>
        <p><strong>Crash recovery:</strong> {{ !empty($checks['crash_recovery_attempted']) ? 'Attempted' : 'Not needed' }}</p>
        <p><strong>Lock recovery:</strong> {{ !empty($checks['lock_recovery_attempted']) ? 'Attempted' : 'Not needed' }}</p>
        <p><strong>Failed-run recovery:</strong> {{ !empty($checks['failed_run_recovery_attempted']) ? 'Attempted' : 'Not needed' }}</p>
        <p><strong>Last event:</strong> {{ $h['last_event_at'] ?? '—' }}</p>
    </div>
</div>
@endsection

