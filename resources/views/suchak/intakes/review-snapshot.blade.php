@extends('layouts.app')

@section('content')
@php
    $sections = is_array($reviewEditor['sections'] ?? null) ? $reviewEditor['sections'] : [];
    $canSave = ! empty($reviewEditor['can_save']);
    $qualitySummary = is_array($qualitySignals['quality_summary'] ?? null) ? $qualitySignals['quality_summary'] : null;
    $qualityScore = is_numeric($qualitySummary['score'] ?? null) ? (float) $qualitySummary['score'] : null;
    $qualityScoreLabel = $qualityScore !== null ? ((int) round($qualityScore * 100)).'%' : null;
    $layoutScore = is_numeric($qualitySummary['layout_score'] ?? null) ? (float) $qualitySummary['layout_score'] : null;
    $layoutScoreLabel = $layoutScore !== null ? ((int) round($layoutScore * 100)).'%' : null;
    $failureCodes = is_array($qualitySignals['failure_codes'] ?? null) ? $qualitySignals['failure_codes'] : [];
    $lowConfidenceFields = is_array($qualitySignals['low_confidence_fields'] ?? null) ? $qualitySignals['low_confidence_fields'] : [];
    $hasQualitySignals = ! empty($qualitySignals['has_any']);
@endphp

