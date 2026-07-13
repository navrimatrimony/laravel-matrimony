<?php

namespace App\Services\Intake\OcrEnsemble\Data;

use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use InvalidArgumentException;

/**
 * Per-engine structured field candidates extracted from one OCR attempt.
 */
final class OcrEngineFieldCandidatesDto
{
    /**
     * @param  array<string, string|null>  $fields
     */
    public function __construct(
        public readonly string $engineKey,
        public readonly ?int $ocrAttemptId,
        public readonly array $fields,
    ) {
        foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
            if (! array_key_exists($fieldKey, $this->fields)) {
                throw new InvalidArgumentException("Missing structured field: {$fieldKey}");
            }
        }
    }

    public function field(string $fieldKey): ?string
    {
        return $this->fields[$fieldKey] ?? null;
    }

    /**
     * @return array<string, string|null>
     */
    public function toFieldMap(): array
    {
        return $this->fields;
    }
}
