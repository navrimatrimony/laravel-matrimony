<?php

namespace App\Services\Intake\OcrEnsemble\Data;

/**
 * Immutable read-only summary of one biodata_intake_ocr_attempts row for Phase 5.
 *
 * @phpstan-type OcrComparisonAttemptSummaryArray array{
 *     attempt_id: int,
 *     engine: string,
 *     source: string|null,
 *     status: string,
 *     is_primary: bool,
 *     raw_text: string|null,
 *     engine_meta_json: array<string, mixed>|null,
 *     quality_score: float|null,
 *     duration_ms: int|null,
 *     selected_reason: string|null,
 *     preprocessing_version: string|null,
 *     prompt_version: string|null,
 *     parser_version: string|null
 * }
 */
final class OcrComparisonAttemptSummary
{
    /**
     * @param  array<string, mixed>|null  $engineMetaJson
     */
    public function __construct(
        public readonly int $attemptId,
        public readonly string $engine,
        public readonly ?string $source,
        public readonly string $status,
        public readonly bool $isPrimary,
        public readonly ?string $rawText,
        public readonly ?array $engineMetaJson,
        public readonly ?float $qualityScore,
        public readonly ?int $durationMs,
        public readonly ?string $selectedReason,
        public readonly ?string $preprocessingVersion,
        public readonly ?string $promptVersion,
        public readonly ?string $parserVersion,
    ) {}

    /**
     * @param  OcrComparisonAttemptSummaryArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            attemptId: (int) ($data['attempt_id'] ?? 0),
            engine: (string) ($data['engine'] ?? ''),
            source: isset($data['source']) && is_string($data['source']) ? $data['source'] : null,
            status: (string) ($data['status'] ?? ''),
            isPrimary: (bool) ($data['is_primary'] ?? false),
            rawText: isset($data['raw_text']) && is_string($data['raw_text']) ? $data['raw_text'] : null,
            engineMetaJson: is_array($data['engine_meta_json'] ?? null) ? $data['engine_meta_json'] : null,
            qualityScore: is_numeric($data['quality_score'] ?? null) ? (float) $data['quality_score'] : null,
            durationMs: isset($data['duration_ms']) && is_numeric($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            selectedReason: isset($data['selected_reason']) && is_string($data['selected_reason'])
                ? $data['selected_reason']
                : null,
            preprocessingVersion: isset($data['preprocessing_version']) && is_string($data['preprocessing_version'])
                ? $data['preprocessing_version']
                : null,
            promptVersion: isset($data['prompt_version']) && is_string($data['prompt_version'])
                ? $data['prompt_version']
                : null,
            parserVersion: isset($data['parser_version']) && is_string($data['parser_version'])
                ? $data['parser_version']
                : null,
        );
    }

    /**
     * @return OcrComparisonAttemptSummaryArray
     */
    public function toArray(): array
    {
        return [
            'attempt_id' => $this->attemptId,
            'engine' => $this->engine,
            'source' => $this->source,
            'status' => $this->status,
            'is_primary' => $this->isPrimary,
            'raw_text' => $this->rawText,
            'engine_meta_json' => $this->engineMetaJson,
            'quality_score' => $this->qualityScore,
            'duration_ms' => $this->durationMs,
            'selected_reason' => $this->selectedReason,
            'preprocessing_version' => $this->preprocessingVersion,
            'prompt_version' => $this->promptVersion,
            'parser_version' => $this->parserVersion,
        ];
    }

    public function hasRawText(): bool
    {
        return is_string($this->rawText) && trim($this->rawText) !== '';
    }
}
