<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldValidatorInterface;

/**
 * Step 3a skeleton — validators arrive in Step 3c.
 */
final class OcrEnsembleFieldValidator implements OcrEnsembleFieldValidatorInterface
{
    /**
     * {@inheritDoc}
     */
    public function validateField(string $fieldKey, array $normalizedByEngine): array
    {
        // TODO(phase3-3c): per-field validators (mobile regex, DOB range, master lookup, etc.).
        unset($fieldKey, $normalizedByEngine);

        return [
            'passed' => false,
            'code' => 'not_implemented',
            'detail' => 'step_3a_skeleton',
            'winning_engine' => null,
            'final' => null,
        ];
    }
}
