<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeMergerInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\OcrEngineFieldCandidatesDto;

/**
 * Step 4a skeleton — field re-merge arrives in Step 4d.
 */
final class OcrEnsembleSarvamJudgeMerger implements OcrEnsembleSarvamJudgeMergerInterface
{
    public function mergeAffectedFields(
        FieldResolutionEnvelope $envelope,
        OcrEngineFieldCandidatesDto $sarvamCandidates,
        array $affectedFieldKeys,
    ): FieldResolutionEnvelope {
        unset($sarvamCandidates, $affectedFieldKeys);

        return $envelope;
    }
}
