<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;

/**
 * Read-only audit / operator context for a Phase 5 comparison table.
 *
 * @phpstan-type OcrComparisonAuditMetaArray array{
 *     schema_version: string,
 *     pipeline_version: string,
 *     intake_id: int,
 *     surface: string,
 *     ensemble_ran: bool,
 *     has_field_resolution_json: bool,
 *     attempt_count: int,
 *     engines_present: list<string>,
 *     empty_state: string|null
 * }
 */
final class OcrComparisonAuditMeta
{
    /**
     * @param  list<string>  $enginesPresent
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $pipelineVersion,
        public readonly int $intakeId,
        public readonly string $surface,
        public readonly bool $ensembleRan,
        public readonly bool $hasFieldResolutionJson,
        public readonly int $attemptCount,
        public readonly array $enginesPresent,
        public readonly ?string $emptyState = null,
    ) {}

    public static function skeleton(int $intakeId, ?string $emptyState = null): self
    {
        return new self(
            schemaVersion: OcrEnsemblePhase5Constants::SCHEMA_VERSION,
            pipelineVersion: OcrEnsemblePhase5Constants::PIPELINE_VERSION,
            intakeId: $intakeId,
            surface: OcrEnsemblePhase5Constants::SURFACE_CORRECT_CANDIDATE,
            ensembleRan: false,
            hasFieldResolutionJson: false,
            attemptCount: 0,
            enginesPresent: [],
            emptyState: $emptyState,
        );
    }

    /**
     * @param  OcrComparisonAuditMetaArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            schemaVersion: (string) ($data['schema_version'] ?? OcrEnsemblePhase5Constants::SCHEMA_VERSION),
            pipelineVersion: (string) ($data['pipeline_version'] ?? OcrEnsemblePhase5Constants::PIPELINE_VERSION),
            intakeId: (int) ($data['intake_id'] ?? 0),
            surface: (string) ($data['surface'] ?? OcrEnsemblePhase5Constants::SURFACE_CORRECT_CANDIDATE),
            ensembleRan: (bool) ($data['ensemble_ran'] ?? false),
            hasFieldResolutionJson: (bool) ($data['has_field_resolution_json'] ?? false),
            attemptCount: (int) ($data['attempt_count'] ?? 0),
            enginesPresent: is_array($data['engines_present'] ?? null)
                ? array_values($data['engines_present'])
                : [],
            emptyState: isset($data['empty_state']) ? (string) $data['empty_state'] : null,
        );
    }

    /**
     * @return OcrComparisonAuditMetaArray
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'pipeline_version' => $this->pipelineVersion,
            'intake_id' => $this->intakeId,
            'surface' => $this->surface,
            'ensemble_ran' => $this->ensembleRan,
            'has_field_resolution_json' => $this->hasFieldResolutionJson,
            'attempt_count' => $this->attemptCount,
            'engines_present' => array_values($this->enginesPresent),
            'empty_state' => $this->emptyState,
        ];
    }
}
