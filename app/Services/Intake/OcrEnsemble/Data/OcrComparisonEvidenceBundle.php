<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;

/**
 * Read-only evidence bundle for Phase 5 comparison.
 *
 * Always includes explicit tesseract / second_ocr / sarvam engine slots
 * (empty when that engine has no attempt). Does not compute comparison rows.
 *
 * @phpstan-type OcrComparisonEvidenceBundleArray array{
 *     intake_id: int,
 *     field_resolution_json: array<string, mixed>|null,
 *     attempt_summaries: list<array<string, mixed>>,
 *     engines_present: list<string>,
 *     primary_attempt: array<string, mixed>|null,
 *     engines: array{
 *         tesseract: array<string, mixed>,
 *         second_ocr: array<string, mixed>,
 *         sarvam: array<string, mixed>
 *     }
 * }
 */
final class OcrComparisonEvidenceBundle
{
    /**
     * @param  array<string, mixed>|null  $fieldResolutionJson
     * @param  list<OcrComparisonAttemptSummary>  $attemptSummaries
     * @param  list<string>  $enginesPresent
     */
    public function __construct(
        public readonly int $intakeId,
        public readonly ?array $fieldResolutionJson,
        public readonly array $attemptSummaries,
        public readonly array $enginesPresent,
        public readonly OcrComparisonEngineEvidence $tesseract,
        public readonly OcrComparisonEngineEvidence $secondOcr,
        public readonly OcrComparisonEngineEvidence $sarvam,
        public readonly ?OcrComparisonAttemptSummary $primaryAttempt = null,
    ) {}

    public static function empty(int $intakeId): self
    {
        return new self(
            intakeId: $intakeId,
            fieldResolutionJson: null,
            attemptSummaries: [],
            enginesPresent: [],
            tesseract: OcrComparisonEngineEvidence::empty(
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
                OcrEnsemblePhase5Constants::COLUMN_TESSERACT,
            ),
            secondOcr: OcrComparisonEngineEvidence::empty(
                OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
                OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR,
            ),
            sarvam: OcrComparisonEngineEvidence::empty(
                OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
                OcrEnsemblePhase5Constants::COLUMN_SARVAM,
            ),
            primaryAttempt: null,
        );
    }

    /**
     * @param  OcrComparisonEvidenceBundleArray  $data
     */
    public static function fromArray(array $data): self
    {
        $summaries = [];
        foreach (is_array($data['attempt_summaries'] ?? null) ? $data['attempt_summaries'] : [] as $row) {
            if (is_array($row)) {
                $summaries[] = OcrComparisonAttemptSummary::fromArray($row);
            }
        }

        $engines = is_array($data['engines'] ?? null) ? $data['engines'] : [];
        $tesseract = is_array($engines['tesseract'] ?? null)
            ? OcrComparisonEngineEvidence::fromArray($engines['tesseract'])
            : OcrComparisonEngineEvidence::empty(
                OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
                OcrEnsemblePhase5Constants::COLUMN_TESSERACT,
            );
        $secondOcr = is_array($engines['second_ocr'] ?? null)
            ? OcrComparisonEngineEvidence::fromArray($engines['second_ocr'])
            : OcrComparisonEngineEvidence::empty(
                OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
                OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR,
            );
        $sarvam = is_array($engines['sarvam'] ?? null)
            ? OcrComparisonEngineEvidence::fromArray($engines['sarvam'])
            : OcrComparisonEngineEvidence::empty(
                OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
                OcrEnsemblePhase5Constants::COLUMN_SARVAM,
            );

        $primary = is_array($data['primary_attempt'] ?? null)
            ? OcrComparisonAttemptSummary::fromArray($data['primary_attempt'])
            : null;

        return new self(
            intakeId: (int) ($data['intake_id'] ?? 0),
            fieldResolutionJson: is_array($data['field_resolution_json'] ?? null)
                ? $data['field_resolution_json']
                : null,
            attemptSummaries: $summaries,
            enginesPresent: is_array($data['engines_present'] ?? null)
                ? array_values($data['engines_present'])
                : [],
            tesseract: $tesseract,
            secondOcr: $secondOcr,
            sarvam: $sarvam,
            primaryAttempt: $primary,
        );
    }

    /**
     * @return OcrComparisonEvidenceBundleArray
     */
    public function toArray(): array
    {
        $summaries = [];
        foreach ($this->attemptSummaries as $summary) {
            $summaries[] = $summary->toArray();
        }

        return [
            'intake_id' => $this->intakeId,
            'field_resolution_json' => $this->fieldResolutionJson,
            'attempt_summaries' => $summaries,
            'engines_present' => array_values($this->enginesPresent),
            'primary_attempt' => $this->primaryAttempt?->toArray(),
            'engines' => [
                'tesseract' => $this->tesseract->toArray(),
                'second_ocr' => $this->secondOcr->toArray(),
                'sarvam' => $this->sarvam->toArray(),
            ],
        ];
    }

    public function hasFieldResolution(): bool
    {
        return is_array($this->fieldResolutionJson) && $this->fieldResolutionJson !== [];
    }

    public function attemptCount(): int
    {
        return count($this->attemptSummaries);
    }

    /**
     * Fixed comparison-engine slots in blueprint column order.
     *
     * @return list<OcrComparisonEngineEvidence>
     */
    public function comparisonEngines(): array
    {
        return [$this->tesseract, $this->secondOcr, $this->sarvam];
    }
}
