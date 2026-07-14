@extends('layouts.admin')

@section('content')
@php
    /** @var \App\Models\BiodataIntake $intake */
    /** @var \App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult $comparisonResult */
    /** @var array{outcome: string, reason: string, table: array<string, mixed>|null} $comparisonPayload */
@endphp

<div class="max-w-5xl mx-auto space-y-4" data-testid="ocr-comparison-placeholder">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">OCR comparison (placeholder)</h1>
            <p class="text-sm text-gray-600">Intake #{{ $intake->id }} — Phase 5 service result (UI layout comes later).</p>
        </div>
        <a href="{{ route('admin.biodata-intakes.show', $intake) }}" class="text-sm text-indigo-700 hover:underline">
            Back to intake
        </a>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4 space-y-2"
         data-testid="ocr-comparison-outcome"
         data-outcome="{{ $comparisonResult->outcome }}"
         data-reason="{{ $comparisonResult->reason }}">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Outcome</div>
        <div class="text-sm text-gray-900" data-testid="ocr-comparison-outcome-value">{{ $comparisonResult->outcome }}</div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Reason</div>
        <div class="text-sm text-gray-900" data-testid="ocr-comparison-reason-value">{{ $comparisonResult->reason }}</div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-4">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Phase5ComparisonResult payload (temporary)</div>
        <pre class="text-xs overflow-auto whitespace-pre-wrap break-all text-gray-800"
             data-testid="ocr-comparison-payload">{{ json_encode($comparisonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</div>
@endsection
