<?php

namespace App\Services\Intake\OcrEnsemble\Data;

/**
 * Read-only evidence bundle for Phase 5 comparison (skeleton — filled in later steps).
 *
 * @phpstan-type OcrComparisonEvidenceBundleArray array{
 *     intake_id: int,
 *     field_resolution_json: array<string, mixed>|null,
 *     attempt_summaries: list<array<string, mixed>>,
 *     engines_present: list<string>
 * }
 */
final class OcrComparisonEvidenceBundle
{
    /**
     * @param  array<string, mixed>|null  $fieldResolutionJson
     * @param  list<array<string, mixed>>  $attemptSummaries
     * @param  list<string>  $enginesPresent
     */
    public function __construct(
        public readonly int $intakeId,
        public readonly ?array $fieldResolutionJson,
        public readonly array $attemptSummaries,
        public readonly array $enginesPresent,
    ) {}

    public static function empty(int $intakeId): self
    {
        return new self(
            intakeId: $intakeId,
            fieldResolutionJson: null,
            attemptSummaries: [],
            enginesPresent: [],
        );
    }

    /**
     * @param  OcrComparisonEvidenceBundleArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            intakeId: (int) ($data['intake_id'] ?? 0),
            fieldResolutionJson: is_array($data['field_resolution_json'] ?? null)
                ? $data['field_resolution_json']
                : null,
            attemptSummaries: is_array($data['attempt_summaries'] ?? null)
                ? array_values($data['attempt_summaries'])
                : [],
            enginesPresent: is_array($data['engines_present'] ?? null)
                ? array_values($data['engines_present'])
                : [],
        );
    }

    /**
     * @return OcrComparisonEvidenceBundleArray
     */
    public function toArray(): array
    {
        return [
            'intake_id' => $this->intakeId,
            'field_resolution_json' => $this->fieldResolutionJson,
            'attempt_summaries' => array_values($this->attemptSummaries),
            'engines_present' => array_values($this->enginesPresent),
        ];
    }

    public function hasFieldResolution(): bool
    {
        return is_array($this->fieldResolutionJson) && $this->fieldResolutionJson !== [];
    }
}
