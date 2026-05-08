@extends('layouts.admin')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <h1 class="text-xl font-semibold mb-4">Rollback Center</h1>
    <details open class="mb-4 rounded border bg-white dark:bg-gray-900 dark:border-gray-700 p-3">
        <summary class="cursor-pointer font-semibold text-sm">Rollback manifests</summary>
        <div class="space-y-3 mt-3">
        @forelse ($manifests as $manifest)
            <div class="rounded border bg-white dark:bg-gray-900 dark:border-gray-700 p-4 text-sm">
                <p class="font-mono">{{ $manifest['name'] }}</p>
                <p class="text-xs text-gray-600 dark:text-gray-300 break-all">{{ $manifest['path'] }}</p>
                <form method="post" action="{{ route('admin.data-engine.governance-action') }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="action" value="rollback_manifest">
                    <input type="hidden" name="manifest_path" value="{{ $manifest['path'] }}">
                    <button class="rounded bg-rose-700 text-white px-3 py-1.5 text-xs" onclick="return confirm('Execute rollback from this manifest?')">Restore from Manifest</button>
                </form>
            </div>
        @empty
            <div class="rounded border bg-white dark:bg-gray-900 dark:border-gray-700 p-4 text-sm">No rollback manifests found.</div>
        @endforelse
        </div>
    </details>
    <h2 class="text-lg font-semibold mt-6 mb-3">Rollback Visualization</h2>
    <details open class="rounded border bg-white dark:bg-gray-900 dark:border-gray-700 p-3">
        <summary class="cursor-pointer font-semibold text-sm">Rollback history and impact</summary>
        <div class="space-y-3 mt-3">
        @forelse (($history ?? collect()) as $row)
            @php
                $before = is_array($row->before_payload) ? $row->before_payload : [];
                $after = is_array($row->after_payload) ? $row->after_payload : [];
                $validation = is_array($row->validation_payload) ? $row->validation_payload : [];
                $rollback = is_array($row->rollback_payload) ? $row->rollback_payload : [];
            @endphp
            <div class="rounded border bg-white dark:bg-gray-900 dark:border-gray-700 p-4 text-sm">
                <p class="font-semibold">Action #{{ $row->id }} · {{ $row->action }}</p>
                <p class="text-xs">State: {{ $row->workflow_state }} · Restored rows: {{ (int) (($rollback['execution']['restored_rows'] ?? 0)) }}</p>
                <p class="text-xs">Before health: {{ (int) ($before['risk_summaries']['overall_platform_health'] ?? 0) }} · After health: {{ (int) ($after['risk_summaries']['overall_platform_health'] ?? 0) }}</p>
                <p class="text-xs">Rollback confidence: {{ !empty($rollback) ? 'high' : 'medium' }} · Validation: {{ !empty($validation['validation_passed']) ? 'passed' : 'check needed' }}</p>
            </div>
        @empty
            <div class="rounded border bg-white dark:bg-gray-900 dark:border-gray-700 p-4 text-sm">No rollback history yet.</div>
        @endforelse
        </div>
    </details>
</div>
@endsection

