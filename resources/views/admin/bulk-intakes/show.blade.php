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
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-lg font-semibold text-gray-900">Items</h2>
                <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">
                    <span data-testid="bulk-eligible-pipeline-count">{{ $eligiblePipelineCount }}</span>
                    {{ $eligiblePipelineCount === 1 ? 'eligible for WhatsApp' : 'eligible for WhatsApp' }}
                    <a href="{{ $buildShowUrl($batch, $statusFilter, 'eligible', $highlightItemId > 0 ? $highlightItemId : null) }}"
                       data-testid="bulk-eligible-pipeline-view"
                       class="text-emerald-900 underline hover:no-underline">View eligible</a>
                </span>
            </div>
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

        <div class="mt-3 flex flex-col gap-3" data-testid="bulk-eligibility-summary-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Eligibility gate</p>
            <div class="flex flex-wrap items-center gap-2" data-testid="bulk-screening-filter-pills">
                @foreach ($primaryScreeningFilters as $key => $label)
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
        <p class="mt-2 text-xs text-gray-500">Candidate fields appear after free parse. Manual transcript only if OCR/free parse fails.</p>

        @if ($batch->items->isEmpty())
            <p class="mt-3 text-sm text-gray-600">No items found for this filter.</p>
        @else
            <div class="mt-4 w-full" data-testid="bulk-items-compact-table">
                <table class="w-full table-fixed divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="w-10 px-2 py-2 text-left text-xs font-semibold uppercase text-gray-500">#</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500">Candidate</th>
                            <th class="hidden w-28 px-2 py-2 text-left text-xs font-semibold uppercase text-gray-500 sm:table-cell">Parse</th>
                            <th class="w-32 px-2 py-2 text-left text-xs font-semibold uppercase text-gray-500">Pipeline</th>
                            <th class="w-28 px-2 py-2 text-right text-xs font-semibold uppercase text-gray-500">Actions</th>
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
                                    'religion' => null,
                                    'caste' => null,
                                    'sub_caste' => null,
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
                                $manualScreeningReview = is_array($screeningReviewByItemId[$item->id] ?? null) ? $screeningReviewByItemId[$item->id] : null;
                                $manualScreeningActive = $manualScreeningReview !== null;
                                $duplicateHints = is_array($duplicateHintsByItemId[$item->id] ?? null) ? $duplicateHintsByItemId[$item->id] : [];
                                $duplicateVerification = is_array($duplicateVerificationByItemId[$item->id] ?? null) ? $duplicateVerificationByItemId[$item->id] : [];
                                $duplicateGate = is_array($duplicateGateByItemId[$item->id] ?? null) ? $duplicateGateByItemId[$item->id] : [];
                                $duplicateGateBlocks = is_array($duplicateGate['blocks'] ?? null) ? $duplicateGate['blocks'] : [];
                                $historyBlocks = is_array($duplicateGate['history_blocks'] ?? null) ? $duplicateGate['history_blocks'] : [];
                                $duplicateAutoBlocked = (bool) ($duplicateGate['auto_blocked'] ?? false);
                                $duplicateOverrideActive = (bool) ($duplicateGate['override_active'] ?? false);
                                $pipeline = is_array($pipelineByItemId[$item->id] ?? null) ? $pipelineByItemId[$item->id] : [];
                                $pipelineBucket = (string) ($pipeline['bucket'] ?? 'needs_check');
                                $pipelineSource = (string) ($pipeline['source'] ?? 'auto');
                                $pipelineBadgeClass = match ($pipelineBucket) {
                                    'eligible' => 'border-emerald-300 bg-emerald-100 text-emerald-800',
                                    'blocked' => 'border-red-300 bg-red-100 text-red-800',
                                    default => 'border-amber-300 bg-amber-100 text-amber-900',
                                };
                                $pipelineReasons = is_array($pipeline['reasons'] ?? null) ? array_slice($pipeline['reasons'], 0, 2) : [];
                                $visiblePipelineReasons = array_values(array_filter(
                                    $pipelineReasons,
                                    static function (array $reason) use ($pipelineBucket): bool {
                                        $code = (string) ($reason['code'] ?? '');

                                        return ! ($pipelineBucket === 'eligible' && $code === 'pipeline_ready');
                                    },
                                ));
                                $whatsappConsent = is_array($whatsappConsentByItemId[$item->id] ?? null) ? $whatsappConsentByItemId[$item->id] : [];
                                $contactPlan = is_array($contactPlanByItemId[$item->id] ?? null) ? $contactPlanByItemId[$item->id] : [];
                                $whatsappConsentStatus = (string) ($whatsappConsent['status'] ?? '');
                                $whatsappConsentLabel = (string) ($whatsappConsent['status_label'] ?? '');
                                $canSendWhatsAppPermission = (bool) data_get($whatsappConsent, 'can_send.allowed', false);
                                $canSimulateWhatsAppReply = (bool) ($whatsappConsent['can_simulate_reply'] ?? false);
                                $manualWhatsAppPreview = is_array($whatsappConsent['manual_preview'] ?? null) ? $whatsappConsent['manual_preview'] : null;
                                $manualWhatsAppShareUrl = (string) ($whatsappConsent['manual_whatsapp_share_url'] ?? '');
                                $registration = is_array($registrationByItemId[$item->id] ?? null) ? $registrationByItemId[$item->id] : [];
                                $registrationStatus = (string) ($registration['status'] ?? '');
                                $registrationStatusLabel = (string) ($registration['status_label'] ?? '');
                                $registrationSummary = is_array($registration['summary'] ?? null) ? $registration['summary'] : [];
                                $registrationPath = (string) ($registrationSummary['path'] ?? '');
                                $registrationPathLabel = (string) ($registrationSummary['path_label'] ?? '');
                                $canSendRegistrationSummary = (bool) data_get($registration, 'can_send_summary.allowed', false);
                                $canSimulateRegistrationComplete = (bool) ($registration['can_simulate_complete'] ?? false);
                                $canSimulateRegistrationReply = (bool) ($registration['can_simulate_reply'] ?? false);
                                $canSimulateRegistrationPhoto = (bool) ($registration['can_simulate_photo'] ?? false);
                                $registrationSimulateButtons = is_array($registration['simulate_buttons'] ?? null) ? $registration['simulate_buttons'] : [];
                                $registrationNeedsFieldValueText = (bool) ($registration['needs_field_value_text'] ?? false);
                                $registrationFieldValueHint = (string) ($registration['field_value_hint'] ?? '');
                                $registrationFlowStepLabel = (string) ($registration['flow_step_label'] ?? '');
                                $registrationManualPreview = is_array($registration['manual_preview'] ?? null) ? $registration['manual_preview'] : null;
                                $registrationWhatsAppShareUrl = (string) ($registration['manual_whatsapp_share_url'] ?? '');
                                $consentReceived = $whatsappConsentStatus === \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED;
                                $registrationBadgeClass = match ($registrationStatus) {
                                    \App\Services\Intake\BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE => 'border-emerald-300 bg-emerald-100 text-emerald-900',
                                    \App\Services\Intake\BulkIntakeRegistrationService::STATUS_SUMMARY_SENT => 'border-violet-300 bg-violet-100 text-violet-900',
                                    default => 'border-gray-200 bg-gray-50 text-gray-700',
                                };
                                $registrationPathBadgeClass = match ($registrationPath) {
                                    \App\Services\Intake\BulkIntakeRegistrationService::PATH_FAST => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                                    \App\Services\Intake\BulkIntakeRegistrationService::PATH_TARGETED => 'border-amber-200 bg-amber-50 text-amber-900',
                                    \App\Services\Intake\BulkIntakeRegistrationService::PATH_FULL => 'border-red-200 bg-red-50 text-red-800',
                                    default => 'border-gray-200 bg-gray-50 text-gray-700',
                                };
                                $whatsappConsentBadgeClass = match ($whatsappConsentStatus) {
                                    \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED => 'border-emerald-300 bg-emerald-100 text-emerald-900',
                                    \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_PERMISSION_SENT => 'border-sky-300 bg-sky-100 text-sky-900',
                                    \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_CONSENT_DENIED,
                                    \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_ALREADY_MARRIED,
                                    \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_WRONG_NUMBER,
                                    \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_NO_RESPONSE => 'border-red-300 bg-red-100 text-red-900',
                                    \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_CONTACTS_EXHAUSTED => 'border-red-300 bg-red-100 text-red-900',
                                    default => 'border-gray-200 bg-gray-50 text-gray-700',
                                };
                                $manualDuplicateReview = is_array(data_get($itemMeta, 'duplicate_review')) ? data_get($itemMeta, 'duplicate_review') : [];
                                $manualDuplicateActive = (string) data_get($manualDuplicateReview, 'status') === 'manual_duplicate';
                                $primaryDuplicateHint = is_array($duplicateVerification['primary'] ?? null)
                                    ? $duplicateVerification['primary']
                                    : (is_array($duplicateHints[0] ?? null) ? $duplicateHints[0] : []);
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
                                if ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_NEEDS_REVIEW && $pipelineBucket !== 'needs_check') {
                                    $exceptionBadges[] = [
                                        'label' => 'Admin flagged',
                                        'class' => 'border-amber-200 bg-amber-50 text-amber-800',
                                        'testid' => 'bulk-item-needs-review-flag',
                                    ];
                                }
                                if ($manualDuplicateActive) {
                                    $exceptionBadges[] = [
                                        'label' => 'Manual duplicate',
                                        'class' => 'border-rose-200 bg-rose-50 text-rose-700',
                                        'testid' => 'bulk-manual-duplicate-badge',
                                    ];
                                }
                                foreach (array_slice($duplicateVerification['hints'] ?? $duplicateHints, 0, 2) as $duplicateHint) {
                                    $dupBadgeLabel = (string) ($duplicateHint['reason_label_mr'] ?? $duplicateHint['label'] ?? 'Possible duplicate');
                                    $exceptionBadges[] = [
                                        'label' => $dupBadgeLabel,
                                        'class' => 'border-purple-200 bg-purple-50 text-purple-700',
                                        'testid' => 'bulk-duplicate-history-hint',
                                        'title' => (string) ($duplicateHint['matched']['journey_label'] ?? ''),
                                    ];
                                }
                                foreach ($historyBlocks as $historyBlock) {
                                    $exceptionBadges[] = [
                                        'label' => 'History: '.(string) ($historyBlock['label'] ?? 'Blocked'),
                                        'class' => 'border-red-200 bg-red-50 text-red-700',
                                        'testid' => 'bulk-identity-history-block',
                                    ];
                                }
                                foreach ($duplicateGateBlocks as $gateBlock) {
                                    if ((string) ($gateBlock['source'] ?? '') !== 'auto_duplicate') {
                                        continue;
                                    }
                                    $exceptionBadges[] = [
                                        'label' => (string) ($gateBlock['label'] ?? 'Auto blocked'),
                                        'class' => 'border-rose-200 bg-rose-50 text-rose-800',
                                        'testid' => 'bulk-auto-duplicate-block',
                                    ];
                                }
                                if ($duplicateOverrideActive) {
                                    $exceptionBadges[] = [
                                        'label' => 'Override: proceed',
                                        'class' => 'border-sky-200 bg-sky-50 text-sky-800',
                                        'testid' => 'bulk-duplicate-override-badge',
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
                                $detailsRowId = 'bulk-item-details-'.(int) $item->id;
                                $extraStatusChipCount = count($visiblePipelineReasons)
                                    + ($whatsappConsentStatus !== '' ? 1 : 0)
                                    + ($consentReceived && $registrationPath !== '' ? 1 : 0)
                                    + ($registrationStatus !== '' ? 1 : 0)
                                    + count($exceptionBadges);
                            @endphp
                            <tr id="bulk-item-{{ $item->id }}" @if ($isHighlightedItem) style="background-color: #ecfdf5;" @endif>
                                <td class="px-2 py-2 text-sm text-gray-900">{{ $item->item_sequence }}</td>
                                <td class="px-3 py-2 text-sm text-gray-700">
                                    <span class="block truncate font-medium" title="{{ $candidate['full_name'] ?? $missingDisplay }}">{{ $candidate['full_name'] ?? $missingDisplay }}</span>
                                    <span class="block truncate text-xs text-gray-500">Mobile: {{ $candidate['mobile'] ?? $missingDisplay }}</span>
                                    <span class="block truncate text-xs text-gray-400" title="{{ $itemDisplayLabel }}">{{ $itemDisplayLabel }}</span>
                                    <button
                                        type="button"
                                        data-bulk-details-toggle="{{ $detailsRowId }}"
                                        data-testid="bulk-toggle-item-details"
                                        class="mt-1 text-xs font-medium text-indigo-600 hover:text-indigo-800"
                                    >Details ▾</button>
                                </td>
                                <td class="hidden px-2 py-2 text-sm text-gray-700 sm:table-cell">
                                    @if ($intake)
                                        <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">#{{ $intake->id }}</a>
                                        @if ($parseStatus === 'parsed' && $hasParsedJson)
                                            <span class="block text-xs font-medium text-green-700">OK</span>
                                        @elseif ($hasEmptyOcrFailure)
                                            <span class="block text-xs font-medium text-red-700">OCR fail</span>
                                        @elseif ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
                                            <span class="block text-xs font-medium text-amber-700">Queued</span>
                                        @else
                                            <span class="block text-xs text-gray-500">{{ $parseStatus !== '' ? $parseStatus : $itemDisplayStatus }}</span>
                                        @endif
                                    @else
                                        <span class="text-xs text-gray-500">{{ $itemDisplayStatus }}</span>
                                    @endif
                                </td>
                                <td class="px-2 py-2 text-sm">
                                    <div class="flex flex-wrap gap-1">
                                        <span data-testid="bulk-pipeline-badge" class="rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $pipelineBadgeClass }}">
                                            {{ $pipeline['bucket_label'] ?? 'Needs check' }}
                                            @if ($pipelineSource === 'override')
                                                <span class="font-normal">· override</span>
                                            @endif
                                        </span>
                                        @if ($extraStatusChipCount > 0)
                                            <button
                                                type="button"
                                                data-bulk-details-toggle="{{ $detailsRowId }}"
                                                class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] font-medium text-gray-600 hover:bg-gray-100"
                                            >+{{ $extraStatusChipCount }}</button>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-2 py-2 text-right text-sm">
                                    <div class="flex flex-col items-end gap-1">
                                        @include('admin.bulk-intakes.partials.item-actions-panel')
                                    </div>
                                </td>
                            </tr>
                            <tr id="{{ $detailsRowId }}" class="hidden bg-gray-50" data-testid="bulk-item-details-row">
                                <td colspan="5" class="px-3 py-3 text-sm text-gray-700">
                                    <div class="grid gap-4 lg:grid-cols-2">
                                        <div class="space-y-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">File / source</p>
                                            <p><span class="font-medium">{{ $itemDisplayLabel }}</span> <span class="text-gray-500">· {{ $item->input_type }} · {{ $itemDisplayStatus }}</span></p>
                                            @if ($textPreview)
                                                <p class="text-xs text-gray-500">{{ $textPreview }}</p>
                                            @endif
                                            @if ($item->failure_code)
                                                <p class="text-xs text-red-700">{{ $item->failure_code }}: {{ $item->failure_message }}</p>
                                            @endif
                                            <p class="text-xs text-gray-500">Source contexts: {{ (int) ($sourceContextCountsByItem[$item->id] ?? 0) }}</p>
                                        </div>
                                        <div class="space-y-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Candidate</p>
                                            <p>
                                                <span class="font-medium">{{ $candidate['full_name'] ?? $missingDisplay }}</span>
                                                @if ($usesReviewedSnapshot)
                                                    <span data-testid="bulk-candidate-reviewed-badge" class="ml-1 rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-700">Reviewed</span>
                                                @endif
                                                @if (($candidate['name_needs_review'] ?? false))
                                                    <span class="ml-1 rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700">review</span>
                                                @endif
                                            </p>
                                            <p class="text-xs text-gray-500">Mobile: {{ $candidate['mobile'] ?? $missingDisplay }}</p>
                                            @if (!empty($contactPlan['active_mobile']))
                                                <p data-testid="bulk-contact-plan-active" class="text-[11px] text-sky-800">
                                                    WhatsApp queue: {{ $contactPlan['active_mobile'] }}
                                                    @if (!empty($contactPlan['active_role_label']))
                                                        ({{ $contactPlan['active_role_label'] }})
                                                    @endif
                                                    @if (($contactPlan['queue_total'] ?? 0) > 1)
                                                        — {{ $contactPlan['active_position'] ?? 0 }}/{{ $contactPlan['queue_total'] }}
                                                    @endif
                                                </p>
                                            @endif
                                            @if (($contactPlan['suchak_count'] ?? 0) > 0)
                                                <p data-testid="bulk-suchak-directory-count" class="text-[11px] text-violet-800">
                                                    Suchak reference: {{ $contactPlan['suchak_count'] }} (not messaged)
                                                </p>
                                            @endif
                                            <p class="text-xs text-gray-500">DOB: {{ $candidate['date_of_birth'] ?? $missingDisplay }} · Age: {{ $candidate['age'] ?? $missingDisplay }}</p>
                                            <p class="text-xs text-gray-500">Height: {{ $candidate['height'] ?? $missingDisplay }} · Gender: {{ $candidate['gender'] ?? $missingDisplay }}</p>
                                            <p class="text-xs text-gray-500">City: {{ $candidate['city'] ?? $missingDisplay }}</p>
                                            <p class="text-xs text-gray-500">Education: {{ $candidate['education'] ?? $missingDisplay }} · Occupation: {{ $candidate['occupation'] ?? $missingDisplay }}</p>
                                            @if (($candidate['religion'] ?? null) || ($candidate['caste'] ?? null) || ($candidate['sub_caste'] ?? null))
                                                <p class="text-xs text-gray-500">
                                                    @if ($candidate['religion'] ?? null)
                                                        Religion: {{ $candidate['religion'] }}
                                                    @endif
                                                    @if ($candidate['caste'] ?? null)
                                                        @if ($candidate['religion'] ?? null) · @endif
                                                        Caste: {{ $candidate['caste'] }}
                                                    @endif
                                                    @if ($candidate['sub_caste'] ?? null)
                                                        · Sub: {{ $candidate['sub_caste'] }}
                                                    @endif
                                                </p>
                                            @endif
                                        </div>
                                        <div class="space-y-2 lg:col-span-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Parse</p>
                                            @if ($intake)
                                                <p>
                                                    <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Intake #{{ $intake->id }}</a>
                                                    · Parsed JSON: {{ $hasParsedJson ? 'Yes' : 'No' }}
                                                </p>
                                                @if ($parseStatus === 'parsed' && $hasParsedJson)
                                                    <p class="text-xs font-medium text-green-700">Parse: OK</p>
                                                @elseif ($hasEmptyOcrFailure)
                                                    <p class="text-xs font-medium text-red-700">OCR failed: no text extracted</p>
                                                @elseif ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
                                                    <p class="text-xs font-medium text-amber-700">Free parse queued</p>
                                                @elseif ($parseStatus === 'pending')
                                                    <p class="text-xs text-gray-500">Waiting for free parse</p>
                                                @elseif ($parseStatus !== '')
                                                    <p class="text-xs text-gray-500">Parse: {{ $parseStatus }}</p>
                                                @endif
                                                @if ($intake->last_error)
                                                    <p class="text-xs text-red-700">{{ $intake->last_error }}</p>
                                                @endif
                                            @else
                                                @if ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PENDING)
                                                    <p class="text-xs text-gray-500">Waiting for background processing</p>
                                                @elseif ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PROCESSING)
                                                    <p class="text-xs text-amber-700">OCR/parse preparation running</p>
                                                @else
                                                    <p class="text-xs text-red-700">Missing linked intake</p>
                                                @endif
                                                <p class="text-xs text-gray-500">Parsed JSON: No</p>
                                            @endif
                                        </div>
                                        <div class="space-y-2 lg:col-span-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Status & flags</p>
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($visiblePipelineReasons as $pipelineReason)
                                                    <span data-testid="bulk-pipeline-reason" class="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[10px] font-medium text-gray-600">{{ $pipelineReason['label'] ?? str_replace('_', ' ', (string) ($pipelineReason['code'] ?? '')) }}</span>
                                                @endforeach
                                                @if ($whatsappConsentStatus !== '')
                                                    <span data-testid="bulk-whatsapp-consent-badge" class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $whatsappConsentBadgeClass }}">{{ $whatsappConsentLabel }}</span>
                                                @endif
                                                @if ($consentReceived && $registrationPath !== '')
                                                    <span data-testid="bulk-registration-path-badge" class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $registrationPathBadgeClass }}">{{ $registrationPathLabel }}</span>
                                                @endif
                                                @if ($registrationStatus !== '')
                                                    <span data-testid="bulk-registration-status-badge" class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $registrationBadgeClass }}">{{ $registrationStatusLabel }}</span>
                                                @endif
                                                @if ($exceptionBadges !== [])
                                                    @foreach ($exceptionBadges as $badge)
                                                        <span @if (! empty($badge['testid'])) data-testid="{{ $badge['testid'] }}" @endif @if (! empty($badge['title'])) title="{{ $badge['title'] }}" @endif class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                                    @endforeach
                                                @endif
                                            </div>
                                        </div>
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

    document.querySelectorAll('[data-bulk-details-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var rowId = button.getAttribute('data-bulk-details-toggle');
            var row = rowId ? document.getElementById(rowId) : null;
            if (! row) {
                return;
            }
            var isHidden = row.classList.contains('hidden');
            row.classList.toggle('hidden', ! isHidden);
            if (button.textContent && button.textContent.indexOf('Details') === 0) {
                button.textContent = isHidden ? 'Details ▴' : 'Details ▾';
            }
        });
    });

    @if ($highlightItemId > 0)
        (function () {
            var detailsRow = document.getElementById('bulk-item-details-{{ $highlightItemId }}');
            if (detailsRow) {
                detailsRow.classList.remove('hidden');
            }
        })();
    @endif
})();
</script>
@endsection
