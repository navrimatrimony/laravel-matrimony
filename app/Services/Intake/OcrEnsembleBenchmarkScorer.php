<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatch;
use App\Models\BulkIntakeBatchItem;
use RuntimeException;

class OcrEnsembleBenchmarkScorer
{
    public function __construct(
        private readonly OcrEnsembleBenchmarkFieldExtractor $snapshotFieldReader,
        private readonly OcrEnsembleBenchmarkOcrTextFieldExtractor $ocrTextFieldExtractor,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>|null  $externalPredictions  keyed by intake_id
     * @return array<string, mixed>
     */
    public function scoreBatch(
        int $batchId,
        string $engineLabel,
        string $stage,
        ?array $externalPredictions = null
    ): array {
        $batch = BulkIntakeBatch::query()->find($batchId);
        if (! $batch instanceof BulkIntakeBatch) {
            throw new RuntimeException("Bulk batch {$batchId} not found.");
        }

        $items = BulkIntakeBatchItem::query()
            ->where('bulk_intake_batch_id', $batchId)
            ->where('input_type', BulkIntakeBatchItem::INPUT_FILE)
            ->with(['biodataIntake.ocrAttempts'])
            ->orderBy('item_sequence')
            ->get();

        if ($items->isEmpty()) {
            throw new RuntimeException("Batch {$batchId} has no file items.");
        }

        $itemReports = [];
        $ocrTimes = [];
        $failures = 0;
        $emptyOcr = 0;
        $correctionCounts = [];
        $fieldMatches = array_fill_keys(OcrEnsembleBenchmarkFieldExtractor::ALL_FIELDS, 0);
        $fieldScored = array_fill_keys(OcrEnsembleBenchmarkFieldExtractor::ALL_FIELDS, 0);
        $criticalMatches = 0;
        $criticalScored = 0;

        foreach ($items as $item) {
            $intake = $item->biodataIntake;
            if (! $intake instanceof BiodataIntake) {
                $failures++;

                continue;
            }

            $snapshot = is_array($intake->approval_snapshot_json) ? $intake->approval_snapshot_json : [];
            if ($snapshot === []) {
                throw new RuntimeException("Intake #{$intake->id} missing approval_snapshot_json (ground truth required).");
            }

            $truth = $this->snapshotFieldReader->extract($snapshot);
            $prediction = $this->resolvePrediction($intake, $externalPredictions);
            $externalRow = is_array($externalPredictions) ? ($externalPredictions[(int) $intake->id] ?? null) : null;
            $fieldRows = [];
            $itemCorrections = 0;

            foreach (OcrEnsembleBenchmarkFieldExtractor::ALL_FIELDS as $field) {
                $truthValue = $truth[$field] ?? null;
                $predictionValue = $prediction[$field] ?? null;
                $hasTruth = is_string($truthValue) && trim($truthValue) !== '';
                $match = $hasTruth
                    ? OcrEnsembleBenchmarkFieldMatcher::match($field, $truthValue, $predictionValue)
                    : null;

                if ($hasTruth) {
                    $fieldScored[$field]++;
                    if ($match === true) {
                        $fieldMatches[$field]++;
                    } else {
                        $itemCorrections++;
                    }
                    if (in_array($field, OcrEnsembleBenchmarkFieldExtractor::CRITICAL_FIELDS, true)) {
                        $criticalScored++;
                        if ($match === true) {
                            $criticalMatches++;
                        }
                    }
                }

                $fieldRows[$field] = [
                    'truth' => $truthValue,
                    'prediction' => $predictionValue,
                    'match' => $match,
                ];
            }

            $correctionCounts[] = $itemCorrections;
            $rawLen = $this->predictionRawOcrLength($intake, $externalRow);
            if ($rawLen < 20) {
                $emptyOcr++;
            }
            if ((string) $intake->parse_status === 'error') {
                $failures++;
            }

            $ocrMs = $this->predictionOcrTimeMs($intake, $externalRow);
            if ($ocrMs !== null) {
                $ocrTimes[] = $ocrMs;
            }

            $itemReports[] = [
                'batch_item_id' => (int) $item->id,
                'item_sequence' => (int) $item->item_sequence,
                'intake_id' => (int) $intake->id,
                'original_filename' => (string) ($item->original_filename ?? $intake->original_filename ?? ''),
                'parse_status' => (string) $intake->parse_status,
                'raw_ocr_len' => $rawLen,
                'ocr_time_ms' => $ocrMs,
                'manual_correction_count' => $itemCorrections,
                'fields' => $fieldRows,
            ];
        }

        $imageCount = count($itemReports);
        $fieldAccuracy = [];
        foreach (OcrEnsembleBenchmarkFieldExtractor::ALL_FIELDS as $field) {
            $fieldAccuracy[$field] = $fieldScored[$field] > 0
                ? round($fieldMatches[$field] / $fieldScored[$field], 4)
                : null;
        }

        return [
            'stage' => $stage,
            'engine' => $engineLabel,
            'batch_id' => $batchId,
            'prediction_source' => $externalPredictions === null ? 'ocr_text_field_extractor' : 'external_predictions_file',
            'scored_at' => now()->toIso8601String(),
            'items' => $itemReports,
            'summary' => [
                'image_count' => $imageCount,
                'field_accuracy' => $fieldAccuracy,
                'critical_accuracy' => $criticalScored > 0 ? round($criticalMatches / $criticalScored, 4) : null,
                'critical_matches' => $criticalMatches,
                'critical_scored' => $criticalScored,
                'ocr_time_ms' => $this->timingSummary($ocrTimes),
                'failure_rate' => $imageCount > 0 ? round($failures / $imageCount, 4) : null,
                'empty_ocr_rate' => $imageCount > 0 ? round($emptyOcr / $imageCount, 4) : null,
                'avg_manual_correction_count' => $imageCount > 0
                    ? round(array_sum($correctionCounts) / $imageCount, 2)
                    : null,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $externalPredictions
     * @return array<string, string|null>
     */
    private function resolvePrediction(BiodataIntake $intake, ?array $externalPredictions): array
    {
        if (is_array($externalPredictions) && isset($externalPredictions[(int) $intake->id])) {
            $row = $externalPredictions[(int) $intake->id];
            if (isset($row['fields']) && is_array($row['fields'])) {
                $fields = $row['fields'];
            } elseif (isset($row['raw_ocr_text']) && is_string($row['raw_ocr_text']) && trim($row['raw_ocr_text']) !== '') {
                return $this->ocrTextFieldExtractor->extractFromText($row['raw_ocr_text']);
            } else {
                $fields = $row;
            }
            $extracted = [];
            foreach (OcrEnsembleBenchmarkFieldExtractor::ALL_FIELDS as $field) {
                $value = $fields[$field] ?? null;
                $extracted[$field] = is_scalar($value) ? trim((string) $value) : null;
            }

            return $extracted;
        }

        return $this->ocrTextFieldExtractor->extractFromIntake($intake);
    }

    /**
     * @param  list<int>  $times
     * @return array{p50: int|null, p95: int|null, max: int|null, avg: int|null}
     */
    private function timingSummary(array $times): array
    {
        if ($times === []) {
            return ['p50' => null, 'p95' => null, 'max' => null, 'avg' => null];
        }
        sort($times);
        $count = count($times);
        $p50Index = (int) floor(($count - 1) * 0.5);
        $p95Index = (int) floor(($count - 1) * 0.95);

        return [
            'p50' => $times[$p50Index],
            'p95' => $times[$p95Index],
            'max' => $times[$count - 1],
            'avg' => (int) round(array_sum($times) / $count),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadExternalPredictions(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Predictions file not readable: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Predictions file must be JSON object or array.');
        }

        $rows = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;
        $indexed = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $intakeId = (int) ($row['intake_id'] ?? 0);
            if ($intakeId > 0) {
                $indexed[$intakeId] = $row;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>|null  $externalRow
     */
    private function predictionRawOcrLength(BiodataIntake $intake, ?array $externalRow): int
    {
        if (is_array($externalRow) && isset($externalRow['raw_ocr_text']) && is_string($externalRow['raw_ocr_text'])) {
            return mb_strlen(trim($externalRow['raw_ocr_text']), 'UTF-8');
        }

        return mb_strlen(trim((string) ($intake->raw_ocr_text ?? '')), 'UTF-8');
    }

    /**
     * @param  array<string, mixed>|null  $externalRow
     */
    private function predictionOcrTimeMs(BiodataIntake $intake, ?array $externalRow): ?int
    {
        if (is_array($externalRow) && is_numeric($externalRow['ocr_time_ms'] ?? null)) {
            return (int) $externalRow['ocr_time_ms'];
        }

        $attempt = $intake->ocrAttempts
            ?->first(static fn (BiodataIntakeOcrAttempt $row): bool => (bool) $row->is_primary)
            ?? $intake->ocrAttempts?->sortBy('id')->first();

        return is_numeric($attempt?->duration_ms) ? (int) $attempt->duration_ms : null;
    }
}
