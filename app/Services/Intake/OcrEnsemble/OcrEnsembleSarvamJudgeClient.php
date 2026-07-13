<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Models\BiodataIntake;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeClientInterface;

/**
 * Step 4a skeleton — Sarvam API integration arrives in Step 4c.
 */
final class OcrEnsembleSarvamJudgeClient implements OcrEnsembleSarvamJudgeClientInterface
{
    /**
     * {@inheritDoc}
     */
    public function extractForIntake(BiodataIntake $intake): array
    {
        unset($intake);

        return [
            'text' => '',
            'meta' => [
                'ok' => false,
                'reason' => 'phase4_v1_skeleton',
            ],
        ];
    }
}
