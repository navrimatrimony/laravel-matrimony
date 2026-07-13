<?php

namespace App\Services\Intake\OcrEnsemble\Data;

/**
 * Extraction output for one or more OCR engine attempts.
 */
final class OcrEnsembleExtractionResultDto
{
    /**
     * @param  list<OcrEngineFieldCandidatesDto>  $engines
     */
    public function __construct(public readonly array $engines) {}

    public function primary(): ?OcrEngineFieldCandidatesDto
    {
        return $this->engines[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->engines === [];
    }
}
