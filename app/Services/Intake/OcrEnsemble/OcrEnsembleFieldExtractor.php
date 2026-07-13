<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldExtractorInterface;

/**
 * Step 3a skeleton — extraction logic arrives in Step 3b.
 */
final class OcrEnsembleFieldExtractor implements OcrEnsembleFieldExtractorInterface
{
    /**
     * {@inheritDoc}
     */
    public function extractCandidates(array $attempts): array
    {
        // TODO(phase3-3b): delegate to Parsing helpers (MarathiOcrFieldRescueService, etc.).
        unset($attempts);

        return [];
    }

    /**
     * @return list<BiodataIntakeOcrAttempt>
     */
    public function filterUsableAttempts(array $attempts): array
    {
        return array_values(array_filter(
            $attempts,
            static fn (mixed $attempt): bool => $attempt instanceof BiodataIntakeOcrAttempt
                && trim((string) $attempt->raw_text) !== '',
        ));
    }
}