<div class="mx-auto max-w-5xl px-4 py-8">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                <a href="{{ route('suchak.dashboard', ['dashboard_tab' => 'records']) }}" class="hover:underline">Suchak dashboard</a>
                <span aria-hidden="true">/</span>
                Intake #{{ $intake->id }}
            </p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Review biodata details</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Correct the parsed biodata details here. Saving this review stores a reviewed snapshot only; it does not approve or update the candidate profile.
            </p>
        </div>
        <a href="{{ route('suchak.intakes.create') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
            Upload / paste biodata
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-100">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-900 dark:border-red-800 dark:bg-red-950/30 dark:text-red-100">
            {{ session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/30 dark:text-red-100">
            <p class="font-semibold">Please fix the following:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-4 dark:border-gray-700">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Reviewed snapshot fields</h2>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Source: {{ $reviewEditor['source'] ?? 'empty' }} · {{ (int) ($reviewEditor['field_count'] ?? 0) }} field(s)
                    </p>
                </div>
                @if (! $canSave)
                    <span class="rounded-full border border-amber-300 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100">
                        Editing blocked after approval or lock
                    </span>
                @endif
            </div>

            @if (empty($reviewEditor['available']) || $sections === [])
                <p class="text-sm text-gray-600 dark:text-gray-300">No parsed or reviewed snapshot fields are available yet.</p>
            @else
                <form method="POST" action="{{ route('suchak.intakes.review-snapshot.update', $intake) }}" class="space-y-4" data-testid="suchak-review-snapshot-form">
                    @csrf
                    @method('PATCH')

                    @foreach ($sections as $section)
                        @php
                            $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
                        @endphp
                        @if ($fields === [])
                            @continue
                        @endif

                        <details class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50/70 dark:border-gray-700 dark:bg-gray-900/50" @if ($loop->first) open @endif>
                            <summary class="flex cursor-pointer select-none flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $section['label'] ?? $section['key'] ?? 'Section' }}</span>
                                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ count($fields) }} field(s)</span>
                            </summary>

                            <div class="grid grid-cols-1 gap-4 p-4 md:grid-cols-2">
                                @foreach ($fields as $field)
                                    @php
                                        $fieldName = (string) ($field['name'] ?? '');
                                        $fieldOldKey = (string) ($field['old_key'] ?? '');
                                        $fieldPath = (string) ($field['path'] ?? $fieldOldKey);
                                        $fieldValue = old($fieldOldKey, (string) ($field['value'] ?? ''));
                                        $fieldLabel = (string) ($field['label'] ?? $fieldPath);
                                        $isMultiline = ! empty($field['multiline']);
                                        $confidence = is_array($field['confidence'] ?? null) ? $field['confidence'] : null;
                                        $isLowConfidence = ! empty($confidence['is_low']);
                                        $confidenceScore = is_numeric($confidence['score'] ?? null) ? (float) $confidence['score'] : null;
                                        $confidenceScoreLabel = $confidenceScore !== null ? ' '.((int) round($confidenceScore * 100)).'%' : '';
                                        $fieldTestId = 'suchak-low-confidence-field-'.trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $fieldPath), '-');
                                        $inputClass = $isLowConfidence
                                            ? 'mt-1 w-full rounded-md border-amber-300 bg-amber-50 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 disabled:bg-gray-100 dark:border-amber-800 dark:bg-amber-950/20 dark:text-gray-100'
                                            : 'mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';
                                    @endphp

                                    <label class="block text-sm {{ $isLowConfidence ? 'suchak-low-confidence-field rounded-lg border border-amber-200 bg-amber-50/60 p-3 dark:border-amber-800 dark:bg-amber-950/20' : '' }}" @if ($isLowConfidence) data-testid="{{ $fieldTestId }}" @endif>
                                        <span class="flex flex-wrap items-center gap-2 font-semibold text-gray-700 dark:text-gray-200">
                                            <span>{{ $fieldLabel }}</span>
                                            @if ($isLowConfidence)
                                                <span class="rounded-full border border-amber-300 bg-white px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                                                    Low confidence{{ $confidenceScoreLabel }}
                                                </span>
                                            @endif
                                        </span>

                                        @if ($isMultiline)
                                            <textarea name="{{ $fieldName }}" rows="3" @disabled(! $canSave) class="{{ $inputClass }}">{{ $fieldValue }}</textarea>
                                        @else
                                            <input type="text" name="{{ $fieldName }}" value="{{ $fieldValue }}" @disabled(! $canSave) class="{{ $inputClass }}">
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    @endforeach

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="submit" @disabled(! $canSave) class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                            Save reviewed snapshot
                        </button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Profile data is not modified by this save.</span>
                    </div>
                </form>
            @endif
        </section>

        <aside class="space-y-4">
            @if ($hasQualitySignals)
                <section data-testid="suchak-quality-signals-panel" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm shadow-sm dark:border-amber-800 dark:bg-amber-950/20">
                    <h2 class="font-semibold text-amber-950 dark:text-amber-100">Quality signals</h2>
                    <p class="mt-1 text-xs text-amber-900/80 dark:text-amber-100/80">Use these signals only to decide what to check carefully.</p>

                    @if ($qualityScoreLabel || $layoutScoreLabel)
                        <dl class="mt-3 space-y-2 text-xs">
                            @if ($qualityScoreLabel)
                                <div>
                                    <dt class="font-semibold text-amber-950 dark:text-amber-100">Overall quality</dt>
                                    <dd class="mt-0.5 text-amber-900 dark:text-amber-100">{{ $qualityScoreLabel }}</dd>
                                </div>
                            @endif
                            @if ($layoutScoreLabel)
                                <div>
                                    <dt class="font-semibold text-amber-950 dark:text-amber-100">Layout score</dt>
                                    <dd class="mt-0.5 text-amber-900 dark:text-amber-100">{{ $layoutScoreLabel }}</dd>
                                </div>
                            @endif
                        </dl>
                    @endif

                    @if ($failureCodes !== [])
                        <div class="mt-3">
                            <p class="text-xs font-semibold text-amber-950 dark:text-amber-100">Failure codes</p>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                @foreach ($failureCodes as $code)
                                    <span class="rounded-full border border-red-200 bg-white px-2 py-0.5 text-xs font-semibold text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-100">{{ $code }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($lowConfidenceFields !== [])
                        <div class="mt-3">
                            <p class="text-xs font-semibold text-amber-950 dark:text-amber-100">Low confidence</p>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                @foreach ($lowConfidenceFields as $lowField)
                                    @php
                                        $lowScore = is_numeric($lowField['score'] ?? null) ? (float) $lowField['score'] : null;
                                        $lowScoreLabel = $lowScore !== null ? ' '.((int) round($lowScore * 100)).'%' : '';
                                    @endphp
                                    <span data-testid="suchak-quality-low-confidence-summary" class="rounded-full border border-amber-300 bg-white px-2 py-0.5 text-xs font-semibold text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100">
                                        {{ ($lowField['label'] ?? $lowField['key'] ?? 'Field').$lowScoreLabel }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>
            @endif

            <section class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Review rule</h2>
                <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">
                    This screen saves a human-reviewed snapshot for Suchak review. Approval, apply, OCR attempts, raw OCR text, and parsed JSON are not changed here.
                </p>
            </section>
        </aside>
    </div>
</div>
@endsection
