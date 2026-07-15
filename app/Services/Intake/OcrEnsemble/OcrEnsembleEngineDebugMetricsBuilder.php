<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAttemptSummary;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonFieldRow;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;
use App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult;

/**
 * Read-only Admin debug metrics: Engine / Confidence / Time / Fields / Judge.
 * Uses existing attempt summaries + Phase 5 comparison rows only (no new DB writes).
 */
final class OcrEnsembleEngineDebugMetricsBuilder
{
    /**
     * @param  list<OcrComparisonAttemptSummary>  $attemptSummaries
     * @return list<array{
     *     engine: string,
     *     is_primary: bool,
     *     confidence: float|null,
     *     duration_ms: int|null,
     *     fields_found: int|null,
     *     fields_missing: int|null,
     *     critical_errors: int|null,
     *     judge_used: bool|null,
     *     status: string,
     *     attempt_id: int|null
     * }>
     */
    public function build(
        array $attemptSummaries,
        Phase5ComparisonResult $comparisonResult,
    ): array {
        $table = $comparisonResult->table;
        $judgeUsedGlobally = $this->judgeUsed($table, $attemptSummaries);

        $byEngine = [];
        foreach ($attemptSummaries as $summary) {
            if (! $summary instanceof OcrComparisonAttemptSummary) {
                continue;
            }
            $key = $summary->engine !== '' ? $summary->engine : 'unknown_engine';
            $fieldStats = $this->fieldStatsForEngine($table, $key);

            $byEngine[$key] = [
                'engine' => $key,
                'is_primary' => $summary->isPrimary,
                'confidence' => $summary->qualityScore,
                'duration_ms' => $summary->durationMs,
                'fields_found' => $fieldStats['found'],
                'fields_missing' => $fieldStats['missing'],
                'critical_errors' => $fieldStats['critical_errors'],
                'judge_used' => $judgeUsedGlobally,
                'status' => $summary->status,
                'attempt_id' => $summary->attemptId > 0 ? $summary->attemptId : null,
            ];
        }

        // Ensure comparison-table engines appear even if attempt row missing.
        if ($table instanceof OcrComparisonTable) {
            foreach ([
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT => 'tesseractValue',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR => 'secondOcrValue',
                OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM => 'sarvamValue',
            ] as $engineKey => $_) {
                if (isset($byEngine[$engineKey])) {
                    continue;
                }
                $fieldStats = $this->fieldStatsForEngine($table, $engineKey);
                if (($fieldStats['found'] ?? 0) < 1) {
                    continue;
                }
                $byEngine[$engineKey] = [
                    'engine' => $engineKey,
                    'is_primary' => false,
                    'confidence' => null,
                    'duration_ms' => null,
                    'fields_found' => $fieldStats['found'],
                    'fields_missing' => $fieldStats['missing'],
                    'critical_errors' => $fieldStats['critical_errors'],
                    'judge_used' => $judgeUsedGlobally,
                    'status' => 'no_attempt_row',
                    'attempt_id' => null,
                ];
            }
        }

        return array_values($byEngine);
    }

    /**
     * @param  list<OcrComparisonAttemptSummary>  $attemptSummaries
     */
    private function judgeUsed(?OcrComparisonTable $table, array $attemptSummaries): bool
    {
        foreach ($attemptSummaries as $summary) {
            if ($summary instanceof OcrComparisonAttemptSummary && $this->isSarvamEngine($summary->engine)) {
                return true;
            }
        }

        if (! $table instanceof OcrComparisonTable) {
            return false;
        }

        foreach ($table->rows as $row) {
            if (! $row instanceof OcrComparisonFieldRow) {
                continue;
            }
            if (is_string($row->sarvamValue) && trim($row->sarvamValue) !== '') {
                return true;
            }
            if (is_string($row->source) && str_contains(strtolower($row->source), 'sarvam')) {
                return true;
            }
        }

        return false;
    }

    private function isSarvamEngine(string $engine): bool
    {
        return $engine === OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM
            || str_contains(strtolower($engine), 'sarvam');
    }

    /**
     * @return array{found: int|null, missing: int|null, critical_errors: int|null}
     */
    private function fieldStatsForEngine(?OcrComparisonTable $table, string $engineKey): array
    {
        if (! $table instanceof OcrComparisonTable || $table->rows === []) {
            return ['found' => null, 'missing' => null, 'critical_errors' => null];
        }

        $found = 0;
        $missing = 0;
        $criticalErrors = 0;
        $total = 0;

        foreach ($table->rows as $row) {
            if (! $row instanceof OcrComparisonFieldRow) {
                continue;
            }
            $total++;
            $value = $this->engineValue($row, $engineKey);
            $hasValue = is_string($value) && trim($value) !== '';
            if ($hasValue) {
                $found++;
            } else {
                $missing++;
            }

            $isCritical = in_array($row->fieldKey, OcrEnsemblePhase3Constants::CRITICAL_FIELDS, true);
            $isConflict = $row->status === OcrEnsemblePhase3Constants::FIELD_STATUS_CONFLICT;
            if ($isCritical && (! $hasValue || $isConflict)) {
                $criticalErrors++;
            }
        }

        if ($total === 0) {
            return ['found' => null, 'missing' => null, 'critical_errors' => null];
        }

        return [
            'found' => $found,
            'missing' => $missing,
            'critical_errors' => $criticalErrors,
        ];
    }

    private function engineValue(OcrComparisonFieldRow $row, string $engineKey): ?string
    {
        return match ($engineKey) {
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            OcrEnsemblePhase5Constants::COLUMN_TESSERACT => $row->tesseractValue,
            OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
            OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR => $row->secondOcrValue,
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
            OcrEnsemblePhase5Constants::COLUMN_SARVAM => $row->sarvamValue,
            default => null,
        };
    }
}
