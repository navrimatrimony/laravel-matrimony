<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;

/**
 * @phpstan-type FieldResolutionMetaArray array{
 *     schema_version: string,
 *     pipeline_version: string,
 *     resolved_at: string,
 *     intake_id: int,
 *     attempt_count: int,
 *     engines_present: list<string>,
 *     vote_mode: string,
 *     assembly_version: string
 * }
 */
final class FieldResolutionMeta
{
    /**
     * @param  list<string>  $enginesPresent
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $pipelineVersion,
        public readonly string $resolvedAt,
        public readonly int $intakeId,
        public readonly int $attemptCount,
        public readonly array $enginesPresent,
        public readonly string $voteMode,
        public readonly string $assemblyVersion,
    ) {}

    public static function skeleton(int $intakeId): self
    {
        return new self(
            schemaVersion: OcrEnsemblePhase3Constants::SCHEMA_VERSION,
            pipelineVersion: OcrEnsemblePhase3Constants::PIPELINE_VERSION,
            resolvedAt: now()->toIso8601String(),
            intakeId: $intakeId,
            attemptCount: 0,
            enginesPresent: [],
            voteMode: OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH,
            assemblyVersion: OcrEnsemblePhase3Constants::ASSEMBLY_VERSION,
        );
    }

    /**
     * @param  FieldResolutionMetaArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            schemaVersion: (string) ($data['schema_version'] ?? OcrEnsemblePhase3Constants::SCHEMA_VERSION),
            pipelineVersion: (string) ($data['pipeline_version'] ?? OcrEnsemblePhase3Constants::PIPELINE_VERSION),
            resolvedAt: (string) ($data['resolved_at'] ?? ''),
            intakeId: (int) ($data['intake_id'] ?? 0),
            attemptCount: (int) ($data['attempt_count'] ?? 0),
            enginesPresent: is_array($data['engines_present'] ?? null) ? array_values($data['engines_present']) : [],
            voteMode: (string) ($data['vote_mode'] ?? OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH),
            assemblyVersion: (string) ($data['assembly_version'] ?? OcrEnsemblePhase3Constants::ASSEMBLY_VERSION),
        );
    }

    /**
     * @return FieldResolutionMetaArray
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'pipeline_version' => $this->pipelineVersion,
            'resolved_at' => $this->resolvedAt,
            'intake_id' => $this->intakeId,
            'attempt_count' => $this->attemptCount,
            'engines_present' => $this->enginesPresent,
            'vote_mode' => $this->voteMode,
            'assembly_version' => $this->assemblyVersion,
        ];
    }
}
