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
    $pipelineByItemId = $pipelineByItemId ?? [];
    $screeningByItemId = $screeningByItemId ?? [];
    $screeningReviewByItemId = $screeningReviewByItemId ?? [];
    $readyForConsentByItemId = is_array($readyForConsentByItemId ?? null) ? $readyForConsentByItemId : [];
    $readyCount = (int) ($readyCount ?? 0);
    $whatsappConsentByItemId = is_array($whatsappConsentByItemId ?? null) ? $whatsappConsentByItemId : [];
    $whatsappEligibleToSendCount = (int) ($whatsappEligibleToSendCount ?? 0);
    $whatsappManualTestEnabled = (bool) ($whatsappManualTestEnabled ?? false);
    $whatsappSendModeLabel = (string) ($whatsappSendModeLabel ?? '');
    $registrationByItemId = is_array($registrationByItemId ?? null) ? $registrationByItemId : [];
    $screeningFilter = (string) ($screeningFilter ?? 'all');
    $primaryScreeningFilters = is_array($primaryScreeningFilters ?? null) ? $primaryScreeningFilters : [];
    $legacyScreeningFilters = is_array($legacyScreeningFilters ?? null) ? $legacyScreeningFilters : [];
    $screeningFilters = is_array($screeningFilters ?? null) ? $screeningFilters : array_merge($primaryScreeningFilters, $legacyScreeningFilters);
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
                    <span data-testid="bulk-ready-for-consent-count">{{ $readyCount }}</span>
                    {{ $readyCount === 1 ? 'candidate ready' : 'candidates ready' }}
                    <a href="{{ $buildShowUrl($batch, $statusFilter, 'ready', $highlightItemId > 0 ? $highlightItemId : null) }}"
                       data-testid="bulk-ready-for-consent-view-queue"
                       class="text-emerald-900 underline hover:no-underline">View queue</a>
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

        <div class="mt-3 flex flex-col gap-3" data-testid="bulk-ready-for-consent-summary-card">
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
            @if ($legacyScreeningFilters !== [])
                <div class="flex flex-wrap items-center gap-2" data-testid="bulk-screening-legacy-filter-pills">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">More filters</span>
                    @foreach ($legacyScreeningFilters as $key => $label)
                        <a href="{{ $buildShowUrl($batch, $statusFilter, $key, $highlightItemId > 0 ? $highlightItemId : null) }}"
                           data-testid="bulk-screening-filter-{{ $key }}"
                           class="rounded-full border border-dashed px-3 py-1 text-xs font-semibold {{ $screeningFilter === $key ? 'border-indigo-600 bg-indigo-50 text-indigo-800' : 'border-gray-300 bg-white text-gray-600 hover:border-indigo-300 hover:text-indigo-700' }}">
                            {{ $label }} ({{ (int) ($screeningCounts[$key] ?? 0) }})
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
        <p class="mt-2 text-xs text-gray-500">Candidate fields appear after free parse. Manual transcript only if OCR/free parse fails.</p>

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
                                $readyForConsent = is_array($readyForConsentByItemId[$item->id] ?? null)
                                    ? $readyForConsentByItemId[$item->id]
                                    : ['ready' => false, 'reasons' => []];
                                $isReadyForConsent = (bool) ($readyForConsent['ready'] ?? false);
                                $manualScreeningLabel = match ($manualScreeningStatus) {
                                    'eligible_for_consent' => 'Override: Eligible',
                                    'needs_review' => 'Override: Needs check',
                                    'stopped' => 'Override: Blocked',
                                    default => 'Override',
                                };
                                $manualScreeningBadgeClass = match ($manualScreeningStatus) {
                                    'eligible_for_consent' => 'border-emerald-300 bg-emerald-100 text-emerald-800',
                                    'stopped' => 'border-red-300 bg-red-100 text-red-800',
                                    default => 'border-amber-300 bg-amber-100 text-amber-900',
                                };
                                $duplicateHints = is_array($duplicateHintsByItemId[$item->id] ?? null) ? $duplicateHintsByItemId[$item->id] : [];
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
                                $whatsappConsent = is_array($whatsappConsentByItemId[$item->id] ?? null) ? $whatsappConsentByItemId[$item->id] : [];
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
                                    default => 'border-gray-200 bg-gray-50 text-gray-700',
                                };
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
                                        <span data-testid="bulk-pipeline-badge" class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $pipelineBadgeClass }}">
                                            {{ $pipeline['bucket_label'] ?? 'Needs check' }}
                                            @if ($pipelineSource === 'override')
                                                <span class="font-normal">· override</span>
                                            @endif
                                        </span>
                                        @foreach ($pipelineReasons as $pipelineReason)
                                            <span data-testid="bulk-pipeline-reason" class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] font-medium text-gray-600">{{ $pipelineReason['label'] ?? str_replace('_', ' ', (string) ($pipelineReason['code'] ?? '')) }}</span>
                                        @endforeach
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
                                        @if ($isReadyForConsent)
                                            <span data-testid="bulk-ready-for-consent-badge" class="rounded-full border border-emerald-300 bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Ready for Consent</span>
                                        @elseif ($manualScreeningActive && $manualScreeningStatus === 'eligible_for_consent')
                                            <span data-testid="bulk-not-ready-for-consent-hint" class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] font-medium text-gray-500">Not ready</span>
                                        @endif
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

                                        @if ($duplicateAutoBlocked && ! $duplicateOverrideActive && ! $manualDuplicateActive)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.override-duplicate-block', [$batch, $item]) }}">
                                                @csrf
                                                <input type="hidden" name="reason" value="Admin override — proceed despite auto block">
                                                <button type="submit" data-testid="bulk-override-duplicate-block" class="text-left text-sm font-medium text-sky-700 hover:text-sky-900">Override — proceed</button>
                                            </form>
                                        @elseif ($duplicateOverrideActive)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-duplicate-block-override', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" data-testid="bulk-clear-duplicate-override" class="text-left text-sm font-medium text-sky-700 hover:text-sky-900">Clear override</button>
                                            </form>
                                        @endif

                                        @if ($manualScreeningActive)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.clear-screening-review', [$batch, $item]) }}">
                                                @csrf
                                                <button type="submit" class="text-left text-sm font-medium text-indigo-700 hover:text-indigo-900">Clear screening</button>
                                            </form>
                                        @endif

                                        @if ($canSendWhatsAppPermission)
                                            <form method="POST" action="{{ route('admin.bulk-intakes.items.send-whatsapp-permission', [$batch, $item]) }}">
                                                @csrf
                                                <button
                                                    type="submit"
                                                    data-testid="bulk-send-whatsapp-permission"
                                                    class="text-left text-sm font-medium text-emerald-700 hover:text-emerald-900"
                                                >Send permission</button>
                                            </form>
                                        @endif

                                        @if ($whatsappManualTestEnabled && ($canSendWhatsAppPermission || $whatsappConsentStatus === \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_PERMISSION_SENT) && $manualWhatsAppPreview)
                                            <div class="mt-1 rounded-md border border-sky-100 bg-sky-50 p-2">
                                                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-sky-700">WhatsApp test</span>
                                                <p data-testid="bulk-whatsapp-message-preview" class="whitespace-pre-wrap text-xs text-sky-900">{{ $manualWhatsAppPreview['share_text'] ?? '' }}</p>
                                                @if ($manualWhatsAppShareUrl !== '')
                                                    <a
                                                        href="{{ $manualWhatsAppShareUrl }}"
                                                        target="_blank"
                                                        rel="noopener"
                                                        data-testid="bulk-open-whatsapp-manual-test"
                                                        class="mt-2 inline-flex text-sm font-medium text-emerald-700 hover:text-emerald-900"
                                                    >Open on my WhatsApp</a>
                                                @endif
                                            </div>
                                        @endif

                                        @if ($canSimulateWhatsAppReply && is_array($manualWhatsAppPreview['buttons'] ?? null))
                                            <div class="mt-1 border-t border-gray-100 pt-2">
                                                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-400">Simulate user reply</span>
                                                @foreach ($manualWhatsAppPreview['buttons'] as $simulateButton)
                                                    @php
                                                        $simulateReplyChoice = (string) ($simulateButton['id'] ?? '');
                                                        $simulateReplyLabel = trim(((string) ($simulateButton['emoji'] ?? '')).' '.((string) ($simulateButton['title'] ?? '')));
                                                        $simulateReplyTestId = match ($simulateReplyChoice) {
                                                            \App\Services\Intake\BulkIntakeWhatsAppConsentService::REPLY_YES => 'bulk-simulate-whatsapp-yes',
                                                            \App\Services\Intake\BulkIntakeWhatsAppConsentService::REPLY_NO => 'bulk-simulate-whatsapp-no',
                                                            \App\Services\Intake\BulkIntakeWhatsAppConsentService::REPLY_ALREADY_MARRIED => 'bulk-simulate-whatsapp-married',
                                                            \App\Services\Intake\BulkIntakeWhatsAppConsentService::REPLY_WRONG_NUMBER => 'bulk-simulate-whatsapp-wrong',
                                                            default => 'bulk-simulate-whatsapp-reply',
                                                        };
                                                    @endphp
                                                    <form method="POST" action="{{ route('admin.bulk-intakes.items.simulate-whatsapp-consent-reply', [$batch, $item]) }}" class="mt-1">
                                                        @csrf
                                                        <input type="hidden" name="reply_choice" value="{{ $simulateReplyChoice }}">
                                                        <button
                                                            type="submit"
                                                            data-testid="{{ $simulateReplyTestId }}"
                                                            class="text-left text-sm font-medium text-indigo-700 hover:text-indigo-900"
                                                        >{{ $simulateReplyLabel }}</button>
                                                    </form>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if ($consentReceived)
                                            <div class="mt-1 rounded-md border border-violet-100 bg-violet-50 p-2">
                                                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-violet-700">Registration (Phase E)</span>
                                                @if (is_array($registrationSummary['fields'] ?? null))
                                                    <div data-testid="bulk-registration-summary-preview" class="space-y-0.5 text-xs text-violet-900">
                                                        @foreach ($registrationSummary['fields'] as $summaryField)
                                                            <div>{{ $summaryField['icon'] ?? '⚠' }} {{ $summaryField['label'] ?? '' }}: {{ $summaryField['value'] ?? '—' }}</div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                                @if ($canSendRegistrationSummary)
                                                    <form method="POST" action="{{ route('admin.bulk-intakes.items.send-registration-summary', [$batch, $item]) }}" class="mt-2">
                                                        @csrf
                                                        <button type="submit" data-testid="bulk-send-registration-summary" class="text-left text-sm font-medium text-violet-700 hover:text-violet-900">Send registration summary</button>
                                                    </form>
                                                @endif
                                                @if ($whatsappManualTestEnabled && $registrationWhatsAppShareUrl !== '')
                                                    <a href="{{ $registrationWhatsAppShareUrl }}" target="_blank" rel="noopener" data-testid="bulk-open-registration-whatsapp-test" class="mt-2 inline-flex text-sm font-medium text-emerald-700 hover:text-emerald-900">Open summary on WhatsApp</a>
                                                @endif
                                                @if ($intake)
                                                    <a href="{{ route('admin.bulk-intakes.items.correct-candidate', [$batch, $item]) }}" data-testid="bulk-registration-web-edit" class="mt-2 block text-sm font-medium text-indigo-700 hover:text-indigo-900">वेबवर सर्व edit करा</a>
                                                @endif
                                                @if ($canSimulateRegistrationComplete)
                                                    <form method="POST" action="{{ route('admin.bulk-intakes.items.simulate-registration-complete', [$batch, $item]) }}" class="mt-2">
                                                        @csrf
                                                        <button type="submit" data-testid="bulk-simulate-registration-complete" class="text-left text-sm font-medium text-emerald-700 hover:text-emerald-900">नोंदणी पूर्ण करा (simulate)</button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif

                                        @if ($intake)
                                            <div class="mt-1 border-t border-gray-100 pt-2">
                                                <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-400">Record history</span>
                                                @foreach ([
                                                    'already_married' => ['label' => 'Already married', 'testid' => 'bulk-mark-already-married'],
                                                    'not_interested' => ['label' => 'Not interested', 'testid' => 'bulk-mark-not-interested'],
                                                    'wrong_number' => ['label' => 'Wrong number', 'testid' => 'bulk-mark-wrong-number'],
                                                ] as $historyReasonKey => $historyAction)
                                                    <form method="POST" action="{{ route('admin.bulk-intakes.items.save-screening-review', [$batch, $item]) }}" class="mt-1">
                                                        @csrf
                                                        <input type="hidden" name="status" value="stopped">
                                                        <input type="hidden" name="reason_key" value="{{ $historyReasonKey }}">
                                                        <button
                                                            type="submit"
                                                            data-testid="{{ $historyAction['testid'] }}"
                                                            class="text-left text-sm font-medium text-red-700 hover:text-red-900"
                                                        >{{ $historyAction['label'] }}</button>
                                                    </form>
                                                @endforeach
                                            </div>
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
