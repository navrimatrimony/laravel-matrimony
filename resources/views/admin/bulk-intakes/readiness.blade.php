@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
    $readinessStatusLabels = [
        'ready_for_profile_review' => 'Ready for profile review',
        'not_ready' => 'Not ready',
        'blocked' => 'Blocked',
    ];
    $readinessStatusClasses = [
        'ready_for_profile_review' => 'border-green-200 bg-green-50 text-green-800',
        'not_ready' => 'border-amber-200 bg-amber-50 text-amber-800',
        'blocked' => 'border-red-200 bg-red-50 text-red-700',
    ];
    $readinessStatus = $readiness['status'] ?? 'not_ready';
@endphp
<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Bulk Intake Readiness</h1>
            <p class="mt-1 text-sm text-gray-600">Bulk Intake #{{ $batch->id }}{{ $batch->batch_name ? ' · '.$batch->batch_name : '' }}</p>
        </div>
        <a href="{{ route('admin.bulk-intakes.show', $batch) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Back to bulk intake</a>
    </div>

    @include('admin.intake._tabs')

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        Readiness preview does not create, approve, or apply profiles.
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Item #{{ $item->item_sequence }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ $item->input_type }} · {{ $item->original_filename ?: '-' }}</p>
            </div>
            <span class="w-fit rounded-full border px-3 py-1 text-xs font-semibold {{ $readinessStatusClasses[$readinessStatus] ?? $readinessStatusClasses['not_ready'] }}">
                {{ $readinessStatusLabels[$readinessStatus] ?? 'Not ready' }}
            </span>
        </div>

        <dl class="mt-6 grid gap-4 text-sm md:grid-cols-2">
            <div>
                <dt class="font-semibold text-gray-700">Linked intake</dt>
                <dd class="mt-1 text-gray-600">{{ $intake ? '#'.$intake->id : '-' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Parse status</dt>
                <dd class="mt-1 text-gray-600">{{ $intake?->parse_status ?? '-' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Owner</dt>
                <dd class="mt-1 text-gray-600">
                    @if ($intake?->uploadedByUser)
                        {{ $intake->uploadedByUser->name }} · {{ $intake->uploadedByUser->mobile ?: ($intake->uploadedByUser->email ?: '-') }}
                    @elseif ($intake)
                        Unassigned
                    @else
                        -
                    @endif
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Parsed JSON</dt>
                <dd class="mt-1 text-gray-600">{{ $intake && ! empty($intake->parsed_json) ? 'Present' : 'Missing' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Intake locked</dt>
                <dd class="mt-1 text-gray-600">{{ $intake && $intake->intake_locked ? 'Yes' : 'No' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Approved already</dt>
                <dd class="mt-1 text-gray-600">{{ $intake && $intake->approved_by_user ? 'Yes' : 'No' }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">Reasons</h2>
        @if (empty($readiness['reason_codes']))
            <p class="mt-3 text-sm text-green-700">No blocking or not-ready reasons found.</p>
        @else
            <div class="mt-4 space-y-3">
                @foreach ($readiness['reason_codes'] as $index => $reasonCode)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <p class="font-mono text-xs text-gray-700">{{ $reasonCode }}</p>
                        <p class="mt-1 text-sm text-gray-700">{{ $readiness['display_reasons'][$index] ?? $reasonCode }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
