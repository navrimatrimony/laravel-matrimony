<?php

namespace App\Services\Intake\OcrEnsemble\Data;

/**
 * One structured-field row for the OCR comparison table (immutable / read-only).
 *
 * @phpstan-type OcrComparisonFieldRowArray array{
 *     field_key: string,
 *     field_label: string|null,
 *     final_value: string|null,
 *     tesseract_value: string|null,
 *     second_ocr_value: string|null,
 *     sarvam_value: string|null,
 *     reason: string|null,
 *     status: string|null,
 *     source: string|null,
 *     winning_engine: string|null
 * }
 */
final class OcrComparisonFieldRow
{
    public function __construct(
        public readonly string $fieldKey,
        public readonly ?string $fieldLabel,
        public readonly ?string $finalValue,
        public readonly ?string $tesseractValue,
        public readonly ?string $secondOcrValue,
        public readonly ?string $sarvamValue,
        public readonly ?string $reason,
        public readonly ?string $status = null,
        public readonly ?string $source = null,
        public readonly ?string $winningEngine = null,
    ) {}

    /**
     * @param  OcrComparisonFieldRowArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            fieldKey: (string) ($data['field_key'] ?? ''),
            fieldLabel: isset($data['field_label']) ? (string) $data['field_label'] : null,
            finalValue: isset($data['final_value']) && is_string($data['final_value']) ? $data['final_value'] : null,
            tesseractValue: isset($data['tesseract_value']) && is_string($data['tesseract_value']) ? $data['tesseract_value'] : null,
            secondOcrValue: isset($data['second_ocr_value']) && is_string($data['second_ocr_value']) ? $data['second_ocr_value'] : null,
            sarvamValue: isset($data['sarvam_value']) && is_string($data['sarvam_value']) ? $data['sarvam_value'] : null,
            reason: isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null,
            status: isset($data['status']) && is_string($data['status']) ? $data['status'] : null,
            source: isset($data['source']) && is_string($data['source']) ? $data['source'] : null,
            winningEngine: isset($data['winning_engine']) && is_string($data['winning_engine']) ? $data['winning_engine'] : null,
        );
    }

    /**
     * @return OcrComparisonFieldRowArray
     */
    public function toArray(): array
    {
        return [
            'field_key' => $this->fieldKey,
            'field_label' => $this->fieldLabel,
            'final_value' => $this->finalValue,
            'tesseract_value' => $this->tesseractValue,
            'second_ocr_value' => $this->secondOcrValue,
            'sarvam_value' => $this->sarvamValue,
            'reason' => $this->reason,
            'status' => $this->status,
            'source' => $this->source,
            'winning_engine' => $this->winningEngine,
        ];
    }
}
