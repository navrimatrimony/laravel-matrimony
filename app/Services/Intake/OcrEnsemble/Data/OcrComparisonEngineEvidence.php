<?php

namespace App\Services\Intake\OcrEnsemble\Data;

/**
 * Explicit per-engine comparison evidence slot.
 * Missing engines use present=false with attempt=null — never omit the slot.
 *
 * @phpstan-type OcrComparisonEngineEvidenceArray array{
 *     engine_key: string,
 *     comparison_column: string,
 *     present: bool,
 *     attempt: array<string, mixed>|null
 * }
 */
final class OcrComparisonEngineEvidence
{
    public function __construct(
        public readonly string $engineKey,
        public readonly string $comparisonColumn,
        public readonly bool $present,
        public readonly ?OcrComparisonAttemptSummary $attempt = null,
    ) {}

    public static function empty(string $engineKey, string $comparisonColumn): self
    {
        return new self(
            engineKey: $engineKey,
            comparisonColumn: $comparisonColumn,
            present: false,
            attempt: null,
        );
    }

    public static function fromAttempt(
        string $engineKey,
        string $comparisonColumn,
        OcrComparisonAttemptSummary $attempt,
    ): self {
        return new self(
            engineKey: $engineKey,
            comparisonColumn: $comparisonColumn,
            present: true,
            attempt: $attempt,
        );
    }

    /**
     * @param  OcrComparisonEngineEvidenceArray  $data
     */
    public static function fromArray(array $data): self
    {
        $attemptData = $data['attempt'] ?? null;
        $attempt = is_array($attemptData) ? OcrComparisonAttemptSummary::fromArray($attemptData) : null;
        $present = (bool) ($data['present'] ?? ($attempt !== null));

        return new self(
            engineKey: (string) ($data['engine_key'] ?? ''),
            comparisonColumn: (string) ($data['comparison_column'] ?? ''),
            present: $present && $attempt !== null,
            attempt: $present && $attempt !== null ? $attempt : null,
        );
    }

    /**
     * @return OcrComparisonEngineEvidenceArray
     */
    public function toArray(): array
    {
        return [
            'engine_key' => $this->engineKey,
            'comparison_column' => $this->comparisonColumn,
            'present' => $this->present,
            'attempt' => $this->attempt?->toArray(),
        ];
    }
}
