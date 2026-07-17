<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAttemptSummary;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonFieldRow;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;
use App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult;

/**
 * Read-only Judge participation summary for Correct Candidate (§20.6).
 */
final class OcrEnsembleJudgeParticipationBuilder
{
    /**
     * @param  list<OcrComparisonAttemptSummary>  $attemptSummaries
     * @return array{
     *     participated: bool,
     *     attempt_count: int,
     *     attempt_engines: list<string>,
     *     judged_fields: list<array{field_key: string, field_label: string, final: string|null, reason: string|null, source: string|null}>,
     *     summary: string
     * }
     */
    public function build(
        array $attemptSummaries,
        Phase5ComparisonResult $comparisonResult,
    ): array {
        $attemptEngines = [];
        foreach ($attemptSummaries as $summary) {
            if (! $summary instanceof OcrComparisonAttemptSummary) {
                continue;
            }
            if ($this->isSarvamEngine($summary->engine)) {
                $attemptEngines[] = $summary->engine;
            }
        }
        $attemptEngines = array_values(array_unique($attemptEngines));

        $judgedFields = [];
        $table = $comparisonResult->table;
        if ($table instanceof OcrComparisonTable) {
            foreach ($table->rows as $row) {
                if (! $row instanceof OcrComparisonFieldRow) {
                    continue;
                }
                $source = is_string($row->source) ? strtolower($row->source) : '';
                $hasSarvamValue = is_string($row->sarvamValue) && trim($row->sarvamValue) !== '';
                $isJudgeSource = str_contains($source, 'sarvam');
                if (! $hasSarvamValue && ! $isJudgeSource) {
                    continue;
                }
                $judgedFields[] = [
                    'field_key' => $row->fieldKey,
                    'field_label' => $row->fieldLabel ?: OcrEnsemblePhase5Constants::fieldLabel($row->fieldKey),
                    'final' => $row->finalValue,
                    'reason' => $row->reason,
                    'source' => $row->source,
                ];
            }
        }

        $participated = $attemptEngines !== [] || $judgedFields !== [];
        $summary = $participated
            ? sprintf(
                'Judge participated (%d Sarvam attempt(s); %d field(s) with Sarvam evidence).',
                count($attemptEngines),
                count($judgedFields),
            )
            : 'Judge did not participate for this intake (no Sarvam attempt / no sarvam_judge field wins).';

        return [
            'participated' => $participated,
            'attempt_count' => count($attemptEngines),
            'attempt_engines' => $attemptEngines,
            'judged_fields' => $judgedFields,
            'summary' => $summary,
        ];
    }

    private function isSarvamEngine(string $engine): bool
    {
        return $engine === OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM
            || str_contains(strtolower($engine), 'sarvam');
    }
}
