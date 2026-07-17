@php
    /** @var list<array<string, mixed>> $ocrEngineDebugMetrics */
    $ocrEngineDebugMetrics = is_array($ocrEngineDebugMetrics ?? null) ? $ocrEngineDebugMetrics : [];
    $fmt = static function ($v, string $fallback = '—'): string {
        if ($v === null || $v === '') {
            return $fallback;
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
        }

        return (string) $v;
    };
@endphp

<section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
         data-testid="ocr-engine-debug-metrics">
    <h3 class="text-sm font-semibold text-gray-900">Engine comparison metrics</h3>
    <p class="mt-1 text-xs text-gray-600">
        Side-by-side debug: confidence, time, fields found/missing, critical gaps, Judge used.
        Sourced from OCR attempts + Phase 3 comparison (read-only).
    </p>

    @if ($ocrEngineDebugMetrics === [])
        <p class="mt-3 text-sm text-gray-600" data-testid="ocr-engine-debug-metrics-empty">
            No engine metrics available yet.
        </p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-left text-sm"
                   data-testid="ocr-engine-debug-metrics-table">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Engine</th>
                        <th class="px-3 py-2">Confidence</th>
                        <th class="px-3 py-2">Time (ms)</th>
                        <th class="px-3 py-2">Fields found</th>
                        <th class="px-3 py-2">Fields missing</th>
                        <th class="px-3 py-2">Critical errors</th>
                        <th class="px-3 py-2">Critical gaps</th>
                        <th class="px-3 py-2">Judge used?</th>
                        <th class="px-3 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white text-gray-900">
                    @foreach ($ocrEngineDebugMetrics as $index => $row)
                        @php
                            $criticalGaps = is_array($row['critical_missing_fields'] ?? null)
                                ? $row['critical_missing_fields']
                                : [];
                            $criticalGapsLabel = $criticalGaps === []
                                ? '—'
                                : implode(', ', $criticalGaps);
                        @endphp
                        <tr data-testid="ocr-engine-debug-row-{{ $index }}"
                            data-engine="{{ $row['engine'] ?? '' }}">
                            <td class="px-3 py-2 font-medium">
                                {{ $row['engine'] ?? '—' }}
                                @if (! empty($row['is_primary']))
                                    <span class="ml-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold text-indigo-800">primary</span>
                                @endif
                            </td>
                            <td class="px-3 py-2" data-testid="ocr-engine-debug-confidence-{{ $index }}">{{ $fmt($row['confidence'] ?? null) }}</td>
                            <td class="px-3 py-2" data-testid="ocr-engine-debug-time-{{ $index }}">{{ $fmt($row['duration_ms'] ?? null) }}</td>
                            <td class="px-3 py-2" data-testid="ocr-engine-debug-found-{{ $index }}">{{ $fmt($row['fields_found'] ?? null) }}</td>
                            <td class="px-3 py-2" data-testid="ocr-engine-debug-missing-{{ $index }}">{{ $fmt($row['fields_missing'] ?? null) }}</td>
                            <td class="px-3 py-2" data-testid="ocr-engine-debug-critical-{{ $index }}">{{ $fmt($row['critical_errors'] ?? null) }}</td>
                            <td class="px-3 py-2 text-xs text-gray-700" data-testid="ocr-engine-debug-critical-gaps-{{ $index }}">{{ $criticalGapsLabel }}</td>
                            <td class="px-3 py-2" data-testid="ocr-engine-debug-judge-{{ $index }}">{{ $fmt($row['judge_used'] ?? null) }}</td>
                            <td class="px-3 py-2 text-xs text-gray-600">{{ $fmt($row['status'] ?? null) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
