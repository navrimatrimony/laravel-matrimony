<?php

namespace App\Services\Intake\OcrEnsemble\Data;

/**
 * Immutable Sarvam judge merge outcome (no DB writes).
 *
 * @phpstan-type SarvamJudgeMergeResultArray array{
 *     changed: bool,
 *     updated_fields: list<string>,
 *     skipped_fields: array<string, string>,
 *     updated_count: int,
 *     envelope: array<string, mixed>
 * }
 */
final class SarvamJudgeMergeResult
{
    /**
     * @param  list<string>  $updatedFields
     * @param  array<string, string>  $skippedFields  field_key => skip_reason
     */
    public function __construct(
        public readonly FieldResolutionEnvelope $envelope,
        public readonly bool $changed,
        public readonly array $updatedFields,
        public readonly array $skippedFields,
    ) {}

    public function updatedCount(): int
    {
        return count($this->updatedFields);
    }

    public static function noop(FieldResolutionEnvelope $envelope, string $reason = 'no_changes'): self
    {
        return new self(
            envelope: $envelope,
            changed: false,
            updatedFields: [],
            skippedFields: $reason === 'no_changes' ? [] : ['_merge' => $reason],
        );
    }

    /**
     * @return SarvamJudgeMergeResultArray
     */
    public function toArray(): array
    {
        return [
            'changed' => $this->changed,
            'updated_fields' => array_values($this->updatedFields),
            'skipped_fields' => $this->skippedFields,
            'updated_count' => $this->updatedCount(),
            'envelope' => $this->envelope->toArray(),
        ];
    }
}
