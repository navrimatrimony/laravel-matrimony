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
    $pipelineReasonCodes = is_array($pipeline['reason_codes'] ?? null) ? $pipeline['reason_codes'] : [];
    $pipelineBadgeClass = match ($pipelineBucket) {
        'eligible' => 'border-emerald-300 bg-emerald-100 text-emerald-800',
        'blocked' => 'border-red-300 bg-red-100 text-red-800',
        default => 'border-amber-300 bg-amber-100 text-amber-900',
    };
    $rowBorderClass = match ($pipelineBucket) {
        'eligible' => 'border-l-4 border-l-emerald-500',
        'blocked' => 'border-l-4 border-l-red-500',
        default => 'border-l-4 border-l-amber-400',
    };
    $pipelineReasons = is_array($pipeline['reasons'] ?? null) ? $pipeline['reasons'] : [];
    $visiblePipelineReasons = array_values(array_filter(
        $pipelineReasons,
        static function (array $reason) use ($pipelineBucket): bool {
            $code = (string) ($reason['code'] ?? '');

            return ! ($pipelineBucket === 'eligible' && $code === 'pipeline_ready');
        },
    ));
    $mainPipelineReason = (string) ($visiblePipelineReasons[0]['label'] ?? '');
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
    $manualDuplicateReview = is_array(data_get($itemMeta, 'duplicate_review')) ? data_get($itemMeta, 'duplicate_review') : [];
    $manualDuplicateActive = (string) data_get($manualDuplicateReview, 'status') === 'manual_duplicate';
    $primaryDuplicateHint = is_array($duplicateVerification['primary'] ?? null)
        ? $duplicateVerification['primary']
        : (is_array($duplicateHints[0] ?? null) ? $duplicateHints[0] : []);
    $primaryDupMatched = is_array($primaryDuplicateHint['matched'] ?? null) ? $primaryDuplicateHint['matched'] : [];
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
    $sourceLabel = $item->input_type === \App\Models\BulkIntakeBatchItem::INPUT_TEXT ? 'text' : 'file';
    $itemDisplayLabel = $item->input_type === \App\Models\BulkIntakeBatchItem::INPUT_TEXT
        ? 'Text item #'.$item->item_sequence
        : ((string) ($item->original_filename ?: $missingDisplay));
    $itemTypeStatusLine = $sourceLabel.' · '.$itemDisplayStatus;
    $textPreview = null;
    if ($item->input_type === \App\Models\BulkIntakeBatchItem::INPUT_TEXT && filled($item->summary_text)) {
        $textPreview = \Illuminate\Support\Str::limit((string) $item->summary_text, 60);
    }
    $hasEmptyOcrFailure = (string) $item->failure_code === 'empty_ocr_text'
        || (
            (string) $item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_NEEDS_REVIEW
            && (string) data_get($itemMeta, 'ocr_failure_code') === 'empty_ocr_text'
        );
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
    $autoSuggestion = is_array($autoSuggestionByItemId[$item->id] ?? null) ? $autoSuggestionByItemId[$item->id] : [];
    $suggestedNextAction = (string) ($autoSuggestion['suggested_next_action'] ?? '');
    $nextActionShort = match (true) {
        $canSendWhatsAppPermission => 'WhatsApp परवानगी पाठवा',
        in_array('missing_mobile', $pipelineReasonCodes, true) => 'मोबाईल भरा',
        in_array('invalid_mobile', $pipelineReasonCodes, true) => 'मोबाईल दुरुस्त करा',
        ($duplicateHints !== [] || $manualDuplicateActive) => 'Duplicate तपासा',
        $pipelineBucket === 'blocked' => 'थांबवले — कारण पहा',
        $hasEmptyOcrFailure || (string) ($intake?->parse_status ?? '') === 'error' => 'Parse/OCR दुरुस्त करा',
        $intake !== null => 'माहिती तपासा / दुरुस्त करा',
        default => \Illuminate\Support\Str::limit($suggestedNextAction !== '' ? $suggestedNextAction : 'प्रतीक्षा', 48),
    };
    $candidateSummaryParts = array_filter([
        filled($candidate['age'] ?? null) ? (string) $candidate['age'].'वर्ष' : null,
        filled($candidate['city'] ?? null) ? (string) $candidate['city'] : null,
        filled($candidate['education'] ?? null) ? (string) $candidate['education'] : null,
    ]);
    $candidateSummary = $candidateSummaryParts !== [] ? implode(' · ', $candidateSummaryParts) : null;
    $mobileMissing = in_array('missing_mobile', $pipelineReasonCodes, true) || blank($candidate['mobile'] ?? null);
    $mobileInvalid = in_array('invalid_mobile', $pipelineReasonCodes, true);
    $problemLines = [];
    if ($hasEmptyOcrFailure) {
        $problemLines[] = [
            'text' => 'OCR failed / no text extracted',
            'class' => 'text-red-800',
            'testid' => '',
            'title' => '',
        ];
    }
    if ($primaryDuplicateHint !== []) {
        $dupLabel = (string) ($primaryDuplicateHint['label'] ?? $primaryDuplicateHint['reason_label_mr'] ?? 'Possible duplicate');
        $problemLines[] = [
            'text' => 'Dup: '.trim((string) ($primaryDupMatched['full_name'] ?? 'जुना record')).' — '.trim((string) ($primaryDupMatched['journey_label'] ?? $dupLabel)),
            'class' => 'text-purple-800',
            'testid' => 'bulk-duplicate-history-hint',
            'title' => (string) ($primaryDupMatched['journey_label'] ?? ''),
        ];
        if (! str_contains(implode(' ', array_column($problemLines, 'text')), $dupLabel)) {
            $problemLines[] = [
                'text' => $dupLabel,
                'class' => 'text-purple-800',
                'testid' => '',
                'title' => '',
            ];
        }
    }
    foreach ($historyBlocks as $historyBlock) {
        $problemLines[] = [
            'text' => 'History: '.(string) ($historyBlock['label'] ?? 'Blocked'),
            'class' => 'text-red-800',
            'testid' => 'bulk-identity-history-block',
            'title' => '',
        ];
    }
    if ($manualDuplicateActive) {
        $problemLines[] = [
            'text' => 'Manual duplicate',
            'class' => 'text-rose-800',
            'testid' => 'bulk-manual-duplicate-badge',
            'title' => '',
        ];
    }
    foreach ($duplicateGateBlocks as $gateBlock) {
        if ((string) ($gateBlock['source'] ?? '') !== 'auto_duplicate') {
            continue;
        }
        $problemLines[] = [
            'text' => (string) ($gateBlock['label'] ?? 'Auto blocked'),
            'class' => 'text-rose-800',
            'testid' => 'bulk-auto-duplicate-block',
            'title' => '',
        ];
    }
    if ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_NEEDS_REVIEW && $pipelineBucket !== 'needs_check') {
        $problemLines[] = [
            'text' => 'Admin flagged',
            'class' => 'text-amber-800',
            'testid' => 'bulk-item-needs-review-flag',
            'title' => '',
        ];
    }
    if ($duplicateOverrideActive) {
        $problemLines[] = [
            'text' => 'Override: proceed',
            'class' => 'text-sky-800',
            'testid' => 'bulk-duplicate-override-badge',
            'title' => '',
        ];
    }
    if ($mainPipelineReason !== '' && $pipelineBucket !== 'eligible') {
        $problemLines[] = [
            'text' => $mainPipelineReason,
            'class' => 'text-gray-800',
            'testid' => 'bulk-pipeline-reason',
            'title' => '',
        ];
    }
    foreach (array_slice($visiblePipelineReasons, 1, 2) as $extraPipelineReason) {
        $extraLabel = (string) ($extraPipelineReason['label'] ?? '');
        if ($extraLabel === '') {
            continue;
        }
        $problemLines[] = [
            'text' => $extraLabel,
            'class' => 'text-gray-700',
            'testid' => 'bulk-pipeline-reason',
            'title' => '',
        ];
    }
    $problemLines = array_slice($problemLines, 0, 3);
    $isUnclaimedIntake = $intake
        && $intake->uploaded_by === null
        && (string) $item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_INTAKE_CREATED;
    $parseDone = $hasParsedJson && $parseStatus === 'parsed';
    $gateDone = $pipelineBucket === 'eligible';
    $gateFail = $pipelineBucket === 'blocked';
    $waSent = in_array($whatsappConsentStatus, [
        \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_PERMISSION_SENT,
        \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED,
    ], true);
    $waDone = $whatsappConsentStatus === \App\Services\Intake\BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED;
    $regDone = $registrationStatus === \App\Services\Intake\BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE;
