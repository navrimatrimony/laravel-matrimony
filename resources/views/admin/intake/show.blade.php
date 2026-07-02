@extends('layouts.admin')

@section('content')
@php
    $fn = trim((string) ($intake->original_filename ?? ''));
    $fp = trim((string) ($intake->file_path ?? ''));
    $fallback = $fp !== '' ? basename($fp) : '';
    $display = $fn !== '' ? $fn : ($fallback !== '' ? $fallback : '—');
    $uploadedAt = $intake->created_at ?? null;
    $ext = strtolower(pathinfo($fp !== '' ? $fp : $display, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    $isPdf = $ext === 'pdf';
    $openUrl = $isImage ? route('intake.biodata-original', $intake) : null;
    $govProfile = $attachedProfile ?? $intake->profile;
    $govPendingConflicts = (int) ($pendingConflictCount ?? 0);
    $govSuggestionsPresent = (bool) ($pendingSuggestionsPresent ?? false);
    $govSuggestionsCount = (int) ($pendingSuggestionsCount ?? 0);
    $govRequireAdmin = (bool) ($requireAdminBeforeAttach ?? false);
    $govReadiness = $applyReadiness ?? [];
    $mutationResult = session('mutation_result');
    $mutationResult = is_array($mutationResult) ? $mutationResult : null;
    $sugSum = $pendingSuggestionsAdminSummary ?? ['has_any' => false, 'non_empty_bucket_count' => 0, 'buckets' => [], 'review_strip' => []];
    $sugBuckets = is_array($sugSum['buckets'] ?? null) ? $sugSum['buckets'] : [];
    $reviewStrip = is_array($sugSum['review_strip'] ?? null) ? $sugSum['review_strip'] : [];
    $card = 'bg-white border border-gray-200 rounded-xl shadow-sm';
    $cardTitle = 'text-sm font-semibold text-gray-900';
    $cardHint = 'text-xs text-gray-500';
    $label = 'text-xs font-semibold text-gray-500 uppercase tracking-wide';
    $value = 'text-sm text-gray-900';
    $parseInProgress = (string) ($intake->parse_status ?? '') === 'pending';
    $adminReviewEditor = is_array($adminReviewSnapshotEditor ?? null) ? $adminReviewSnapshotEditor : ['available' => false, 'can_save' => false, 'field_count' => 0, 'sections' => [], 'source' => 'empty'];
    $adminReviewSections = is_array($adminReviewEditor['sections'] ?? null) ? $adminReviewEditor['sections'] : [];
    $adminReviewCanSave = ! empty($adminReviewEditor['can_save']);
    $reviewerName = $intake->reviewedByUser->name ?? null;
    $adminQualitySignals = is_array($adminQualitySignals ?? null) ? $adminQualitySignals : ['has_any' => false, 'quality_summary' => null, 'failure_codes' => [], 'field_confidence_by_key' => [], 'field_confidence_by_path' => [], 'low_confidence_fields' => [], 'low_confidence_threshold' => 0.65];
    $qualitySummary = is_array($adminQualitySignals['quality_summary'] ?? null) ? $adminQualitySignals['quality_summary'] : null;
    $failureCodes = is_array($adminQualitySignals['failure_codes'] ?? null) ? $adminQualitySignals['failure_codes'] : [];
    $fieldConfidenceByKey = is_array($adminQualitySignals['field_confidence_by_key'] ?? null) ? $adminQualitySignals['field_confidence_by_key'] : [];
    $fieldConfidenceByPath = is_array($adminQualitySignals['field_confidence_by_path'] ?? null) ? $adminQualitySignals['field_confidence_by_path'] : [];
    $lowConfidenceFields = is_array($adminQualitySignals['low_confidence_fields'] ?? null) ? $adminQualitySignals['low_confidence_fields'] : [];
    $adminRoutingDryRun = is_array($adminRoutingDryRun ?? null) ? $adminRoutingDryRun : ['has_any' => false, 'recommendation' => [], 'signals' => [], 'telemetry' => []];
    $routingRecommendation = is_array($adminRoutingDryRun['recommendation'] ?? null) ? $adminRoutingDryRun['recommendation'] : [];
    $routingSignals = is_array($adminRoutingDryRun['signals'] ?? null) ? $adminRoutingDryRun['signals'] : [];
    $routingTelemetry = is_array($adminRoutingDryRun['telemetry'] ?? null) ? $adminRoutingDryRun['telemetry'] : [];
    $routingDisplay = static function ($value): string {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_float($value)) {
            return number_format($value, 2);
        }

        return trim((string) $value) !== '' ? (string) $value : '—';
    };
@endphp

<div class="max-w-6xl mx-auto text-gray-900" x-data="{ tab: 'review' }">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Intake #{{ $intake->id }}</h1>
            <p class="text-gray-600 text-sm mt-1">Review parsed biodata — start with the <strong class="font-semibold text-indigo-700">Parse review</strong> tab to spot mistakes.</p>
        </div>
        <a href="{{ route('admin.biodata-intakes.index') }}" class="text-sm text-indigo-700 hover:text-indigo-900 underline">← Back to intakes</a>
    </div>

    @if (session('success'))
        <div class="mb-3 px-4 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-3 px-4 py-2 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">{{ session('error') }}</div>
    @endif
    @if (session('warning'))
        <div class="mb-3 px-4 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-sm">{{ session('warning') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-3 px-4 py-2 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm">
            <ul class="list-disc list-inside">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($parseInProgress)
        <div id="admin-intake-parse-progress" class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 flex flex-wrap items-start gap-3" role="status" aria-live="polite">
            <svg class="animate-spin h-6 w-6 text-indigo-600 shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-indigo-950">{{ __('intake.admin_intake_parse_in_progress_title') }}</p>
                <p class="text-xs text-indigo-900/90 mt-0.5">{{ __('intake.admin_intake_parse_in_progress_help') }}</p>
            </div>
        </div>
    @endif

    {{-- Compact status strip --}}
    <div class="{{ $card }} p-4 mb-4 flex flex-wrap items-center gap-3 text-sm">
        @php
            $parse = (string) ($intake->parse_status ?? '');
            $parseChip = $parse === 'parsed'
                ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
                : ($parse === 'error' ? 'bg-red-50 text-red-800 border-red-200' : 'bg-indigo-50 text-indigo-800 border-indigo-200');
        @endphp
        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold border border-gray-200 bg-gray-50">{{ $intake->intake_status }}</span>
        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-semibold border {{ $parseChip }}">
            @if ($parseInProgress)
                <svg class="animate-spin h-3.5 w-3.5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            @endif
            {{ $parse !== '' ? $parse : '—' }}
        </span>
        <span class="text-gray-600">Owner: <strong class="text-gray-900">{{ $intake->uploadedByUser->name ?? '—' }}</strong></span>
        @if ($intake->profile)
            <span class="text-gray-600">Profile: <strong class="text-gray-900">#{{ $intake->profile->id }}</strong> {{ $intake->profile->full_name }}</span>
        @else
            <span class="text-amber-700 text-xs font-medium">Not attached to profile</span>
        @endif
        @if (! empty($intake->reviewed_at))
            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold border border-sky-200 bg-sky-50 text-sky-800">Reviewed: {{ $intake->approval_status ?? 'reviewed' }}</span>
        @endif
    </div>

    @include('admin.intake.show._tab-nav')

    {{-- TAB: Parse review --}}
    <div x-show="tab === 'review'" x-cloak class="space-y-6">
        @include('intake.partials.normalized-draft-preview', [
            'normalizedDraftPreview' => $normalizedDraftPreview ?? null,
            'intakePhotoPreview' => $intakePhotoPreview ?? null,
            'adminLightMode' => true,
            'hideEmptySections' => true,
            'parseInProgress' => $parseInProgress,
            'draftCorrectionApplyEnabled' => ! $intake->approved_by_user && ! $intake->intake_locked && ! $parseInProgress,
            'draftCorrectionApplyRoute' => route('admin.biodata-intakes.apply-draft-correction', $intake),
        ])

        <div class="{{ $card }} p-5">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <h2 class="{{ $cardTitle }} mb-1">Human-reviewed snapshot</h2>
                    <p class="{{ $cardHint }}">Edit parsed/review fields, then save only the intake approval snapshot.</p>
                </div>
                <span class="inline-flex px-2 py-0.5 rounded-full border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700">
                    Source: {{ $adminReviewEditor['source'] ?? 'empty' }}
                </span>
            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-xs mb-4">
                <div><dt class="{{ $label }}">Reviewer</dt><dd class="{{ $value }} mt-0.5">{{ $reviewerName ? $reviewerName.' (#'.$intake->reviewed_by_user_id.')' : ($intake->reviewed_by_user_id ? '#'.$intake->reviewed_by_user_id : '—') }}</dd></div>
                <div><dt class="{{ $label }}">Actor</dt><dd class="{{ $value }} mt-0.5">{{ $intake->review_actor_type ?? '—' }}</dd></div>
                <div><dt class="{{ $label }}">Surface</dt><dd class="{{ $value }} mt-0.5">{{ $intake->review_surface ?? '—' }}</dd></div>
                <div><dt class="{{ $label }}">Reviewed at</dt><dd class="{{ $value }} mt-0.5">{{ $intake->reviewed_at ? $intake->reviewed_at->toDateTimeString() : '—' }}</dd></div>
                <div><dt class="{{ $label }}">Status</dt><dd class="{{ $value }} mt-0.5">{{ $intake->approval_status ?? '—' }}</dd></div>
                <div><dt class="{{ $label }}">Policy</dt><dd class="{{ $value }} mt-0.5">{{ $intake->approval_policy ?? '—' }}</dd></div>
            </dl>

            @if (! empty($adminQualitySignals['has_any']))
                @php
                    $qualityScore = is_numeric($qualitySummary['score'] ?? null) ? (float) $qualitySummary['score'] : null;
                    $qualityPercent = $qualityScore !== null ? (int) round($qualityScore * 100) : null;
                    $qualityLow = (bool) ($qualitySummary['is_low'] ?? false);
                    $layoutScore = is_numeric($qualitySummary['layout_score'] ?? null) ? (float) $qualitySummary['layout_score'] : null;
                @endphp
                <div data-testid="quality-signals-panel" class="mb-4 rounded-lg border border-amber-200 bg-amber-50/60 p-3 text-xs text-amber-950">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <h3 class="text-sm font-semibold text-amber-950">Quality signals</h3>
                        @if ($qualityScore !== null)
                            <span class="inline-flex px-2 py-0.5 rounded-full border {{ $qualityLow ? 'border-amber-300 bg-amber-100 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }} font-semibold">
                                Overall: {{ $qualityPercent }}% {{ $qualityLow ? 'Low' : 'OK' }}
                            </span>
                        @endif
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <div class="{{ $label }} text-amber-800">Quality</div>
                            <div class="mt-1 text-sm font-semibold text-amber-950">{{ $qualityScore !== null ? number_format($qualityScore, 2) : '—' }}</div>
                            @if ($layoutScore !== null)
                                <div class="mt-1 text-[11px] text-amber-900">Layout: {{ number_format($layoutScore, 2) }}</div>
                            @endif
                        </div>
                        <div>
                            <div class="{{ $label }} text-amber-800">Failure codes</div>
                            @if ($failureCodes !== [])
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($failureCodes as $code)
                                        <span data-testid="quality-failure-code" class="inline-flex px-2 py-0.5 rounded-full border border-red-200 bg-red-50 text-red-800 font-semibold">{{ $code }}</span>
                                    @endforeach
                                </div>
                            @else
                                <div class="mt-1 text-sm text-amber-900">—</div>
                            @endif
                        </div>
                        <div>
                            <div class="{{ $label }} text-amber-800">Low-confidence fields</div>
                            @if ($lowConfidenceFields !== [])
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($lowConfidenceFields as $lowField)
                                        @php
                                            $lowScore = is_numeric($lowField['score'] ?? null) ? (float) $lowField['score'] : null;
                                            $lowScoreLabel = $lowScore !== null ? ' '.((int) round($lowScore * 100)).'%' : '';
                                        @endphp
                                        <span data-testid="quality-low-confidence-summary" class="inline-flex px-2 py-0.5 rounded-full border border-amber-300 bg-white text-amber-900 font-semibold">
                                            {{ ($lowField['label'] ?? $lowField['key'] ?? 'Field').$lowScoreLabel }}
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <div class="mt-1 text-sm text-amber-900">—</div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if (! empty($adminRoutingDryRun['has_any']))
                @php
                    $routingConfidence = is_numeric($routingRecommendation['confidence'] ?? null) ? (float) $routingRecommendation['confidence'] : null;
                    $routingConfidenceLabel = $routingConfidence !== null ? number_format($routingConfidence, 2).' ('.((int) round($routingConfidence * 100)).'%)' : '—';
                    $routingReasonCodes = is_array($routingRecommendation['reason_codes'] ?? null) ? $routingRecommendation['reason_codes'] : [];
                    $routingTelemetryRows = [
                        'sarvam_attempt_count' => 'Sarvam attempts',
                        'cheap_ocr_attempt_count' => 'Cheap OCR attempts',
                        'failed_provider_count' => 'Provider failures',
                        'reuse_candidate_found' => 'Reuse candidate',
                        'last_provider_failure_code' => 'Last provider failure',
                        'last_quality_score' => 'Last quality score',
                        'last_layout_score' => 'Last layout score',
                        'duration_ms' => 'Duration ms',
                        'cost_units' => 'Cost units',
                    ];
                @endphp
                <div data-testid="routing-dry-run-panel" class="routing-dry-run-panel mb-4 rounded-lg border border-sky-200 bg-sky-50/60 p-3 text-xs text-sky-950">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <h3 class="text-sm font-semibold text-sky-950">Routing dry run</h3>
                        <span class="inline-flex px-2 py-0.5 rounded-full border border-sky-200 bg-white text-sky-800 font-semibold">Read only</span>
                    </div>

                    <dl class="grid grid-cols-1 md:grid-cols-5 gap-3">
                        <div>
                            <dt class="{{ $label }} text-sky-800">Recommended action</dt>
                            <dd data-testid="routing-recommended-action" class="routing-recommended-action mt-1 text-sm font-semibold text-sky-950">{{ $routingRecommendation['recommended_action'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="{{ $label }} text-sky-800">Confidence</dt>
                            <dd class="mt-1 text-sm font-semibold text-sky-950">{{ $routingConfidenceLabel }}</dd>
                        </div>
                        <div>
                            <dt class="{{ $label }} text-sky-800">Would skip paid vision</dt>
                            <dd data-testid="routing-would-skip-paid-vision" class="mt-1 text-sm font-semibold text-sky-950">{{ $routingDisplay($routingRecommendation['would_skip_paid_vision'] ?? null) }}</dd>
                        </div>
                        <div>
                            <dt class="{{ $label }} text-sky-800">Would call paid vision</dt>
                            <dd data-testid="routing-would-call-paid-vision" class="mt-1 text-sm font-semibold text-sky-950">{{ $routingDisplay($routingRecommendation['would_call_paid_vision'] ?? null) }}</dd>
                        </div>
                        <div>
                            <dt class="{{ $label }} text-sky-800">Reason codes</dt>
                            <dd data-testid="routing-reason-codes" class="routing-reason-codes mt-1 flex flex-wrap gap-1">
                                @if ($routingReasonCodes !== [])
                                    @foreach ($routingReasonCodes as $code)
                                        <span class="inline-flex px-2 py-0.5 rounded-full border border-sky-200 bg-white text-sky-900 font-semibold">{{ $code }}</span>
                                    @endforeach
                                @else
                                    <span class="text-sm text-sky-900">—</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mt-3 pt-3 border-t border-sky-200/80">
                        <div>
                            <div class="{{ $label }} text-sky-800 mb-1">Telemetry</div>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1">
                                @foreach ($routingTelemetryRows as $routingTelemetryKey => $routingTelemetryLabel)
                                    @if (array_key_exists($routingTelemetryKey, $routingTelemetry) && $routingTelemetry[$routingTelemetryKey] !== null)
                                        <div data-testid="routing-telemetry-{{ str_replace('_', '-', $routingTelemetryKey) }}" class="flex items-baseline justify-between gap-2 rounded bg-white/70 px-2 py-1">
                                            <dt class="text-sky-800">{{ $routingTelemetryLabel }}</dt>
                                            <dd class="font-semibold text-sky-950">{{ $routingDisplay($routingTelemetry[$routingTelemetryKey]) }}</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                        </div>
                        <div>
                            <div class="{{ $label }} text-sky-800 mb-1">Routing signals</div>
                            @if ($routingSignals !== [])
                                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1">
                                    @foreach ($routingSignals as $signal)
                                        <div class="flex items-baseline justify-between gap-2 rounded bg-white/70 px-2 py-1">
                                            <dt class="text-sky-800">{{ $signal['label'] ?? $signal['key'] ?? 'Signal' }}</dt>
                                            <dd class="font-semibold text-sky-950 text-right">{{ $signal['value'] ?? '—' }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @else
                                <p class="text-sm text-sky-900">—</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if (! empty($adminReviewEditor['available']) && $adminReviewSections !== [])
                <form method="POST" action="{{ route('admin.biodata-intakes.review-snapshot.update', $intake) }}" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    @foreach ($adminReviewSections as $reviewSection)
                        @php
                            $reviewFields = is_array($reviewSection['fields'] ?? null) ? $reviewSection['fields'] : [];
                        @endphp
                        @if ($reviewFields === [])
                            @continue
                        @endif
                        <details class="rounded-lg border border-gray-200 bg-gray-50/60 overflow-hidden" @if ($loop->first) open @endif>
                            <summary class="cursor-pointer select-none px-3 py-2 bg-white border-b border-gray-200 flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-gray-900">{{ $reviewSection['label'] ?? $reviewSection['key'] ?? 'Section' }}</span>
                                <span class="text-[11px] font-semibold text-gray-500">{{ count($reviewFields) }} field(s)</span>
                            </summary>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-3">
                                @foreach ($reviewFields as $field)
                                    @php
                                        $fieldName = (string) ($field['name'] ?? '');
                                        $fieldOldKey = (string) ($field['old_key'] ?? '');
                                        $fieldValue = old($fieldOldKey, (string) ($field['value'] ?? ''));
                                        $fieldLabel = (string) ($field['label'] ?? $fieldOldKey);
                                        $isMultiline = ! empty($field['multiline']);
                                        $fieldPath = str_starts_with($fieldOldKey, 'snapshot.') ? substr($fieldOldKey, 9) : $fieldOldKey;
                                        $fieldSegments = array_values(array_filter(explode('.', $fieldPath), static fn (string $segment): bool => ! ctype_digit($segment)));
                                        $fieldLeaf = $fieldSegments !== [] ? (string) end($fieldSegments) : $fieldPath;
                                        $confidenceSignal = $fieldConfidenceByPath[$fieldPath] ?? ($fieldConfidenceByKey[$fieldLeaf] ?? null);
                                        $confidenceSignal = is_array($confidenceSignal) ? $confidenceSignal : null;
                                        $confidenceScore = is_numeric($confidenceSignal['score'] ?? null) ? (float) $confidenceSignal['score'] : null;
                                        $confidenceScoreLabel = $confidenceScore !== null ? ' '.((int) round($confidenceScore * 100)).'%' : '';
                                        $isLowConfidenceField = ! empty($confidenceSignal['is_low']);
                                        $confidenceTestId = 'low-confidence-field-'.str_replace(['.', '_'], '-', $fieldPath);
                                        $inputClass = $isLowConfidenceField
                                            ? 'w-full rounded-lg border-amber-300 bg-amber-50 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 disabled:bg-gray-100'
                                            : 'w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100';
                                    @endphp
                                    <label @if ($isLowConfidenceField) data-testid="{{ $confidenceTestId }}" @endif class="block text-xs {{ $isLowConfidenceField ? 'admin-low-confidence-field rounded-lg border border-amber-200 bg-amber-50/40 p-2' : '' }}">
                                        <span class="flex flex-wrap items-center gap-2 font-semibold text-gray-700 mb-1">
                                            <span>{{ $fieldLabel }}</span>
                                            @if ($isLowConfidenceField)
                                                <span class="inline-flex px-1.5 py-0.5 rounded-full border border-amber-300 bg-white text-[10px] font-bold uppercase tracking-wide text-amber-900">
                                                    {{ 'Low confidence'.$confidenceScoreLabel }}
                                                </span>
                                            @endif
                                        </span>
                                        @if ($isMultiline)
                                            <textarea name="{{ $fieldName }}" rows="3" @disabled(! $adminReviewCanSave || $parseInProgress) class="{{ $inputClass }}">{{ $fieldValue }}</textarea>
                                        @else
                                            <input type="text" name="{{ $fieldName }}" value="{{ $fieldValue }}" @disabled(! $adminReviewCanSave || $parseInProgress) class="{{ $inputClass }}">
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    @endforeach

                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        <button type="submit" @disabled(! $adminReviewCanSave || $parseInProgress) class="inline-flex px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-sm font-semibold text-white disabled:opacity-50 disabled:cursor-not-allowed">Save reviewed snapshot</button>
                        @if ($parseInProgress)
                            <span class="text-xs text-amber-700">Parsing is still pending.</span>
                        @elseif (! $adminReviewCanSave)
                            <span class="text-xs text-amber-700">Snapshot editing is blocked after approval or lock.</span>
                        @else
                            <span class="text-xs text-gray-500">This does not apply data to the profile.</span>
                        @endif
                    </div>
                </form>
            @else
                <p class="text-sm text-gray-500">No parsed/review fields are available for admin snapshot editing.</p>
            @endif
        </div>

        @if (! empty($unresolvedLocationOptions) && is_array($unresolvedLocationOptions))
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-5">
                <h2 class="{{ $cardTitle }} text-amber-900 mb-1">Resolve locations</h2>
                <p class="{{ $cardHint }} text-amber-800 mb-3">Parsed place names without a matched location ID — pick the correct match.</p>
                <div class="space-y-4">
                    @foreach ($unresolvedLocationOptions as $loc)
                        @php $opts = is_array($loc['options'] ?? null) ? $loc['options'] : []; @endphp
                        <div class="rounded-lg border border-amber-200 bg-white p-3">
                            <div class="{{ $label }} mb-1">{{ $loc['label'] ?? ($loc['field_key'] ?? 'Location') }}</div>
                            @if (! empty($loc['raw_input']))
                                <p class="text-sm text-gray-900 mb-2">From biodata: <strong>"{{ $loc['raw_input'] }}"</strong></p>
                            @endif
                            @if ($opts !== [])
                                <div class="space-y-2 admin-intake-loc-options-list">
                                    @foreach ($opts as $opt)
                                        <div class="flex flex-wrap items-center gap-2 text-xs rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1.5">
                                            <span class="font-semibold text-gray-900">{{ $opt['display_label'] ?? $opt['name'] ?? $opt['city_name'] ?? '—' }}</span>
                                            <button type="button" class="admin-intake-loc-apply-btn px-2 py-0.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-semibold"
                                                data-field="{{ $loc['field_key'] ?? '' }}" data-city-id="{{ $opt['city_id'] ?? '' }}">{{ __('intake.parse_suggestion_apply') }}</button>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-xs text-gray-600">No quick matches.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- TAB: Source & file --}}
    <div x-show="tab === 'source'" x-cloak class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="{{ $card }} p-5">
                <h2 class="{{ $cardTitle }} mb-1">Uploaded file</h2>
                <p class="{{ $cardHint }} mb-3">{{ $display }}</p>
                <dl class="space-y-3 text-sm">
                    <div><dt class="{{ $label }}">Uploaded</dt><dd class="{{ $value }} mt-0.5">{{ $uploadedAt ? $uploadedAt->toDateTimeString() : '—' }}</dd></div>
                    @if ($fp !== '')
                        <div><dt class="{{ $label }}">Stored path</dt><dd class="font-mono text-xs text-gray-700 break-all mt-0.5">{{ $fp }}</dd></div>
                    @endif
                </dl>
                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($openUrl)
                        <a href="{{ $openUrl }}" target="_blank" class="inline-flex px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold">Open biodata image →</a>
                        <button type="button" class="inline-flex px-3 py-2 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-xs font-semibold text-gray-800"
                            onclick="document.getElementById('uploadPreviewDialog')?.showModal();">Preview</button>
                    @elseif ($isPdf)
                        <span class="text-xs text-gray-500">PDF preview not available here.</span>
                    @else
                        <span class="text-xs text-gray-500">No file preview for this type.</span>
                    @endif
                </div>
            </div>
            <div class="{{ $card }} p-5">
                <h2 class="{{ $cardTitle }} mb-1">Compare tip</h2>
                <p class="text-sm text-gray-700 leading-relaxed">Open the biodata image on the left, then compare it with the parse input text below. If the normalized draft looks wrong, check whether the parser received the expected text.</p>
                <a href="{{ route('intake.preview', $intake) }}" target="_blank" class="mt-3 inline-flex text-xs font-semibold text-indigo-700 hover:underline">User preview (side-by-side) →</a>
            </div>
        </div>

        <div class="{{ $card }} p-5">
            <h2 class="{{ $cardTitle }} mb-1">{{ __('intake.admin_parse_input_heading') }}</h2>
            <p class="{{ $cardHint }} mb-3">{{ __('intake.admin_parse_input_subtitle') }}</p>
            <pre class="bg-gray-50 border border-gray-200 p-3 rounded-lg overflow-auto max-h-[28rem] text-xs whitespace-pre-wrap text-gray-900 font-mono leading-relaxed">{{ $reviewParse['text'] !== '' ? $reviewParse['text'] : '(empty)' }}</pre>
        </div>
    </div>

    {{-- TAB: Actions & apply --}}
    <div x-show="tab === 'actions'" x-cloak class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="{{ $card }} p-5 space-y-4">
                <div>
                    <h2 class="{{ $cardTitle }} mb-1">Parsing actions</h2>
                    <p class="{{ $cardHint }}">Same extracted text — re-runs rules/parser only.</p>
                </div>
                <form method="POST" action="{{ route('admin.biodata-intakes.reparse', $intake) }}">
                    @csrf
                    <button type="submit" @disabled($parseInProgress) class="w-full px-3 py-2.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-sm font-semibold text-gray-900 disabled:opacity-50 disabled:cursor-not-allowed">Re-parse (no new AI extraction)</button>
                </form>
                @if (! empty($showAdminReextractAction))
                    <form method="POST" action="{{ route('admin.biodata-intakes.re-extract', $intake) }}" onsubmit="return confirm('Run paid vision extraction again?');">
                        @csrf
                        <button type="submit" @disabled($parseInProgress) class="w-full px-3 py-2.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-sm font-semibold text-gray-900 disabled:opacity-50 disabled:cursor-not-allowed">Re-extract (vision again)</button>
                        <p class="mt-1 text-[11px] text-amber-800">Paid API — re-reads text from the uploaded file.</p>
                    </form>
                @endif
            </div>

            <div class="{{ $card }} p-5">
                <h2 class="{{ $cardTitle }} mb-1">Apply to profile</h2>
                @php
                    $ready = (bool) ($govReadiness['can_admin_apply'] ?? false);
                    $missing = [];
                    if (! ($govReadiness['user_approved'] ?? false)) { $missing[] = 'user approval'; }
                    if (! ($govReadiness['attached_profile'] ?? false)) { $missing[] = 'attached profile'; }
                    if (! ($govReadiness['has_snapshot'] ?? false)) { $missing[] = 'approval snapshot'; }
                    if (! ($govReadiness['admin_required'] ?? false)) { $missing[] = 'admin apply mode in settings'; }
                @endphp
                <p class="text-sm mb-3 {{ $ready ? 'text-emerald-700' : 'text-amber-800' }}">
                    {{ $ready ? 'Ready to apply.' : 'Not ready: '.($missing === [] ? 'check intake state.' : implode(', ', $missing).'.') }}
                </p>
                <form method="POST" action="{{ route('admin.biodata-intakes.apply', $intake) }}">
                    @csrf
                    <button type="submit" class="w-full px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold text-white">Apply intake to profile</button>
                </form>
            </div>
        </div>

        <div class="{{ $card }} p-5">
            <h2 class="{{ $cardTitle }} mb-3">Governance checklist</h2>
            <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                <li class="flex items-center gap-2"><span class="{{ ! empty($govReadiness['user_approved']) ? 'text-emerald-600' : 'text-gray-400' }}">●</span> User approved</li>
                <li class="flex items-center gap-2"><span class="{{ ! empty($govReadiness['attached_profile']) ? 'text-emerald-600' : 'text-gray-400' }}">●</span> Profile attached</li>
                <li class="flex items-center gap-2"><span class="{{ ! empty($govReadiness['has_snapshot']) ? 'text-emerald-600' : 'text-gray-400' }}">●</span> Approval snapshot</li>
                <li class="flex items-center gap-2"><span class="{{ $govRequireAdmin ? 'text-emerald-600' : 'text-gray-400' }}">●</span> Admin apply mode enabled</li>
                <li class="flex items-center gap-2"><span class="{{ $govPendingConflicts === 0 ? 'text-emerald-600' : 'text-amber-600' }}">●</span> Pending conflicts: {{ $govPendingConflicts }}</li>
            </ul>
            @if ($govPendingConflicts > 0 && ! empty($recentPendingConflicts) && $recentPendingConflicts->isNotEmpty())
                <div class="mt-3 text-xs text-gray-600">
                    @foreach ($recentPendingConflicts as $rc)
                        <a href="{{ route('admin.conflict-records.show', $rc) }}" class="text-indigo-700 hover:underline mr-2">Conflict #{{ $rc->id }}</a>
                    @endforeach
                </div>
            @endif
            @if ($mutationResult)
                <div class="mt-4 pt-4 border-t border-gray-200 text-xs text-gray-700 space-y-1">
                    <div>Last apply — success: {{ ! empty($mutationResult['mutation_success']) ? 'yes' : 'no' }}, conflict: {{ ! empty($mutationResult['conflict_detected']) ? 'yes' : 'no' }}</div>
                </div>
            @endif
        </div>

        @if ($govSuggestionsPresent)
            <div class="{{ $card }} p-5">
                <h2 class="{{ $cardTitle }} mb-1">Pending suggestions on profile</h2>
                <p class="{{ $cardHint }} mb-3">{{ $govSuggestionsCount }} bucket(s) — open profile review for details.</p>
                <div class="flex flex-wrap gap-2">
                    @if ($govProfile)
                        <a href="{{ route('admin.suggestions.review', $intake) }}" class="text-xs font-semibold text-indigo-700 hover:underline">Admin suggestion review →</a>
                        <a href="{{ route('admin.profiles.show', $govProfile->id) }}" class="text-xs font-semibold text-indigo-700 hover:underline">Open profile →</a>
                    @endif
                    <a href="{{ route('intake.status', $intake) }}" target="_blank" class="text-xs font-semibold text-indigo-700 hover:underline">Member suggestion page →</a>
                </div>
            </div>
        @endif
    </div>

    {{-- TAB: Technical --}}
    <div x-show="tab === 'technical'" x-cloak class="space-y-6">
        <div class="{{ $card }} p-5">
            <h2 class="{{ $cardTitle }} mb-1">Parse &amp; extraction diagnostics</h2>
            @if (! empty($diagnosticsUnavailableReason ?? null))
                <p class="text-sm text-amber-800 mb-2">{{ $diagnosticsUnavailableReason }}</p>
                <p class="text-xs text-gray-500">Run Re-parse to refresh debug metadata.</p>
            @else
                @php
                    $s = $diagnostics['summary'] ?? [];
                    $row = fn (string $label, $value) => [$label, is_bool($value) ? ($value ? 'Yes' : 'No') : (trim((string) $value) !== '' ? (string) $value : '—')];
                    $rows = [
                        $row('Parser mode', $s['parser_mode_label'] ?? null),
                        $row('Parser version', $intake->parser_version ?? '—'),
                        $row('AI provider', $s['ai_provider_label'] ?? null),
                        $row('Parse input source', $s['autofill_source_label'] ?? null),
                        $row('Transcript used', $s['transcript_used_label'] ?? null),
                        $row('Quality ok', $meta['parse_input_text_quality_ok'] ?? null),
                        $row('Chars / lines', ($meta['parse_input_text_chars'] ?? '—').' / '.($meta['parse_input_text_lines'] ?? '—')),
                    ];
                @endphp
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm mt-2">
                    @foreach ($rows as [$k, $v])
                        <div><dt class="{{ $label }}">{{ $k }}</dt><dd class="{{ $value }} mt-0.5 break-words">{{ $v }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </div>

        <details class="{{ $card }} p-5">
            <summary class="{{ $cardTitle }} cursor-pointer select-none">Technical details (internal codes)</summary>
            @php
                $dbgArr = is_array($dbg ?? null) ? $dbg : [];
                $oq = is_array($ocrQuality ?? null) ? $ocrQuality : [];
                $get = function (array $a, string $k) {
                    if (! array_key_exists($k, $a)) { return '—'; }
                    $v = $a[$k];
                    if (is_bool($v)) { return $v ? 'true' : 'false'; }
                    if ($v === null) { return '—'; }
                    $s = trim((string) $v);
                    return $s !== '' ? $s : '—';
                };
            @endphp
            <pre class="mt-3 bg-gray-50 border border-gray-200 p-3 rounded-lg overflow-auto max-h-64 text-xs text-gray-800 font-mono">parse_input_source: {{ $get($dbgArr, 'parse_input_source') }}
provider: {{ $get($dbgArr, 'provider') }}
model: {{ $get($dbgArr, 'model') }}
ocr_quality.score: {{ $get($oq, 'score') }}</pre>
        </details>

        <details class="{{ $card }} p-5">
            <summary class="{{ $cardTitle }} cursor-pointer select-none">Parsed JSON (stored snapshot)</summary>
            <pre class="mt-3 bg-gray-50 border border-gray-200 p-3 rounded-lg overflow-auto max-h-[32rem] text-xs text-gray-800 font-mono">{{ json_encode($intake->parsed_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </details>

        @if (config('app.debug') && config('intake.debug_show_stored_raw_ocr'))
            <details class="{{ $card }} p-5 border-dashed border-amber-300">
                <summary class="{{ $cardTitle }} cursor-pointer text-amber-900">Debug: stored raw OCR</summary>
                <pre class="mt-3 bg-gray-50 border border-gray-200 p-3 rounded-lg overflow-auto max-h-48 text-xs font-mono">{{ $intake->raw_ocr_text ?? '(empty)' }}</pre>
            </details>
        @endif
    </div>

    @if ($openUrl)
        <dialog id="uploadPreviewDialog" class="backdrop:bg-black/50 rounded-lg p-0 w-[min(900px,95vw)]">
            <div class="bg-white border border-gray-200 rounded-lg">
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
                    <div class="text-sm font-semibold text-gray-900 truncate">{{ $display }}</div>
                    <button type="button" class="text-xs font-semibold text-gray-600 hover:text-gray-900" onclick="document.getElementById('uploadPreviewDialog')?.close();">Close</button>
                </div>
                <div class="p-3 bg-gray-50">
                    <img src="{{ $openUrl }}" alt="Biodata preview" class="max-h-[75vh] w-auto mx-auto rounded">
                </div>
            </div>
        </dialog>
    @endif
</div>

<style>[x-cloak]{display:none!important}</style>

<script>
(function () {
    var resolveUrl = @json(route('admin.biodata-intakes.resolve-location', $intake));
    document.querySelectorAll('.admin-intake-loc-apply-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = btn.getAttribute('data-field');
            var cityId = btn.getAttribute('data-city-id');
            if (!field || !cityId) return;
            btn.disabled = true;
            fetch(resolveUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                body: JSON.stringify({ field: field, city_id: parseInt(cityId, 10) })
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); })
                .then(function (res) {
                    if (res.ok && res.data && res.data.success) { window.location.reload(); }
                    else { btn.disabled = false; window.alert((res.data && res.data.message) ? res.data.message : 'Could not resolve.'); }
                }).catch(function () { btn.disabled = false; window.alert('Network error.'); });
        });
    });

    @if ($parseInProgress)
    (function () {
        var statusUrl = @json(route('admin.biodata-intakes.parse-status', $intake));
        var timeoutMessage = @json(__('intake.admin_intake_parse_poll_timeout'));
        var attempts = 0;
        var maxAttempts = 120;

        function pollParseStatus() {
            attempts += 1;
            fetch(statusUrl, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (r) { return r.json(); })
                .then(function (data) {
                    var status = String((data && data.parse_status) || '');
                    if (status === 'parsed' || status === 'error') {
                        window.location.reload();
                        return;
                    }
                    if (attempts >= maxAttempts) {
                        var banner = document.getElementById('admin-intake-parse-progress');
                        if (banner) {
                            banner.classList.remove('border-indigo-200', 'bg-indigo-50');
                            banner.classList.add('border-amber-300', 'bg-amber-50');
                            var help = banner.querySelector('p.text-xs');
                            if (help) {
                                help.textContent = timeoutMessage;
                            }
                        }
                        return;
                    }
                    window.setTimeout(pollParseStatus, 3000);
                })
                .catch(function () {
                    if (attempts < maxAttempts) {
                        window.setTimeout(pollParseStatus, 5000);
                    }
                });
        }

        window.setTimeout(pollParseStatus, 2500);
    })();
    @endif
})();
</script>
@endsection
