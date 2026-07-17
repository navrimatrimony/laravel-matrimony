@php
    /** @var \App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable $table */
    /** @var string $emptyDisplay */
    /** @var bool $isEmptyOutcome */

    $statusBadgeClasses = [
        'resolved' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'missing' => 'border-gray-300 bg-gray-50 text-gray-700',
        'conflict' => 'border-amber-300 bg-amber-50 text-amber-900',
    ];

    $sourceBadgeClasses = [
        'validator' => 'border-sky-200 bg-sky-50 text-sky-800',
        'sarvam_judge' => 'border-violet-200 bg-violet-50 text-violet-800',
        'vote' => 'border-indigo-200 bg-indigo-50 text-indigo-800',
        'single_engine' => 'border-teal-200 bg-teal-50 text-teal-800',
        'manual_override' => 'border-rose-200 bg-rose-50 text-rose-800',
        'missing' => 'border-gray-300 bg-gray-50 text-gray-600',
    ];

    $defaultBadgeClass = 'border-gray-300 bg-white text-gray-700';
@endphp

@if ($isEmptyOutcome)
    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"
         data-testid="ocr-comparison-empty-notice">
        Field resolution is not available for this intake. Showing canonical empty rows for review.
        @if ($table->audit->emptyState)
            <span class="font-medium">({{ $table->audit->emptyState }})</span>
        @endif
    </div>
@endif

<div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden"
     data-testid="ocr-comparison-table-wrap">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left"
               data-testid="ocr-comparison-table"
               data-row-count="{{ count($table->rows) }}">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th scope="col" class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">Field</th>
                    <th scope="col" class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">Final</th>
                    <th scope="col" class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">Tesseract</th>
                    <th scope="col" class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">Second OCR</th>
                    <th scope="col" class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">Sarvam</th>
                    <th scope="col" class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">Winner</th>
                    <th scope="col" class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">Reason</th>
                    <th scope="col" class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">Status</th>
                    <th scope="col" class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">Source</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($table->rows as $index => $row)
                    @php
                        $status = is_string($row->status) && $row->status !== '' ? $row->status : null;
                        $source = is_string($row->source) && $row->source !== '' ? $row->source : null;
                        $winner = is_string($row->winningEngine ?? null) && trim((string) $row->winningEngine) !== ''
                            ? $row->winningEngine
                            : null;
                        $final = is_string($row->finalValue) && trim($row->finalValue) !== '' ? $row->finalValue : null;
                        $isResolvedFinal = $status === 'resolved' && $final !== null;
                        $statusClass = $statusBadgeClasses[$status] ?? $defaultBadgeClass;
                        $sourceClass = $sourceBadgeClasses[$source] ?? $defaultBadgeClass;
                    @endphp
                    <tr data-testid="ocr-comparison-row-{{ $row->fieldKey }}" data-field-key="{{ $row->fieldKey }}" data-row-index="{{ $index }}" data-status="{{ $status ?? '' }}" data-source="{{ $source ?? '' }}" data-winning-engine="{{ $winner ?? '' }}">
                        <td class="px-3 py-2 align-top whitespace-nowrap">
                            <div class="font-medium text-gray-900" data-testid="ocr-comparison-field-label">{{ $row->fieldLabel ?: $row->fieldKey }}</div>
                            <div class="text-[11px] text-gray-500">{{ $row->fieldKey }}</div>
                        </td>
                        <td class="px-3 py-2 align-top {{ $isResolvedFinal ? 'bg-emerald-50' : '' }}"
                            data-testid="ocr-comparison-final">
                            @if ($isResolvedFinal)
                                <span class="font-semibold text-emerald-900" data-testid="ocr-comparison-final-highlight">{{ $final }}</span>
                            @elseif ($final !== null)
                                <span class="text-gray-900">{{ $final }}</span>
                            @else
                                <span class="text-gray-400" data-empty="1">{{ $emptyDisplay }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top text-gray-800" data-testid="ocr-comparison-tesseract">
                            @if (is_string($row->tesseractValue) && trim($row->tesseractValue) !== '')
                                {{ $row->tesseractValue }}
                            @else
                                <span class="text-gray-400" data-empty="1">{{ $emptyDisplay }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top text-gray-800" data-testid="ocr-comparison-second-ocr">
                            @if (is_string($row->secondOcrValue) && trim($row->secondOcrValue) !== '')
                                {{ $row->secondOcrValue }}
                            @else
                                <span class="text-gray-400" data-empty="1">{{ $emptyDisplay }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top text-gray-800" data-testid="ocr-comparison-sarvam">
                            @if (is_string($row->sarvamValue) && trim($row->sarvamValue) !== '')
                                {{ $row->sarvamValue }}
                            @else
                                <span class="text-gray-400" data-empty="1">{{ $emptyDisplay }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top text-gray-800" data-testid="ocr-comparison-winner">
                            @if ($winner !== null)
                                <span class="font-medium text-gray-900">{{ $winner }}</span>
                            @else
                                <span class="text-gray-400" data-empty="1">{{ $emptyDisplay }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top text-gray-700" data-testid="ocr-comparison-reason">
                            @if (is_string($row->reason) && trim($row->reason) !== '')
                                {{ $row->reason }}
                            @else
                                <span class="text-gray-400" data-empty="1">{{ $emptyDisplay }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top" data-testid="ocr-comparison-status-cell">
                            @if ($status)
                                <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $statusClass }}"
                                      data-testid="ocr-comparison-status-badge"
                                      data-status-badge="{{ $status }}">{{ $status }}</span>
                            @else
                                <span class="text-gray-400" data-empty="1">{{ $emptyDisplay }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top" data-testid="ocr-comparison-source-cell">
                            @if ($source)
                                <span class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $sourceClass }}"
                                      data-testid="ocr-comparison-source-badge"
                                      data-source-badge="{{ $source }}">{{ $source }}</span>
                            @else
                                <span class="text-gray-400" data-empty="1">{{ $emptyDisplay }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
