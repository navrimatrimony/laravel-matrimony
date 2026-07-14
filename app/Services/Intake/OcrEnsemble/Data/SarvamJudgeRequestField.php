<?php

namespace App\Services\Intake\OcrEnsemble\Data;

/**
 * One triggered field payload for the Sarvam judge request (immutable).
 *
 * @phpstan-type ValidatorMeta array{passed: bool, code: string, detail: string|null}
 * @phpstan-type EngineMeta array{
 *     winning_engine: string|null,
 *     candidate_engines: list<string>,
 *     engines_present: list<string>
 * }
 * @phpstan-type SarvamJudgeRequestFieldArray array{
 *     field_name: string,
 *     trigger_reason: string,
 *     resolved_value: string|null,
 *     normalized_value: string|null,
 *     status: string,
 *     source: string,
 *     winning_engine: string|null,
 *     confidence: float|null,
 *     field_reason: string,
 *     candidates: array<string, string|null>,
 *     normalized: array<string, string|null>,
 *     validator: ValidatorMeta,
 *     ocr_snippets: list<string>,
 *     engine_metadata: EngineMeta
 * }
 */
final class SarvamJudgeRequestField
{
    /**
     * @param  array<string, string|null>  $candidates
     * @param  array<string, string|null>  $normalized
     * @param  ValidatorMeta  $validator
     * @param  list<string>  $ocrSnippets
     * @param  EngineMeta  $engineMetadata
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly string $triggerReason,
        public readonly ?string $resolvedValue,
        public readonly ?string $normalizedValue,
        public readonly string $status,
        public readonly string $source,
        public readonly ?string $winningEngine,
        public readonly ?float $confidence,
        public readonly string $fieldReason,
        public readonly array $candidates,
        public readonly array $normalized,
        public readonly array $validator,
        public readonly array $ocrSnippets,
        public readonly array $engineMetadata,
    ) {}

    /**
     * @param  SarvamJudgeRequestFieldArray  $data
     */
    public static function fromArray(array $data): self
    {
        $validator = is_array($data['validator'] ?? null) ? $data['validator'] : [];
        $engineMetadata = is_array($data['engine_metadata'] ?? null) ? $data['engine_metadata'] : [];
        $candidates = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];
        $normalized = is_array($data['normalized'] ?? null) ? $data['normalized'] : [];
        ksort($candidates);
        ksort($normalized);

        return new self(
            fieldName: (string) ($data['field_name'] ?? ''),
            triggerReason: (string) ($data['trigger_reason'] ?? ''),
            resolvedValue: isset($data['resolved_value']) && is_string($data['resolved_value']) ? $data['resolved_value'] : null,
            normalizedValue: isset($data['normalized_value']) && is_string($data['normalized_value']) ? $data['normalized_value'] : null,
            status: (string) ($data['status'] ?? ''),
            source: (string) ($data['source'] ?? ''),
            winningEngine: isset($data['winning_engine']) && is_string($data['winning_engine']) ? $data['winning_engine'] : null,
            confidence: is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : null,
            fieldReason: (string) ($data['field_reason'] ?? ''),
            candidates: $candidates,
            normalized: $normalized,
            validator: [
                'passed' => (bool) ($validator['passed'] ?? false),
                'code' => (string) ($validator['code'] ?? ''),
                'detail' => isset($validator['detail']) && is_string($validator['detail']) ? $validator['detail'] : null,
            ],
            ocrSnippets: is_array($data['ocr_snippets'] ?? null) ? array_values($data['ocr_snippets']) : [],
            engineMetadata: [
                'winning_engine' => isset($engineMetadata['winning_engine']) && is_string($engineMetadata['winning_engine'])
                    ? $engineMetadata['winning_engine']
                    : null,
                'candidate_engines' => is_array($engineMetadata['candidate_engines'] ?? null)
                    ? array_values($engineMetadata['candidate_engines'])
                    : [],
                'engines_present' => is_array($engineMetadata['engines_present'] ?? null)
                    ? array_values($engineMetadata['engines_present'])
                    : [],
            ],
        );
    }

    /**
     * @return SarvamJudgeRequestFieldArray
     */
    public function toArray(): array
    {
        $candidates = $this->candidates;
        $normalized = $this->normalized;
        ksort($candidates);
        ksort($normalized);

        return [
            'field_name' => $this->fieldName,
            'trigger_reason' => $this->triggerReason,
            'resolved_value' => $this->resolvedValue,
            'normalized_value' => $this->normalizedValue,
            'status' => $this->status,
            'source' => $this->source,
            'winning_engine' => $this->winningEngine,
            'confidence' => $this->confidence,
            'field_reason' => $this->fieldReason,
            'candidates' => $candidates,
            'normalized' => $normalized,
            'validator' => [
                'passed' => (bool) ($this->validator['passed'] ?? false),
                'code' => (string) ($this->validator['code'] ?? ''),
                'detail' => isset($this->validator['detail']) && is_string($this->validator['detail'])
                    ? $this->validator['detail']
                    : null,
            ],
            'ocr_snippets' => array_values($this->ocrSnippets),
            'engine_metadata' => [
                'winning_engine' => $this->engineMetadata['winning_engine'] ?? null,
                'candidate_engines' => array_values($this->engineMetadata['candidate_engines'] ?? []),
                'engines_present' => array_values($this->engineMetadata['engines_present'] ?? []),
            ],
        ];
    }
}
