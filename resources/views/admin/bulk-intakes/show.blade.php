@extends('layouts.admin')

@section('content')
@php
    $activeAdminProfileTab = 'bulk';
    $progress = $progress ?? [
        'total' => 0,
        'pending' => 0,
        'processing' => 0,
        'intake_created' => 0,
        'parse_queued' => 0,
        'parsed' => 0,
        'parse_error' => 0,
        'needs_review' => 0,
        'failed' => 0,
        'completed_or_terminal' => 0,
        'percent_done' => 0,
        'approx_eta_seconds' => null,
        'approx_eta_label' => 'calculating',
        'active_work_label' => 'pending',
        'last_activity_at' => null,
        'queue_backlog' => 0,
        'failed_jobs_count' => 0,
        'worker_warning' => null,
        'user_message' => 'Bulk processing runs in background. You can leave this page open and refresh later. Website and app requests are not blocked by this batch.',
        'ocr_failed' => 0,
        'error_summary' => [],
    ];
    $candidateByItemId = $candidateByItemId ?? [];
    $duplicateHintsByItemId = $duplicateHintsByItemId ?? [];
    $screeningByItemId = $screeningByItemId ?? [];
    $screeningReviewByItemId = $screeningReviewByItemId ?? [];
    $screeningFilter = (string) ($screeningFilter ?? 'all');
    $screeningFilters = is_array($screeningFilters ?? null) ? $screeningFilters : [];
    $screeningCounts = is_array($screeningCounts ?? null) ? $screeningCounts : [];
    $statusFilter = (string) ($statusFilter ?? 'all');
    $statusFilters = is_array($statusFilters ?? null) ? $statusFilters : [];
    $missingDisplay = '—';
    $highlightItemId = (int) request()->query('highlight_item', 0);
    $buildShowUrl = static function (
        $batch,
        ?string $status = null,
        ?string $screening = null,
        ?int $highlightItem = null
    ): string {
        $params = ['bulkIntakeBatch' => $batch];
        $status ??= (string) request()->query('status', 'all');
        $screening ??= (string) request()->query('screening', 'all');
        $highlightItem ??= (int) request()->query('highlight_item', 0);

        if ($status !== '' && $status !== 'all') {
            $params['status'] = $status;
        }
        if ($screening !== '' && $screening !== 'all') {
            $params['screening'] = $screening;
        }
        if ($highlightItem > 0) {
            $params['highlight_item'] = $highlightItem;
        }

        return route('admin.bulk-intakes.show', $params);
    };
    $hasActiveFilters = $statusFilter !== 'all' || $screeningFilter !== 'all';
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

    <div class="rounded-lg bg-white p-6 shadow">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Background processing</h2>
                <p class="mt-1 text-sm text-gray-600">{{ $progress['user_message'] }}</p>
            </div>
            <span class="inline-flex w-fit rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase text-indigo-700">
                {{ $progress['active_work_label'] }}
            </span>
        </div>

        <div class="mt-4 grid gap-3 md:grid-cols-4 xl:grid-cols-6">
            @foreach ([
                'Total items' => $progress['total'],
                'Pending' => $progress['pending'],
                'Processing' => $progress['processing'],
                'Parse queued' => $progress['parse_queued'],
                'Parsed' => $progress['parsed'],
                'Needs review' => $progress['needs_review'],
                'Failed' => $progress['failed'],
                'Percent done' => $progress['percent_done'].'%',
                'Approx ETA' => $progress['approx_eta_label'],
                'Queue backlog' => $progress['queue_backlog'],
                'Last activity' => $progress['last_activity_at'] ?: $missingDisplay,
                'Failed jobs' => $progress['failed_jobs_count'],
            ] as $label => $value)
                <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        @if (! empty($progress['error_summary']))
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($progress['error_summary'] as $errorSummaryLine)
                    <span class="rounded-full border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700">{{ $errorSummaryLine }}</span>
                @endforeach
            </div>
        @endif

        @if (! empty($progress['worker_warning']))
            <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm font-medium text-amber-900">
                {{ $progress['worker_warning'] }}
            </div>
        @endif
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
                    <a href="{{ $buildShowUrl($batch, $key, $screeningFilter, $highlightItemId > 0 ? $highlightItemId : null) }}"
                       data-testid="bulk-status-filter-{{ $key }}"
                       class="rounded-full border px-3 py-1 text-xs font-semibold {{ $statusFilter === $key ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-indigo-300 hover:text-indigo-700' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="mt-4 flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2" data-testid="bulk-screening-filter-pills">
                @foreach ($screeningFilters as $key => $label)
                    <a href="{{ $buildShowUrl($batch, $statusFilter, $key, $highlightItemId > 0 ? $highlightItemId : null) }}"
                       data-testid="bulk-screening-filter-{{ $key }}"
                       class="rounded-full border px-3 py-1 text-xs font-semibold {{ $screeningFilter === $key ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-emerald-300 hover:text-emerald-700' }}">
                        {{ $label }} ({{ (int) ($screeningCounts[$key] ?? 0) }})
                    </a>
                @endforeach
                @if ($hasActiveFilters)
                    <a href="{{ $buildShowUrl($batch, 'all', 'all', $highlightItemId > 0 ? $highlightItemId : null) }}"
                       data-testid="bulk-screening-clear-filters"
                       class="rounded-full border border-gray-300 bg-white px-3 py-1 text-xs font-semibold text-gray-700 hover:border-gray-400 hover:text-gray-900">
                        Clear filters
                    </a>
                @endif
            </div>
        </div>
        <p class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
            Current stage: candidate extraction and review. Owner assignment and profile creation are later steps.
            Candidate fields appear after free parse completes. Manual transcript is only needed if OCR/free parse fails.
            Bulk processing runs in background. You can leave this page open and refresh later.
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
                                    'display_source' => 'parsed_json',
                                    'reviewed_snapshot_present' => false,
                                    'missing_fields' => [],
                                    'name_source' => null,
                                    'name_needs_review' => false,
                                    'dob_needs_review' => false,
                                    'height_needs_review' => false,
                                    'education_needs_review' => false,
                                    'occupation_needs_review' => false,
                                    'display_warnings' => [],
                                ];
                                $screening = is_array($screeningByItemId[$item->id] ?? null) ? $screeningByItemId[$item->id] : [
                                    'decision' => 'review',
                                    'label' => 'Needs review',
                                    'reasons' => [
                                        ['code' => 'parsed_json_missing', 'label' => 'Parsed JSON missing'],
                                    ],
                                    'suggested_next_action' => 'Review: Parser output is not ready.',
                                ];
                                $screeningDecision = (string) ($screening['decision'] ?? 'review');
                                $screeningBadgeClass = match ($screeningDecision) {
                                    'eligible' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                    'stop' => 'border-red-200 bg-red-50 text-red-700',
                                    default => 'border-amber-200 bg-amber-50 text-amber-800',
                                };
                                $screeningReasonChipClass = match ($screeningDecision) {
                                    'eligible' => 'border-emerald-100 bg-white text-emerald-700',
                                    'stop' => 'border-red-100 bg-white text-red-700',
                                    default => 'border-amber-100 bg-white text-amber-800',
                                };
                                $screeningReasons = is_array($screening['reasons'] ?? null) ? array_slice($screening['reasons'], 0, 2) : [];
                                $manualScreeningReview = is_array($screeningReviewByItemId[$item->id] ?? null) ? $screeningReviewByItemId[$item->id] : null;
                                $manualScreeningActive = $manualScreeningReview !== null;
                                $manualScreeningStatus = (string) ($manualScreeningReview['status'] ?? '');
                                $manualScreeningLabel = match ($manualScreeningStatus) {
                                    'eligible_for_consent' => 'Eligible for consent',
                                    'needs_review' => 'Needs review',
                                    'stopped' => 'Stopped',
                                    default => 'Manual screening',
                                };
                                $manualScreeningBadgeClass = match ($manualScreeningStatus) {
                                    'eligible_for_consent' => 'border-emerald-300 bg-emerald-100 text-emerald-800',
                                    'stopped' => 'border-red-300 bg-red-100 text-red-800',
                                    default => 'border-amber-300 bg-amber-100 text-amber-900',
                                };
                                $duplicateHints = is_array($duplicateHintsByItemId[$item->id] ?? null) ? $duplicateHintsByItemId[$item->id] : [];
                                $manualDuplicateReview = is_array(data_get($itemMeta, 'duplicate_review')) ? data_get($itemMeta, 'duplicate_review') : [];
                                $manualDuplicateActive = (string) data_get($manualDuplicateReview, 'status') === 'manual_duplicate';
                                $primaryDuplicateHint = is_array($duplicateHints[0] ?? null) ? $duplicateHints[0] : [];
                                $hasParsedJson = (bool) ($candidate['parsed_json_present'] ?? false);
                                $usesReviewedSnapshot = ($candidate['display_source'] ?? null) === 'approval_snapshot_json';
                                $parseStatus = (string) ($candidate['parse_status'] ?? $intake?->parse_status ?? '');
                                $itemDisplayStatus = match (true) {
                                    $parseStatus === 'parsed' && $hasParsedJson => 'parsed',
                                    $item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PENDING => 'pending',
                                    $item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PROCESSING => 'processing',
                                    $parseStatus !== '' => str_replace('_', ' ', $parseStatus),
                                    default => str_replace('_', ' ', (string) $item->item_status),
                                };
                                $itemDisplayLabel = $item->input_type === \App\Models\BulkIntakeBatchItem::INPUT_TEXT
                                    ? 'Text item #'.$item->item_sequence
                                    : ($item->original_filename ?: $missingDisplay);
                                $textPreview = null;
                                if ($item->input_type === \App\Models\BulkIntakeBatchItem::INPUT_TEXT && filled($item->summary_text)) {
                                    $textPreview = \Illuminate\Support\Str::limit((string) $item->summary_text, 80);
                                }
                                $exceptionBadges = [];
                                $waitingForBackgroundIntake = ! $intake && in_array((string) $item->item_status, [
                                    \App\Models\BulkIntakeBatchItem::STATUS_PENDING,
                                    \App\Models\BulkIntakeBatchItem::STATUS_PROCESSING,
                                ], true);
                                if (! $intake && ! $waitingForBackgroundIntake) {
                                    $exceptionBadges[] = ['label' => 'Missing linked intake', 'class' => 'border-red-200 bg-red-50 text-red-700'];
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
                                if ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_NEEDS_REVIEW) {
                                    $exceptionBadges[] = ['label' => 'Needs review', 'class' => 'border-amber-200 bg-amber-50 text-amber-800'];
                                }
                                if ($manualDuplicateActive) {
                                    $exceptionBadges[] = [
                                        'label' => 'Manual duplicate',
                                        'class' => 'border-rose-200 bg-rose-50 text-rose-700',
                                        'testid' => 'bulk-manual-duplicate-badge',
                                    ];
                                }
                                foreach (array_slice($duplicateHints, 0, 2) as $duplicateHint) {
                                    $exceptionBadges[] = [
                                        'label' => (string) ($duplicateHint['label'] ?? 'Possible duplicate'),
                                        'class' => 'border-purple-200 bg-purple-50 text-purple-700',
                                        'testid' => 'bulk-duplicate-history-hint',
                                    ];
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
                                $isHighlightedItem = $highlightItemId === (int) $item->id;
                            @endphp
                            <tr id="bulk-item-{{ $item->id }}" @if ($isHighlightedItem) style="background-color: #ecfdf5;" @endif>
                                <td class="px-4 py-2 text-sm text-gray-900">{{ $item->item_sequence }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $itemDisplayLabel }}</span>
                                    <span class="block text-xs text-gray-500">{{ $item->input_type }} · {{ $itemDisplayStatus }}</span>
                                    @if ($textPreview)
                                        <span class="block max-w-xs truncate text-xs text-gray-400">{{ $textPreview }}</span>
                                    @endif
                                    @if ($item->failure_code)
                                        <span class="block max-w-xs truncate text-xs text-red-700" title="{{ $item->failure_message }}">{{ $item->failure_code }}: {{ $item->failure_message }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $candidate['full_name'] ?? $missingDisplay }}</span>
                                    @if ($usesReviewedSnapshot)
                                        <span data-testid="bulk-candidate-reviewed-badge" class="ml-1 rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-700">Reviewed</span>
                                    @endif
                                    @if (($candidate['name_needs_review'] ?? false))
                                        <span class="ml-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700">review</span>
                                    @endif
                                    <span class="block text-xs text-gray-500">Mobile: {{ $candidate['mobile'] ?? $missingDisplay }}</span>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $candidate['date_of_birth'] ?? $missingDisplay }}</span>
                                    @if (($candidate['dob_needs_review'] ?? false))
                                        <span class="ml-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700">review</span>
                                    @endif
                                    <span class="block text-xs text-gray-500">Age: {{ $candidate['age'] ?? $missingDisplay }}</span>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $candidate['height'] ?? $missingDisplay }}</span>
                                    @if (($candidate['height_needs_review'] ?? false))
                                        <span class="ml-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700">review</span>
                                    @endif
                                    <span class="block text-xs text-gray-500">Gender: {{ $candidate['gender'] ?? $missingDisplay }}</span>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $candidate['city'] ?? $missingDisplay }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <span class="font-medium">{{ $candidate['education'] ?? $missingDisplay }}</span>
                                    @if (($candidate['education_needs_review'] ?? false))
                                        <span class="ml-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700">review</span>
                                    @endif
                                    <span class="block text-xs text-gray-500">
                                        {{ $candidate['occupation'] ?? $missingDisplay }}
                                        @if (($candidate['occupation_needs_review'] ?? false))
                                            <span class="ml-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700">review</span>
                                        @endif
                                    </span>
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
                                        @if ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PENDING)
                                            <span class="text-gray-500">Waiting for background processing</span>
                                        @elseif ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PROCESSING)
                                            <span class="text-amber-700">OCR/parse preparation running</span>
                                        @else
                                            <span class="text-red-700">Missing linked intake</span>
                                        @endif
                                        <span class="block text-xs text-gray-500">Parsed JSON: No</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    <div class="flex max-w-xs flex-wrap gap-1">
                                        @if ($manualScreeningActive)
                                            <span data-testid="bulk-manual-screening-badge" class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $manualScreeningBadgeClass }}">{{ $manualScreeningLabel }}</span>
                                            @foreach ($screeningReasons as $screeningReason)
                                                <span data-testid="bulk-screening-advisor-hint" class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] font-medium text-gray-600">{{ $screeningReason['label'] ?? str_replace('_', ' ', (string) ($screeningReason['code'] ?? 'review')) }}</span>
                                            @endforeach
                                        @else
                                            <span data-testid="bulk-screening-badge" class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $screeningBadgeClass }}">{{ $screening['label'] ?? 'Needs review' }}</span>
                                            @foreach ($screeningReasons as $screeningReason)
                                                <span data-testid="bulk-screening-reason" class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $screeningReasonChipClass }}">{{ $screeningReason['label'] ?? str_replace('_', ' ', (string) ($screeningReason['code'] ?? 'review')) }}</span>
                                            @endforeach
                                        @endif
                                        @if ($exceptionBadges !== [])
                                            @foreach ($exceptionBadges as $badge)
                                                <span @if (! empty($badge['testid'])) data-testid="{{ $badge['testid'] }}" @endif class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                            @endforeach
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ (int) ($sourceContextCountsByItem[$item->id] ?? 0) }}</td>
                                <td class="px-4 py-2 text-sm">
                                    <div class="flex min-w-40 flex-col gap-2">
                                        @if ($intake)
                                            <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Open intake review</a>
                                            <a href="{{ route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]) }}" class="font-medium text-emerald-700 hover:text-emerald-900">Correct candidate</a>
                                        @endif
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

                                        @if ($manualDuplicateActive)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-duplicate', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" class="text-left text-sm font-medium text-rose-700 hover:text-rose-900">Clear duplicate</button>
                                            </form>
                                        @elseif ($duplicateHints !== [])
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.mark-duplicate', [$batch, $item]) }}">
                                                @csrf
                                                @if (! empty($primaryDuplicateHint['matched_intake_id']))
                                                    <input type="hidden" name="matched_biodata_intake_id" value="{{ (int) $primaryDuplicateHint['matched_intake_id'] }}">
                                                @endif
                                                @if (! empty($primaryDuplicateHint['matched_profile_id']))
                                                    <input type="hidden" name="matched_profile_id" value="{{ (int) $primaryDuplicateHint['matched_profile_id'] }}">
                                                @endif
                                                <input type="hidden" name="reason" value="{{ trim('Duplicate/history hint: '.(string) ($primaryDuplicateHint['label'] ?? 'Possible duplicate')) }}">
                                                <button type="submit" class="text-left text-sm font-medium text-rose-700 hover:text-rose-900">Mark duplicate</button>
                                            </form>
                                        @endif

                                        @if ($manualScreeningActive)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-screening-review', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" class="text-left text-sm font-medium text-indigo-700 hover:text-indigo-900">Clear screening</button>
                                            </form>
                                        @elseif ($intake)
                                            <a href="{{ route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]).'#bulk-manual-screening-card' }}" class="font-medium text-indigo-700 hover:text-indigo-900">Set screening</a>
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