@endphp
<tr id="bulk-item-{{ $item->id }}" class="{{ $rowBorderClass }} @if ($isHighlightedItem) bg-emerald-50 @endif" @if ($isHighlightedItem) style="background-color: #ecfdf5;" @endif>
    <td class="sticky left-0 z-[1] bg-white px-1.5 py-1.5 text-xs text-gray-900 @if ($isHighlightedItem) bg-emerald-50 @endif" @if ($isHighlightedItem) style="background-color: #ecfdf5;" @endif>{{ $item->item_sequence }}</td>
    <td class="sticky left-8 z-[1] min-w-[9rem] bg-white px-2 py-1.5 text-xs @if ($isHighlightedItem) bg-emerald-50 @endif" @if ($isHighlightedItem) style="background-color: #ecfdf5;" @endif>
        <span class="block truncate font-semibold text-gray-900" title="{{ $candidate['full_name'] ?? $missingDisplay }}">
            {{ $candidate['full_name'] ?? $missingDisplay }}
            @if (($candidate['name_needs_review'] ?? false))
                <span class="ml-1 rounded border border-amber-200 bg-amber-50 px-1 py-0.5 text-[10px] font-semibold text-amber-700">review</span>
            @endif
        </span>
        <span @class(['block truncate', 'font-medium text-red-700' => $mobileMissing || $mobileInvalid, 'text-gray-600' => ! $mobileMissing && ! $mobileInvalid])" title="{{ $candidate['mobile'] ?? '' }}">
            @if ($mobileMissing)
                Mobile: {{ $missingDisplay }}
            @elseif ($mobileInvalid)
                Mobile: <span class="text-red-700">⚠ invalid</span>
            @else
                Mobile: {{ $candidate['mobile'] ?? $missingDisplay }}
            @endif
        </span>
        @if ($candidateSummary)
            <span class="block truncate text-[11px] text-gray-600" title="{{ $candidateSummary }}">{{ $candidateSummary }}</span>
        @endif
        <span class="block truncate text-[10px] text-gray-500" title="{{ $itemDisplayLabel }}">{{ $itemDisplayLabel }}</span>
        <span class="block text-[10px] text-gray-500">{{ $itemTypeStatusLine }}</span>
        @if ($isUnclaimedIntake)
            <span class="block text-[10px] font-semibold text-amber-800">Unclaimed / consent pending</span>
        @endif
        @if ($usesReviewedSnapshot)
            <span data-testid="bulk-candidate-reviewed-badge" class="text-[10px] font-semibold text-emerald-700">Reviewed</span>
        @endif
        @if (!empty($contactPlan['active_mobile']))
            <span data-testid="bulk-contact-plan-active" class="block truncate text-[10px] text-sky-800" title="WhatsApp queue">
                WA: {{ $contactPlan['active_mobile'] }}
            </span>
        @endif
        @if (($contactPlan['suchak_count'] ?? 0) > 0)
            <span data-testid="bulk-suchak-directory-count" class="block text-[10px] text-violet-800">Suchak: {{ $contactPlan['suchak_count'] }}</span>
        @endif
    </td>
    <td class="px-2 py-1.5 text-[11px] leading-snug text-gray-700">
        <span @class(['block', 'text-amber-800' => ($candidate['dob_needs_review'] ?? false)])>
            @if ($candidate['dob_needs_review'] ?? false)
                <span class="rounded border border-amber-200 bg-amber-50 px-1 py-0.5 text-[10px] font-semibold text-amber-700">review</span>
            @endif
            {{ $candidate['date_of_birth'] ?? $missingDisplay }}
        </span>
        <span class="block">Age: {{ $candidate['age'] ?? $missingDisplay }}</span>
        <span @class(['block', 'text-amber-800' => ($candidate['height_needs_review'] ?? false)])>
            @if ($candidate['height_needs_review'] ?? false)⚠ @endif
            {{ $candidate['height'] ?? $missingDisplay }}
        </span>
        <span class="block">Gender: {{ filled($candidate['gender'] ?? null) ? ucfirst((string) $candidate['gender']) : $missingDisplay }}</span>
        <span class="block truncate" title="{{ $candidate['city'] ?? '' }}">{{ $candidate['city'] ?? $missingDisplay }}</span>
        <span @class(['block truncate', 'text-amber-800' => ($candidate['education_needs_review'] ?? false)])" title="{{ $candidate['education'] ?? '' }}">
            @if ($candidate['education_needs_review'] ?? false)⚠ @endif
            {{ $candidate['education'] ?? $missingDisplay }}
        </span>
        @if (filled($candidate['occupation'] ?? null))
            <span @class(['block truncate', 'text-amber-800' => ($candidate['occupation_needs_review'] ?? false)])" title="{{ $candidate['occupation'] }}">
                @if ($candidate['occupation_needs_review'] ?? false)⚠ @endif
                {{ $candidate['occupation'] }}
            </span>
        @endif
        @if (filled($candidate['religion'] ?? null))
            <span class="block truncate text-gray-600">Religion: {{ $candidate['religion'] }}</span>
        @endif
        @if (filled($candidate['caste'] ?? null))
            <span class="block truncate text-gray-600">Caste: {{ $candidate['caste'] }}</span>
        @endif
        @if (filled($candidate['sub_caste'] ?? null))
            <span class="block truncate text-gray-600">Sub: {{ $candidate['sub_caste'] }}</span>
        @endif
        @if (($candidate['name_needs_review'] ?? false))
            <span class="block text-amber-800">⚠ नाव तपासा</span>
        @endif
    </td>
    <td class="px-2 py-1.5 text-[11px] text-gray-700">
        @if ($intake)
            <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="font-medium text-indigo-600 hover:text-indigo-800">#{{ $intake->id }}</a>
            @if ($parseDone)
                <span class="block font-medium text-green-700">Parse: OK</span>
            @elseif ((string) ($intake?->parse_status ?? '') === 'error')
                <span class="block font-medium text-red-700">Parse error</span>
            @elseif ($hasEmptyOcrFailure)
                <span class="block font-medium text-red-700">OCR failed: no text extracted</span>
            @elseif ($item->item_status === \App\Models\BulkIntakeBatchItem::STATUS_PARSE_QUEUED)
                <span class="block font-medium text-amber-700">Free parse queued</span>
            @elseif ($parseStatus === 'pending')
                <span class="block text-gray-500">Waiting for free parse</span>
            @else
                <span class="block text-gray-600">{{ $parseStatus !== '' ? $parseStatus : $itemDisplayStatus }}</span>
            @endif
            <span class="block text-gray-600">Parsed JSON: {{ $hasParsedJson ? 'Yes' : 'No' }}</span>
            @if ($intake->last_error)
                <span class="block text-red-700" title="{{ $intake->last_error }}">{{ \Illuminate\Support\Str::limit($intake->last_error, 40) }}</span>
            @endif
        @else
            <span class="text-gray-600">{{ $itemDisplayStatus }}</span>
            <span class="block text-gray-600">Parsed JSON: No</span>
        @endif
        @if ($textPreview)
            <span class="block truncate text-[10px] text-gray-500" title="{{ $textPreview }}">{{ $textPreview }}</span>
        @endif
    </td>
    <td class="px-2 py-1.5 text-[11px]">
        <span data-testid="bulk-pipeline-badge" class="inline-block rounded-full border px-2 py-0.5 font-semibold {{ $pipelineBadgeClass }}">
            {{ $pipeline['bucket_label'] ?? 'Needs check' }}
            @if ($pipelineSource === 'override')
                <span class="font-normal">· override</span>
            @endif
        </span>
        @if ($mainPipelineReason !== '' && $pipelineBucket !== 'eligible')
            <span class="mt-0.5 block text-gray-700">{{ $mainPipelineReason }}</span>
        @endif
        @foreach (array_slice($visiblePipelineReasons, 1, 2) as $extraPipelineReason)
            <span data-testid="bulk-pipeline-reason" class="mt-0.5 block text-gray-600">{{ $extraPipelineReason['label'] ?? '' }}</span>
        @endforeach
        @if ($whatsappConsentStatus !== '')
            <span data-testid="bulk-whatsapp-consent-badge" class="mt-0.5 block text-sky-800">{{ $whatsappConsentLabel }}</span>
        @endif
        @if ($consentReceived && $registrationPath !== '')
            <span data-testid="bulk-registration-path-badge" class="mt-0.5 block text-violet-800">{{ $registrationPathLabel }}</span>
        @endif
        @if ($registrationStatus !== '')
            <span data-testid="bulk-registration-status-badge" class="mt-0.5 block text-gray-700">{{ $registrationStatusLabel }}</span>
        @endif
    </td>
    <td class="px-2 py-1.5 text-[11px] leading-snug">
        @forelse ($problemLines as $problemLine)
            <span
                @if (! empty($problemLine['testid'])) data-testid="{{ $problemLine['testid'] }}" @endif
                @if (! empty($problemLine['title'])) title="{{ $problemLine['title'] }}" @endif
                class="block {{ $problemLine['class'] }}"
            >{{ $problemLine['text'] }}</span>
        @empty
            <span class="text-gray-400">—</span>
        @endforelse
    </td>
    <td class="px-2 py-1.5 text-[10px] leading-snug text-gray-700" data-testid="bulk-item-journey">
        <span @class(['font-semibold', 'text-green-700' => true])">✓ Upload</span>
        <span class="text-gray-400"> · </span>
        <span @class(['font-semibold', 'text-green-700' => $parseDone, 'text-amber-700' => ! $parseDone])">{{ $parseDone ? '✓' : '○' }} Parse</span>
        <span class="text-gray-400"> · </span>
        <span @class(['font-semibold', 'text-green-700' => $gateDone, 'text-red-700' => $gateFail, 'text-amber-700' => ! $gateDone && ! $gateFail])">{{ $gateDone ? '✓' : ($gateFail ? '✕' : '?') }} Gate</span>
        <span class="text-gray-400"> · </span>
        <span @class(['font-semibold', 'text-green-700' => $waDone, 'text-sky-700' => $waSent && ! $waDone, 'text-gray-500' => ! $waSent])">{{ $waDone ? '✓' : ($waSent ? '→' : '—') }} WA</span>
        <span class="text-gray-400"> · </span>
        <span @class(['font-semibold', 'text-green-700' => $regDone, 'text-gray-500' => ! $regDone])">{{ $regDone ? '✓' : '—' }} Profile</span>
    </td>
    <td class="px-2 py-1.5 text-[11px] font-medium text-indigo-900" data-testid="bulk-item-next-action">
        {{ $nextActionShort }}
        @if ($suggestedNextAction !== '' && $suggestedNextAction !== $nextActionShort)
            <span class="mt-0.5 block text-[10px] font-normal text-gray-500" title="{{ $suggestedNextAction }}">{{ \Illuminate\Support\Str::limit($suggestedNextAction, 55) }}</span>
        @endif
    </td>
    <td class="px-2 py-1.5 text-right text-[11px]">
        <div class="flex flex-col items-end gap-1">
            @include('admin.bulk-intakes.partials.item-actions-panel')
        </div>
    </td>
</tr>
