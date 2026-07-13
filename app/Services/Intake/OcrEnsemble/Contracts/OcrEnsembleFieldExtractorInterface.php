<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Models\BiodataIntakeOcrAttempt;

interface OcrEnsembleFieldExtractorInterface
{
    /**
     * Extract per-engine field candidates from OCR attempts.
     *
     * @param  list<BiodataIntakeOcrAttempt>  $attempts
     * @return array<string, array<string, string|null>> engine => field => candidate
     */
    public function extractCandidates(array $attempts): array;
}
