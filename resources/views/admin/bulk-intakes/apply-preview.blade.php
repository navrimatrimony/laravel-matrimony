@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
    $summary = $preview['summary'] ?? [
        'total_fields' => 0,
        'changed_fields' => 0,
        'safe_count' => 0,
        'review_count' => 0,
        'blocked_count' => 0,
    ];
    $riskClasses = [
        'safe' => 'border-green-200 bg-green-50 text-green-800',
        'review' => 'border-amber-200 bg-amber-50 text-amber-800',
        'blocked' => 'border-red-200 bg-red-50 text-red-700',
    ];
@endphp

<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Bulk Intake Apply Preview</h1>
            <p class="mt-1 text-sm text-gray-600">Bulk Intake #{{ $batch->id }}{{ $batch->batch_name ? ' · '.$batch->batch_name : '' }}</p>
        </div>
        <div class="flex flex-wrap gap-3 text-sm">
            @if ($intake)
                <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Open intake review</a>
            @endif
            <a href="{{ route('admin.bulk-intakes.show', $batch) }}" class="font-medium text-slate-700 hover:text-slate-900">Back to bulk intake</a>
        </div>
    </div>

    @include('admin.intake._tabs')

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm font-medium text-blue-800">
        Preview only. No profile fields are changed on this page.
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <dl class="grid gap-4 text-sm md:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="font-semibold text-gray-700">Item</dt>
                <dd class="mt-1 text-gray-600">#{{ $item->item_sequence }} · {{ $item->item_status }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Intake</dt>
                <dd class="mt-1 text-gray-600">{{ $intake ? '#'.$intake->id : '-' }} · {{ $intake?->parse_status ?? '-' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Profile</dt>
                <dd class="mt-1 text-gray-600">{{ $profile ? '#'.$profile->id : '-' }} · {{ $profile?->lifecycle_state ?? '-' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Owner</dt>
                <dd class="mt-1 text-gray-600">
                    @if ($intake?->uploadedByUser)
                        {{ $intake->uploadedByUser->name }} · {{ $intake->uploadedByUser->mobile ?: ($intake->uploadedByUser->email ?: '-') }}
                    @else
                        -
                    @endif
                </dd>
            </div>
        </dl>
    </div>

    @if (! empty($preview['blocked_reasons']))
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Preview is blocked for this item.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($preview['blocked_reasons'] as $reason)
                    <span class="rounded border border-amber-300 bg-white px-2 py-1 font-mono text-xs text-amber-900">{{ $reason }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-5">
        @foreach ([
            'Total Fields' => $summary['total_fields'],
            'Changed' => $summary['changed_fields'],
            'Safe' => $summary['safe_count'],
            'Review' => $summary['review_count'],
            'Blocked' => $summary['blocked_count'],
        ] as $label => $value)
            <div class="rounded-lg bg-white p-4 shadow">
                <p class="text-xs font-semibold uppercase text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">Parsed Field Diff</h2>

        @if (empty($preview['groups']))
            <p class="mt-3 text-sm text-gray-600">No previewable parsed fields are available.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Group</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Field</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Current Value</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Parsed Value</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Changed</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Risk</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Reasons</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach ($preview['groups'] as $group => $rows)
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="px-4 py-2 text-sm font-medium text-gray-800">{{ $group }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-700">
                                        <span class="font-medium">{{ $row['label'] }}</span>
                                        <span class="block font-mono text-xs text-gray-500">{{ $row['field'] }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-sm text-gray-700">{{ $row['current_display'] }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-900">{{ $row['proposed_display'] }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-700">{{ $row['changed'] ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-2 text-sm">
                                        <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $riskClasses[$row['risk']] ?? $riskClasses['review'] }}">{{ $row['risk'] }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-sm">
                                        <div class="flex max-w-xs flex-wrap gap-1">
                                            @foreach ($row['reason_codes'] as $reasonCode)
                                                <span class="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-mono text-[11px] text-gray-700">{{ $reasonCode }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
