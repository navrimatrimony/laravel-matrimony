@php
    /** @var \App\Models\BiodataIntake $intake */
    /** @var \App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult $comparisonResult */
    /** @var bool $embedded */

    $embedded = (bool) ($embedded ?? false);
    $table = $comparisonResult->table;
    $audit = $table?->audit ?? null;
    $emptyDisplay = '—';
@endphp

<div class="space-y-4" data-testid="ocr-comparison-review" data-ocr-comparison-surface="{{ $embedded ? 'correct_candidate' : 'standalone' }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="{{ $embedded ? 'text-lg font-semibold text-gray-900' : 'text-lg font-semibold text-gray-900' }}">
                OCR comparison review
            </h2>
            <p class="text-sm text-gray-600">
                Intake #{{ $intake->id }} — read-only ensemble comparison (Field / Final / Tesseract / Second OCR / Sarvam / Reason).
            </p>
        </div>
        @unless ($embedded)
            <a href="{{ route('admin.biodata-intakes.show', $intake) }}"
               class="text-sm font-medium text-indigo-700 hover:underline"
               data-testid="ocr-comparison-back-link">
                Back to intake
            </a>
        @endunless
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4"
         data-testid="ocr-comparison-outcome"
         data-outcome="{{ $comparisonResult->outcome }}"
         data-reason="{{ $comparisonResult->reason }}">
        <div class="flex flex-wrap items-center gap-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Outcome</div>
                <div class="text-sm font-semibold text-gray-900" data-testid="ocr-comparison-outcome-value">{{ $comparisonResult->outcome }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Reason</div>
                <div class="text-sm text-gray-900" data-testid="ocr-comparison-reason-value">{{ $comparisonResult->reason }}</div>
            </div>
            @if ($audit)
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Engines present</div>
                    <div class="text-sm text-gray-900" data-testid="ocr-comparison-engines-present">
                        {{ $audit->enginesPresent !== [] ? implode(', ', $audit->enginesPresent) : $emptyDisplay }}
                    </div>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Attempts</div>
                    <div class="text-sm text-gray-900" data-testid="ocr-comparison-attempt-count">{{ $audit->attemptCount }}</div>
                </div>
            @endif
        </div>
    </div>

    @if ($comparisonResult->wasSkipped())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
             data-testid="ocr-comparison-skipped-notice">
            Comparison is unavailable while Phase 5 is skipped ({{ $comparisonResult->reason }}).
        </div>
    @elseif ($table === null)
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700"
             data-testid="ocr-comparison-no-table-notice">
            No comparison table was produced.
        </div>
    @else
        @include('admin.intake.partials.ocr-comparison-table', [
            'table' => $table,
            'emptyDisplay' => $emptyDisplay,
            'isEmptyOutcome' => $comparisonResult->wasEmpty(),
        ])
    @endif

    @include('admin.intake.partials.ocr-engine-debug-metrics', [
        'ocrEngineDebugMetrics' => $ocrEngineDebugMetrics ?? [],
    ])

    @include('admin.intake.partials.ocr-attempt-raw-transcripts', [
        'ocrAttemptSummaries' => $ocrAttemptSummaries ?? [],
    ])
</div>
