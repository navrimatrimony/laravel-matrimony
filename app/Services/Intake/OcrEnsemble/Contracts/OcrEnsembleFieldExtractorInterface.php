<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Intake\OcrEnsemble\Data\OcrEnsembleExtractionResultDto;
use App\Services\Intake\OcrEnsemble\Data\OcrEngineFieldCandidatesDto;

interface OcrEnsembleFieldExtractorInterface
{
    /**
     * Extract per-engine field candidates from OCR attempts.
     *
     * @param  list<BiodataIntakeOcrAttempt>  $attempts
     */
    public function extractCandidates(array $attempts): OcrEnsembleExtractionResultDto;

    public function extractFromText(
        string $text,
        string $engineKey,
        ?int $ocrAttemptId = null,
    ): OcrEngineFieldCandidatesDto;

    /**
     * @return list<BiodataIntakeOcrAttempt>
     */
    public function filterUsableAttempts(array $attempts): array;
}
