@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
    $reviewSummary = $reviewSummary ?? [
        'total' => 0,
        'unclaimed' => 0,
        'claimed' => 0,
        'intakes_created' => 0,
        'parse_pending' => 0,
        'parse_queued' => 0,
        'parsed' => 0,
        'parse_error' => 0,
        'needs_review' => 0,
        'failed' => 0,
    ];
    $readinessSummary = $readinessSummary ?? [
        'ready_for_profile_review' => 0,
        'not_ready' => 0,
        'blocked' => 0,
        'owner_missing' => 0,
        'parse_pending_error' => 0,
    ];
    $readinessByItem = $readinessByItem ?? [];
    $candidateByItemId = $candidateByItemId ?? [];
    $missingDisplay = '—';
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
@endphp
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Bulk Intake #{{ $batch->id }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ $batch->batch_name ?: 'Untitled batch' }}</p>
        </div>
        <a href="{{ route('admin.bulk-intakes.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Back to bulk intakes</a>
    </div>

    @include('admin.intake._tabs')

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        This page is review visibility only. It does not create, approve, claim, or apply profiles.
    </div>

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        Readiness preview does not create, approve, or apply profiles.
    </div>

    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p>This queues parser from existing OCR/raw text only. Paid Sarvam/OpenAI vision extraction is not called.</p>
            <form method="POST" action="{{ route('admin.bulk-intakes.queue-free-parse', $batch) }}">
                @csrf
                <button type="submit" class="rounded-lg bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                    Queue free parse for pending items
                </button>
            </form>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-9">
        @foreach ([
            'Total Items' => $reviewSummary['total'],
            'Unclaimed' => $reviewSummary['unclaimed'],
            'Claimed' => $reviewSummary['claimed'],
            'Intakes Created' => $reviewSummary['intakes_created'],
            'Parse Pending' => $reviewSummary['parse_pending'],
            'Parse Queued' => $reviewSummary['parse_queued'],
            'Parsed' => $reviewSummary['parsed'],
            'Parse Errors' => $reviewSummary['parse_error'],
            'Needs Review' => $reviewSummary['needs_review'],
            'Failed' => $reviewSummary['failed'],
        ] as $label => $value)
            <div class="rounded-lg bg-white p-4 shadow">
                <p class="text-xs font-semibold uppercase text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            'Ready for Profile Review' => $readinessSummary['ready_for_profile_review'],
            'Blocked' => $readinessSummary['blocked'],
            'Owner Missing' => $readinessSummary['owner_missing'],
            'Parse Pending/Error' => $readinessSummary['parse_pending_error'],
        ] as $label => $value)
            <div class="rounded-lg bg-white p-4 shadow">
                <p class="text-xs font-semibold uppercase text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <dl class="grid gap-4 text-sm md:grid-cols-3">
            <div>
                <dt class="font-semibold text-gray-700">Status</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->batch_status }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Uploader</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->uploaded_by_actor_type ?: '-' }} · {{ $batch->uploadedByUser?->name ?? '-' }}{{ $batch->uploadedByUser?->email ? ' · '.$batch->uploadedByUser->email : '' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Created</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->created_at?->format('d-m-Y H:i') ?? '-' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Completed</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->completed_at?->format('d-m-Y H:i') ?? '-' }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Files</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->total_files }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-700">Texts</dt>
                <dd class="mt-1 text-gray-600">{{ $batch->total_texts }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-lg bg-white p-6 shadow">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <h2 class="text-lg font-semibold text-gray-900">Items</h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($statusFilters as $key => $label)
                    <a href="{{ route('admin.bulk-intakes.show', ['bulkIntakeBatch' => $batch, 'status' => $key]) }}"
                       class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusFilter === $key ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-indigo-300 hover:text-indigo-700' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
        <p class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
            Current stage: candidate extraction and review. Owner assignment and profile creation are later steps.
            Candidate fields appear after free parse completes. Manual transcript is only needed if OCR/free parse fails.
        </p>

        @if ($batch->items->isEmpty())
            <p class="mt-3 text-sm text-gray-600">No items found for this filter.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Seq</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">File/Text</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Candidate</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">DOB / Age</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Height / Gender</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">City</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Education / Occupation</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Parse</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Profile Readiness</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Exceptions</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Source</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-gray-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach ($batch->items as $item)
                            @php
                                $intake = $item->biodataIntake;
                                $itemMeta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
                                $candidate = $candidateByItemId[$item->id] ?? [
                                    'full_name' => null,
                                    'mobile' => null,
                                    'date_of_birth' => null,
                                    'age' => null,
                                    'height' => null,
                                    'gender' => null,
                                    'city' => null,
                                    'education' => null,
                                    'occupation' => null,
                                    'parse_status' => $intake?->parse_status,
                                    'parsed_json_present' => false,
                                    'missing_fields' => [],
                                ];
                                $hasParsedJson = (bool) ($candidate['parsed_json_present'] ?? false);
                                $parseStatus = (string) ($candidate['parse_status'] ?? $intake?->parse_status ?? '');
                                $readiness = $readinessByItem[$item->id] ?? [
                                    'status' => 'not_ready',
                                    'reason_codes' => [],
                                    'display_reasons' => [],
                                ];
                                $readinessStatus = $readiness['status'] ?? 'not_ready';
                                $exceptionBadges = [];
                                if (! $intake) {
                                    $exceptionBadges[] = ['label' => 'Missing linked intake', 'class' => 'border-red-200 bg-red-50 text-red-700'];
                                } elseif ($intake->uploaded_by === null) {
                                    $exceptionBadges[] = ['label' => 'Unclaimed / consent pending', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'];
                                }
                                if ($intake && (string) $intake->parse_status === 'error') {
                                    $exceptionBadges[] = ['label' => 'Parse error', 'class' => 'border-red-200 bg-red-50 text-red-700'];
                                }
                                if ($intake && filled($intake->last_error)) {
                                    $exceptionBadges[] = ['label' => 'Intake last_error present', 'class' => 'border-red-200 bg-red-50 text-red-700'];
                                }
                                if ($intake && (string) $intake->parse_status === 'parsed' && ! $hasParsedJson) {
                                    $exceptionBadges[] = ['label' => 'Parsed JSON missing', 'class' => 'border-orange-200 bg-orange-50 text-orange-700'];
                                }
                                $hasEmptyOcrFailure = (string) $item->failure_code === 'empty_ocr_text'
                                    || (
                                        (string) $item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_NEEDS_REVIEW
                                        && (string) data_get($itemMeta, 'ocr_failure_code') === 'empty_ocr_text'
                                    );
                                if ($hasEmptyOcrFailure) {
                                    $exceptionBadges[] = ['label' => 'OCR failed / no text extracted', 'class' => 'border-red-200 bg-red-50 text-red-700'];
                                }
                                $lastError = (string) ($intake?->last_error ?? '');
                                $canAddManualTranscript = $intake && (
                                    (string) $intake->parse_status === 'error'
                                    || ((string) $intake->parse_status === 'parsed' && ! $hasParsedJson)
                                    || filled($lastError)
                                    || str_contains($lastError, 'empty_text')
                                    || str_contains($lastError, 'reparse_no_canonical_or_raw_ocr')
                                    || $hasEmptyOcrFailure
                                );
                            @endphp
                            <tr>
                                <td class="px-4 py-2 text-sm text-gray-900">{{ $item->item_sequence }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $item->original_filename ?: ($item->summary_text ?: $missingDisplay) }}</span>
                                    <span class="block text-xs text-gray-500">{{ $item->input_type }} · {{ $item->item_status }}</span>
                                    @if ($item->failure_code)
                                        <span class="block max-w-xs truncate text-xs text-red-700" title="{{ $item->failure_message }}">{{ $item->failure_code }}: {{ $item->failure_message }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $candidate['full_name'] ?? $missingDisplay }}</span>
                                    <span class="block text-xs text-gray-500">Mobile: {{ $candidate['mobile'] ?? $missingDisplay }}</span>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $candidate['date_of_birth'] ?? $missingDisplay }}</span>
                                    <span class="block text-xs text-gray-500">Age: {{ $candidate['age'] ?? $missingDisplay }}</span>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $candidate['height'] ?? $missingDisplay }}</span>
                                    <span class="block text-xs text-gray-500">Gender: {{ $candidate['gender'] ?? $missingDisplay }}</span>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $candidate['city'] ?? $missingDisplay }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $candidate['education'] ?? $missingDisplay }}</span>
                                    <span class="block text-xs text-gray-500">{{ $candidate['occupation'] ?? $missingDisplay }}</span>
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($intake)
                                        <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">#{{ $intake->id }}</a>
                                        @if ($parseStatus === 'parsed' && $hasParsedJson)
                                            <span class="block text-xs font-medium text-green-700">Parse: OK</span>
                                        @elseif ($hasEmptyOcrFailure)
                                            <span class="block text-xs font-medium text-red-700">OCR failed: no text extracted</span>
                                        @elseif ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
                                            <span class="block text-xs font-medium text-amber-700">Free parse queued</span>
                                        @elseif ($parseStatus === 'pending')
                                            <span class="block text-xs text-gray-500">Waiting for free parse</span>
                                        @else
                                            <span class="block text-xs text-gray-500">Parse: {{ $parseStatus !== '' ? $parseStatus : $missingDisplay }}</span>
                                        @endif
                                        <span class="block text-xs text-gray-500">Parsed JSON: {{ $hasParsedJson ? 'Yes' : 'No' }}</span>
                                        @if ($intake->last_error)
                                            <span class="block max-w-xs truncate text-xs text-red-700" title="{{ $intake->last_error }}">{{ \Illuminate\Support\Str::limit((string) $intake->last_error, 90) }}</span>
                                        @endif
                                    @else
                                        <span class="text-red-700">Missing linked intake</span>
                                        <span class="block text-xs text-gray-500">Parsed JSON: No</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $readinessStatusClasses[$readinessStatus] ?? $readinessStatusClasses['not_ready'] }}">
                                        {{ $readinessStatusLabels[$readinessStatus] ?? 'Not ready' }}
                                    </span>
                                    @if ($readinessStatus === 'not_ready' && $hasParsedJson && (string) $intake?->parse_status === 'parsed')
                                        <span class="block text-xs text-gray-500">Candidate extraction is complete; profile readiness is a later step.</span>
                                    @endif
                                    @if (! empty($readiness['reason_codes']))
                                        <div class="mt-2 flex max-w-xs flex-wrap gap-1">
                                            @foreach ($readiness['reason_codes'] as $reasonCode)
                                                <span class="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-[11px] font-mono text-gray-700">{{ $reasonCode }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if (! empty($readiness['display_reasons']))
                                        <ul class="mt-1 max-w-xs list-disc space-y-0.5 pl-4 text-xs text-gray-600">
                                            @foreach ($readiness['display_reasons'] as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($exceptionBadges === [])
                                        <span class="text-gray-400">-</span>
                                    @else
                                        <div class="flex max-w-xs flex-wrap gap-1">
                                            @foreach ($exceptionBadges as $badge)
                                                <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ (int) ($sourceContextCountsByItem[$item->id] ?? 0) }}</td>
                                <td class="px-4 py-2 text-sm">
                                    <div class="flex min-w-40 flex-col gap-2">
                                        @if ($intake)
                                            <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Open intake review</a>
                                        @endif
                                        <a href="{{ route('admin.bulk-intakes.items.readiness', [$batch, $item]) }}" class="font-medium text-slate-700 hover:text-slate-900">Profile Readiness details</a>
                                        @if ($canAddManualTranscript)
                                            <a href="{{ route('admin.bulk-intakes.items.manual-transcript', [$batch, $item]) }}" class="font-medium text-orange-700 hover:text-orange-900">Add manual transcript (OCR failed fallback)</a>
                                        @endif

                                        @if ($intake && $intake->parse_status === 'pending' && $item->item_status !== \App\Models\BulkIntakeBatchItem::STATUS_PARSE_QUEUED && ! $intake->approved_by_user && ! $intake->intake_locked)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.queue-free-parse', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" class="text-left text-sm font-medium text-indigo-600 hover:text-indigo-800">Queue free parse item</button>
                                            </form>
                                        @endif

                                        @if ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_NEEDS_REVIEW)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-needs-review', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" class="text-left text-sm font-medium text-green-700 hover:text-green-900">Clear needs review</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.mark-needs-review', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" class="text-left text-sm font-medium text-amber-700 hover:text-amber-900">Mark needs review</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
