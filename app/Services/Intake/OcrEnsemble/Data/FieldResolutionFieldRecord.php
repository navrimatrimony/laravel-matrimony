<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;

/**
 * @phpstan-type FieldResolutionValidatorArray array{
 *     passed: bool,
 *     code: string,
 *     detail: string|null
 * }
 * @phpstan-type FieldResolutionFieldRecordArray array{
 *     final: string|null,
 *     status: string,
 *     source: string,
 *     winning_engine: string|null,
 *     confidence: float|null,
 *     reason: string,
 *     candidates: array<string, string|null>,
 *     normalized: array<string, string|null>,
 *     validator: FieldResolutionValidatorArray
 * }
 */
final class FieldResolutionFieldRecord
{
    /**
     * @param  array<string, string|null>  $candidates
     * @param  array<string, string|null>  $normalized
     * @param  FieldResolutionValidatorArray  $validator
     */
    public function __construct(
        public readonly ?string $final,
        public readonly string $status,
        public readonly string $source,
        public readonly ?string $winningEngine,
        public readonly ?float $confidence,
        public readonly string $reason,
        public readonly array $candidates,
        public readonly array $normalized,
        public readonly array $validator,
    ) {}

    public static function missingSkeleton(string $reason = 'not_implemented_step_3a'): self
    {
        return new self(
            final: null,
            status: OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING,
            source: OcrEnsemblePhase3Constants::FIELD_SOURCE_MISSING,
            winningEngine: null,
            confidence: null,
            reason: $reason,
            candidates: [],
            normalized: [],
            validator: [
                'passed' => false,
                'code' => 'not_implemented',
                'detail' => null,
            ],
        );
    }

    /**
     * @param  FieldResolutionFieldRecordArray  $data
     */
    public static function fromArray(array $data): self
    {
        $validator = is_array($data['validator'] ?? null) ? $data['validator'] : [];

        return new self(
            final: array_key_exists('final', $data) ? (is_string($data['final']) ? $data['final'] : null) : null,
            status: (string) ($data['status'] ?? OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING),
            source: (string) ($data['source'] ?? OcrEnsemblePhase3Constants::FIELD_SOURCE_MISSING),
            winningEngine: isset($data['winning_engine']) && is_string($data['winning_engine']) ? $data['winning_engine'] : null,
            confidence: is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : null,
            reason: (string) ($data['reason'] ?? ''),
            candidates: is_array($data['candidates'] ?? null) ? $data['candidates'] : [],
            normalized: is_array($data['normalized'] ?? null) ? $data['normalized'] : [],
            validator: [
                'passed' => (bool) ($validator['passed'] ?? false),
                'code' => (string) ($validator['code'] ?? ''),
                'detail' => isset($validator['detail']) && is_string($validator['detail']) ? $validator['detail'] : null,
            ],
        );
    }

    /**
     * @return FieldResolutionFieldRecordArray
     */
    public function toArray(): array
    {
        return [
            'final' => $this->final,
            'status' => $this->status,
            'source' => $this->source,
            'winning_engine' => $this->winningEngine,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'candidates' => $this->candidates,
            'normalized' => $this->normalized,
            'validator' => $this->validator,
        ];
    }
}
