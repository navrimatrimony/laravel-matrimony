<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;

/**
 * @phpstan-type FieldResolutionValidatorArray array{
 *     passed: bool,
 *     code: string,
 *     detail: string|null
 * }
 * @phpstan-type FieldResolutionMergeArray array{
 *     previous_final: string|null,
 *     previous_source: string,
 *     previous_confidence: float|null,
 *     previous_status: string,
 *     previous_winning_engine: string|null,
 *     previous_reason: string,
 *     previous_validator: FieldResolutionValidatorArray,
 *     sarvam_confidence: float|null,
 *     sarvam_reason: string|null,
 *     merged_by: string
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
 *     validator: FieldResolutionValidatorArray,
 *     merge?: FieldResolutionMergeArray
 * }
 */
final class FieldResolutionFieldRecord
{
    /**
     * @param  array<string, string|null>  $candidates
     * @param  array<string, string|null>  $normalized
     * @param  FieldResolutionValidatorArray  $validator
     * @param  FieldResolutionMergeArray|null  $merge
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
        public readonly ?array $merge = null,
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
            merge: null,
        );
    }

    /**
     * @param  FieldResolutionFieldRecordArray  $data
     */
    public static function fromArray(array $data): self
    {
        $validator = is_array($data['validator'] ?? null) ? $data['validator'] : [];
        $merge = null;
        if (isset($data['merge']) && is_array($data['merge'])) {
            $previousValidator = is_array($data['merge']['previous_validator'] ?? null)
                ? $data['merge']['previous_validator']
                : [];
            $merge = [
                'previous_final' => array_key_exists('previous_final', $data['merge'])
                    ? (is_string($data['merge']['previous_final']) ? $data['merge']['previous_final'] : null)
                    : null,
                'previous_source' => (string) ($data['merge']['previous_source'] ?? ''),
                'previous_confidence' => is_numeric($data['merge']['previous_confidence'] ?? null)
                    ? (float) $data['merge']['previous_confidence']
                    : null,
                'previous_status' => (string) ($data['merge']['previous_status'] ?? ''),
                'previous_winning_engine' => isset($data['merge']['previous_winning_engine']) && is_string($data['merge']['previous_winning_engine'])
                    ? $data['merge']['previous_winning_engine']
                    : null,
                'previous_reason' => (string) ($data['merge']['previous_reason'] ?? ''),
                'previous_validator' => [
                    'passed' => (bool) ($previousValidator['passed'] ?? false),
                    'code' => (string) ($previousValidator['code'] ?? ''),
                    'detail' => isset($previousValidator['detail']) && is_string($previousValidator['detail'])
                        ? $previousValidator['detail']
                        : null,
                ],
                'sarvam_confidence' => is_numeric($data['merge']['sarvam_confidence'] ?? null)
                    ? (float) $data['merge']['sarvam_confidence']
                    : null,
                'sarvam_reason' => isset($data['merge']['sarvam_reason']) && is_string($data['merge']['sarvam_reason'])
                    ? $data['merge']['sarvam_reason']
                    : null,
                'merged_by' => (string) ($data['merge']['merged_by'] ?? ''),
            ];
        }

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
            merge: $merge,
        );
    }

    /**
     * @return FieldResolutionFieldRecordArray
     */
    public function toArray(): array
    {
        $array = [
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

        if ($this->merge !== null) {
            $array['merge'] = $this->merge;
        }

        return $array;
    }
}
