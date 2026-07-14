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
    $duplicateGateByItemId = $duplicateGateByItemId ?? [];
    $duplicateVerificationByItemId = $duplicateVerificationByItemId ?? [];
    $pipelineByItemId = $pipelineByItemId ?? [];
    $ocrEnsembleBadgesByItemId = $ocrEnsembleBadgesByItemId ?? [];
    $screeningReviewByItemId = $screeningReviewByItemId ?? [];
    $eligiblePipelineCount = (int) ($eligiblePipelineCount ?? 0);
    $whatsappConsentByItemId = is_array($whatsappConsentByItemId ?? null) ? $whatsappConsentByItemId : [];
    $contactPlanByItemId = is_array($contactPlanByItemId ?? null) ? $contactPlanByItemId : [];
    $whatsappEligibleToSendCount = (int) ($whatsappEligibleToSendCount ?? 0);
    $whatsappManualTestEnabled = (bool) ($whatsappManualTestEnabled ?? false);
    $whatsappSendModeLabel = (string) ($whatsappSendModeLabel ?? '');
    $registrationByItemId = is_array($registrationByItemId ?? null) ? $registrationByItemId : [];
    $screeningFilter = (string) ($screeningFilter ?? 'all');
    $primaryScreeningFilters = is_array($primaryScreeningFilters ?? null) ? $primaryScreeningFilters : [];
    $screeningCounts = is_array($screeningCounts ?? null) ? $screeningCounts : [];
    $statusFilter = (string) ($statusFilter ?? 'all');
    $statusFilters = is_array($statusFilters ?? null) ? $statusFilters : [];
    $missingDisplay = '—';
    $highlightItemId = (int) request()->query('highlight_item', 0);
    $autoSuggestionByItemId = is_array($autoSuggestionByItemId ?? null) ? $autoSuggestionByItemId : [];
    $batchCoachSummary = is_array($batchCoachSummary ?? null) ? $batchCoachSummary : [
        'total' => 0,
        'needs_check' => 0,
        'eligible' => 0,
        'blocked' => 0,
        'missing_mobile' => 0,
        'duplicate_hints' => 0,
        'history_blocks' => 0,
        'action_hint' => '',
    ];
    $workQueueFilterOrder = [
        \App\Services\Intake\BulkIntakeEligibilityService::FILTER_NEEDS_CHECK,
        \App\Services\Intake\BulkIntakeEligibilityService::FILTER_ELIGIBLE,
        \App\Services\Intake\BulkIntakeEligibilityService::FILTER_BLOCKED,
        \App\Services\Intake\BulkIntakeEligibilityService::FILTER_ALL,
    ];
    $workQueueFilterLabels = [
        \App\Services\Intake\BulkIntakeEligibilityService::FILTER_NEEDS_CHECK => 'तपासा',
        \App\Services\Intake\BulkIntakeEligibilityService::FILTER_ELIGIBLE => 'WhatsApp तयार',
        \App\Services\Intake\BulkIntakeEligibilityService::FILTER_BLOCKED => 'थांबवले',
        \App\Services\Intake\BulkIntakeEligibilityService::FILTER_ALL => 'सर्व',
    ];
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
<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Bulk Intake #{{ $batch->id }}</h1>
            <p class="mt-1 text-sm text-gray-600">
                {{ $batch->batch_name ?: 'Untitled batch' }}
                · {{ $batch->batch_status }}
                · {{ $batch->uploadedByUser?->name ?? 'Unknown uploader' }}
                · {{ $batch->created_at?->format('d-m-Y H:i') ?? '-' }}
            </p>
            <p class="mt-1 text-xs text-gray-500">Review visibility only — does not create profiles. Free parse uses existing OCR text only.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($whatsappEligibleToSendCount > 0)
                <form method="POST" action="{{ route('admin.bulk-intakes.send-whatsapp-permission-batch', $batch) }}">
                    @csrf
                    <button
                        type="submit"
                        data-testid="bulk-send-whatsapp-permission-batch"
                        class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-emerald-100"
                    >Send permission ({{ $whatsappEligibleToSendCount }})</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.bulk-intakes.queue-free-parse', $batch) }}">
                @csrf
                <button type="submit" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                    Queue free parse
                </button>
            </form>
            <a href="{{ route('admin.bulk-intakes.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Back to bulk intakes</a>
        </div>
    </div>

    @include('admin.intake._tabs')

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    @if ($whatsappManualTestEnabled)
        <div data-testid="bulk-whatsapp-manual-test-banner" class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
            <p class="font-semibold">{{ $whatsappSendModeLabel }}</p>
            <p class="mt-1 text-xs text-sky-800">
                Meta API key नसल्यामुळे message phone वर automatic जात नाही. प्रत्येक eligible row वर
                <strong>Send permission</strong> → <strong>Open on my WhatsApp</strong> → message तुमच्या phone वर पहा.
                नंतर <strong>Simulate user reply</strong> बटणांनी user ने काय केले ते test करा.
                Meta API key टाकल्यावर हेच Send permission automatic live होईल.
            </p>
        </div>
    @endif

    @if (! empty($progress['worker_warning']))
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs font-medium text-amber-900">
            {{ $progress['worker_warning'] }}
        </div>
    @endif

    <details class="rounded-lg bg-white p-4 shadow">
        <summary class="cursor-pointer text-sm font-semibold text-gray-900">
            Background processing · {{ $progress['percent_done'] }}% done · ETA {{ $progress['approx_eta_label'] }}
        </summary>
        <p class="mt-2 text-xs text-gray-600">{{ $progress['user_message'] }}</p>
        <div class="mt-3 grid gap-2 text-xs sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                'Total items' => $progress['total'],
                'Pending' => $progress['pending'],
                'Processing' => $progress['processing'],
                'Parse queued' => $progress['parse_queued'],
                'Parsed' => $progress['parsed'],
                'Needs check' => $progress['needs_review'],
                'Failed' => $progress['failed'],
                'Percent done' => $progress['percent_done'].'%',
                'Approx ETA' => $progress['approx_eta_label'],
                'Queue backlog' => $progress['queue_backlog'],
                'Last activity' => $progress['last_activity_at'] ?: $missingDisplay,
                'Failed jobs' => $progress['failed_jobs_count'],
            ] as $label => $value)
                <div class="rounded border border-gray-200 bg-gray-50 px-3 py-2">
                    <p class="font-semibold uppercase text-gray-500">{{ $label }}</p>
                    <p class="mt-0.5 font-semibold text-gray-900">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        @if (! empty($progress['error_summary']))
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($progress['error_summary'] as $errorSummaryLine)
                    <span class="rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700">{{ $errorSummaryLine }}</span>
                @endforeach
            </div>
        @endif

        @if (! empty($progress['worker_warning']))
            <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs font-medium text-amber-900">
                {{ $progress['worker_warning'] }}
            </div>
        @endif
    </details>

    <div class="rounded-lg bg-white p-4 shadow">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold text-gray-900">Items</h2>
                <div class="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-800" data-testid="bulk-batch-coach-summary">
                    <p class="font-semibold text-slate-900">
                        {{ $batchCoachSummary['total'] }} upload
                        · {{ $batchCoachSummary['needs_check'] }} तपासा
                        · {{ $batchCoachSummary['blocked'] }} थांबवले
                        · <span data-testid="bulk-eligible-pipeline-count">{{ $batchCoachSummary['eligible'] }}</span> eligible for WhatsApp
                    </p>
                    <p class="mt-1 text-slate-700">
                        @if ($batchCoachSummary['missing_mobile'] > 0)
                            {{ $batchCoachSummary['missing_mobile'] }} ला mobile नाही.
                        @endif
                        @if ($batchCoachSummary['duplicate_hints'] > 0)
                            {{ $batchCoachSummary['duplicate_hints'] }} duplicate hint.
                        @endif
                        @if ($batchCoachSummary['history_blocks'] > 0)
                            {{ $batchCoachSummary['history_blocks'] }} history/duplicate block.
                        @endif
                        <span class="font-medium text-indigo-800">{{ $batchCoachSummary['action_hint'] }}</span>
                    </p>
                </div>
            </div>
            <details class="shrink-0 text-xs text-gray-600">
                <summary class="cursor-pointer font-semibold text-gray-700">Processing status filter</summary>
                <div class="mt-2 flex max-w-md flex-wrap gap-1">
                    @foreach ($statusFilters as $key => $label)
                        <a href="{{ $buildShowUrl($batch, $key, $screeningFilter, $highlightItemId > 0 ? $highlightItemId : null) }}"
                           data-testid="bulk-status-filter-{{ $key }}"
                           class="rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $statusFilter === $key ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-indigo-300 hover:text-indigo-700' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </details>
        </div>

        <div class="mt-3 flex flex-col gap-2" data-testid="bulk-eligibility-summary-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Eligibility gate · Work queue</p>
            <div class="flex flex-wrap items-center gap-2" data-testid="bulk-screening-filter-pills">
                @foreach ($workQueueFilterOrder as $key)
                    @php
                        $englishLabel = $primaryScreeningFilters[$key] ?? $key;
                        $filterCount = (int) ($screeningCounts[$key] ?? 0);
                        $marathiHint = $workQueueFilterLabels[$key] ?? $englishLabel;
                    @endphp
                    <a href="{{ $buildShowUrl($batch, $statusFilter, $key, $highlightItemId > 0 ? $highlightItemId : null) }}"
                       data-testid="bulk-screening-filter-{{ $key }}"
                       title="{{ $marathiHint }}"
                       class="rounded-full border px-3 py-1 text-xs font-semibold {{ $screeningFilter === $key ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-emerald-300 hover:text-emerald-700' }}">
                        {{ $englishLabel }} ({{ $filterCount }})
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
            @if ($eligiblePipelineCount > 0)
                <p class="text-xs text-emerald-800">
                    <a href="{{ $buildShowUrl($batch, $statusFilter, 'eligible', $highlightItemId > 0 ? $highlightItemId : null) }}"
                       data-testid="bulk-eligible-pipeline-view"
                       class="font-semibold underline hover:no-underline">View eligible ({{ $eligiblePipelineCount }})</a>
                </p>
            @endif
        </div>
        <p class="mt-2 text-xs text-gray-500">Current stage: candidate extraction and review. Owner assignment and profile creation are later steps.</p>
        <p class="mt-1 text-xs text-gray-500">Candidate fields appear after free parse. Manual transcript only if OCR/free parse fails. सर्व माहिती table मध्ये दिसते — click फक्त कृतीसाठी.</p>

        @if ($batch->items->isEmpty())
            <p class="mt-3 text-sm text-gray-600">No items found for this filter.</p>
        @else
            <div class="mt-4 w-full overflow-x-auto" data-testid="bulk-items-dense-table">
                <table class="w-full min-w-[1100px] table-fixed divide-y divide-gray-200 text-xs">
                    <thead class="sticky top-0 z-10 bg-gray-50 shadow-sm">
                        <tr>
                            <th class="w-8 px-1.5 py-2 text-left font-semibold uppercase text-gray-500">#</th>
                            <th class="w-[11%] px-2 py-2 text-left font-semibold uppercase text-gray-500">उमेदवार</th>
                            <th class="w-[14%] px-2 py-2 text-left font-semibold uppercase text-gray-500">माहिती</th>
                            <th class="w-[9%] px-2 py-2 text-left font-semibold uppercase text-gray-500">Parse</th>
                            <th class="w-[12%] px-2 py-2 text-left font-semibold uppercase text-gray-500">स्थिती</th>
                            <th class="w-[14%] px-2 py-2 text-left font-semibold uppercase text-gray-500">समस्या</th>
                            <th class="w-[12%] px-2 py-2 text-left font-semibold uppercase text-gray-500">प्रवास</th>
                            <th class="w-[12%] px-2 py-2 text-left font-semibold uppercase text-gray-500">पुढे काय</th>
                            <th class="w-[10%] px-2 py-2 text-right font-semibold uppercase text-gray-500">कृती</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach ($batch->items as $item)
                            @include('admin.bulk-intakes.partials.dense-item-row')
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('[data-bulk-wa-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialogId = button.getAttribute('data-bulk-wa-open');
            var dialog = dialogId ? document.getElementById(dialogId) : null;
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        });
    });

    document.querySelectorAll('[data-bulk-wa-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = button.closest('dialog');
            if (dialog && typeof dialog.close === 'function') {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('dialog[id^="bulk-wa-panel-"]').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog && typeof dialog.close === 'function') {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('[data-bulk-dup-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialogId = button.getAttribute('data-bulk-dup-open');
            var dialog = dialogId ? document.getElementById(dialogId) : null;
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        });
    });

    document.querySelectorAll('[data-bulk-dup-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = button.closest('dialog');
            if (dialog && typeof dialog.close === 'function') {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('dialog[id^="bulk-dup-panel-"]').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog && typeof dialog.close === 'function') {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('[data-bulk-actions-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialogId = button.getAttribute('data-bulk-actions-open');
            var dialog = dialogId ? document.getElementById(dialogId) : null;
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        });
    });

    document.querySelectorAll('[data-bulk-actions-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = button.closest('dialog');
            if (dialog && typeof dialog.close === 'function') {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('dialog[id^="bulk-actions-panel-"]').forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog && typeof dialog.close === 'function') {
                dialog.close();
            }
        });
    });

    @if ($highlightItemId > 0)
        (function () {
            var row = document.getElementById('bulk-item-{{ $highlightItemId }}');
            if (row && typeof row.scrollIntoView === 'function') {
                row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        })();
    @endif
})();
</script>
@endsection
