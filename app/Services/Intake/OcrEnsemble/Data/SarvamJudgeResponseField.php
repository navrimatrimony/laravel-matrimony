<?php

namespace App\Services\Intake\OcrEnsemble\Data;

/**
 * One judged field from a Sarvam judge HTTP response (immutable).
 *
 * @phpstan-type SarvamJudgeResponseFieldArray array{
 *     field_name: string,
 *     value: string|null,
 *     confidence: float|null,
 *     reason: string|null
 * }
 */
final class SarvamJudgeResponseField
{
    public function __construct(
        public readonly string $fieldName,
        public readonly ?string $value,
        public readonly ?float $confidence = null,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @param  SarvamJudgeResponseFieldArray  $data
     */
    public static function fromArray(array $data): self
    {
        $value = $data['value'] ?? null;
        if (! is_string($value)) {
            $value = null;
        } else {
            $value = trim($value);
            $value = $value === '' ? null : $value;
        }

        return new self(
            fieldName: (string) ($data['field_name'] ?? ''),
            value: $value,
            confidence: is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : null,
            reason: isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null,
        );
    }

    /**
     * @return SarvamJudgeResponseFieldArray
     */
    public function toArray(): array
    {
        return [
            'field_name' => $this->fieldName,
            'value' => $this->value,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
        ];
    }
}
