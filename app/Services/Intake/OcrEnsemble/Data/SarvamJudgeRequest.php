<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase4Constants;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleSarvamJudgeRequestSupport;

/**
 * Immutable Sarvam judge request payload (no timestamps, no network).
 *
 * Contains ONLY triggered fields.
 *
 * @phpstan-type SarvamJudgeRequestArray array{
 *     schema_version: string,
 *     pipeline_version: string,
 *     intake_id: int,
 *     trigger_reasons: array<string, string>,
 *     fields: list<array<string, mixed>>
 * }
 */
final class SarvamJudgeRequest
{
    /**
     * @param  list<SarvamJudgeRequestField>  $fields
     * @param  array<string, string>  $triggerReasons
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $pipelineVersion,
        public readonly int $intakeId,
        public readonly array $triggerReasons,
        public readonly array $fields,
    ) {}

    public static function empty(int $intakeId = 0): self
    {
        return new self(
            schemaVersion: OcrEnsemblePhase4Constants::SCHEMA_VERSION,
            pipelineVersion: OcrEnsemblePhase4Constants::PIPELINE_VERSION,
            intakeId: $intakeId,
            triggerReasons: [],
            fields: [],
        );
    }

    /**
     * @param  SarvamJudgeRequestArray  $data
     */
    public static function fromArray(array $data): self
    {
        $fieldsData = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $fields = [];
        foreach ($fieldsData as $fieldData) {
            if (! is_array($fieldData)) {
                continue;
            }
            $fields[] = SarvamJudgeRequestField::fromArray($fieldData);
        }

        $reasons = is_array($data['trigger_reasons'] ?? null) ? $data['trigger_reasons'] : [];

        return new self(
            schemaVersion: (string) ($data['schema_version'] ?? OcrEnsemblePhase4Constants::SCHEMA_VERSION),
            pipelineVersion: (string) ($data['pipeline_version'] ?? OcrEnsemblePhase4Constants::PIPELINE_VERSION),
            intakeId: (int) ($data['intake_id'] ?? 0),
            triggerReasons: OcrEnsembleSarvamJudgeRequestSupport::orderedTriggerReasons($reasons),
            fields: $fields,
        );
    }

    /**
     * @return SarvamJudgeRequestArray
     */
    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $field) {
            $fields[] = $field->toArray();
        }

        return [
            'schema_version' => $this->schemaVersion,
            'pipeline_version' => $this->pipelineVersion,
            'intake_id' => $this->intakeId,
            'trigger_reasons' => OcrEnsembleSarvamJudgeRequestSupport::orderedTriggerReasons($this->triggerReasons),
            'fields' => $fields,
        ];
    }

    /**
     * Deterministic JSON serialization (stable key order via toArray contract).
     */
    public function toCanonicalJson(): string
    {
        return OcrEnsembleSarvamJudgeRequestSupport::encodeCanonicalJson($this->toArray());
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        $names = [];
        foreach ($this->fields as $field) {
            $names[] = $field->fieldName;
        }

        return $names;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }
}
