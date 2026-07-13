<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldNormalizerInterface;

/**
 * Step 3a skeleton — per-field normalization arrives in Step 3c.
 */
final class OcrEnsembleFieldNormalizer implements OcrEnsembleFieldNormalizerInterface
{
    /**
     * {@inheritDoc}
     */
    public function normalizeField(string $fieldKey, array $candidatesByEngine): array
    {
        // TODO(phase3-3c): field-specific normalization (DOB ISO, mobile digits, etc.).
        unset($fieldKey);

        return $candidatesByEngine;
    }
}
