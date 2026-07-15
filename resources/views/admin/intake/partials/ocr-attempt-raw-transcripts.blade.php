@php
    /** @var list<\App\Services\Intake\OcrEnsemble\Data\OcrComparisonAttemptSummary>|mixed $ocrAttemptSummaries */
    $ocrAttemptSummaries = is_array($ocrAttemptSummaries ?? null) ? $ocrAttemptSummaries : [];
@endphp

<section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
         data-testid="ocr-attempt-raw-transcripts">
    <h3 class="text-sm font-semibold text-gray-900">Per-engine Raw OCR</h3>
    <p class="mt-1 text-xs text-gray-600">
        Transcripts from <code class="text-[11px]">biodata_intake_ocr_attempts</code> (read-only). Use this to judge OCR quality before Phase 3 fields.
    </p>

    @if ($ocrAttemptSummaries === [])
        <p class="mt-3 text-sm text-gray-600" data-testid="ocr-attempt-raw-empty">
            No OCR attempts recorded for this intake yet.
        </p>
    @else
        <div class="mt-3 space-y-3">
            @foreach ($ocrAttemptSummaries as $index => $attempt)
                @php
                    $engine = is_object($attempt) ? (string) ($attempt->engine ?? '') : (string) ($attempt['engine'] ?? '');
                    $attemptId = is_object($attempt) ? (int) ($attempt->attemptId ?? 0) : (int) ($attempt['attempt_id'] ?? 0);
                    $isPrimary = is_object($attempt) ? (bool) ($attempt->isPrimary ?? false) : (bool) ($attempt['is_primary'] ?? false);
                    $status = is_object($attempt) ? (string) ($attempt->status ?? '') : (string) ($attempt['status'] ?? '');
                    $quality = is_object($attempt) ? ($attempt->qualityScore ?? null) : ($attempt['quality_score'] ?? null);
                    $raw = is_object($attempt) ? ($attempt->rawText ?? null) : ($attempt['raw_text'] ?? null);
                    $raw = is_string($raw) ? $raw : '';
                    $hasText = trim($raw) !== '';
                @endphp
                <details class="rounded-lg border border-gray-200 bg-gray-50"
                         data-testid="ocr-attempt-raw-{{ $index }}"
                         data-engine="{{ $engine }}"
                         @if ($isPrimary) open @endif>
                    <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-gray-900">
                        {{ $engine !== '' ? $engine : 'unknown_engine' }}
                        @if ($isPrimary)
                            <span class="ml-2 rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold text-indigo-800">primary</span>
                        @endif
                        @if ($status !== '')
                            <span class="ml-2 text-xs font-normal text-gray-500">{{ $status }}</span>
                        @endif
                        @if ($quality !== null && $quality !== '')
                            <span class="ml-2 text-xs font-normal text-gray-500">quality={{ $quality }}</span>
                        @endif
                        <span class="ml-2 text-xs font-normal text-gray-400">#{{ $attemptId }}</span>
                    </summary>
                    @if ($hasText)
                        <pre class="max-h-72 overflow-auto whitespace-pre-wrap border-t border-gray-200 bg-white p-3 text-xs leading-relaxed text-gray-800"
                             data-testid="ocr-attempt-raw-text-{{ $index }}">{{ $raw }}</pre>
                    @else
                        <p class="border-t border-gray-200 px-3 py-2 text-sm text-amber-800"
                           data-testid="ocr-attempt-raw-missing-{{ $index }}">
                            Attempt row present, but raw_text is empty.
                        </p>
                    @endif
                </details>
            @endforeach
        </div>
    @endif
</section>
