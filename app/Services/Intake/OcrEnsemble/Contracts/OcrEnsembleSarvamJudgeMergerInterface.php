<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\OcrEngineFieldCandidatesDto;

/**
 * Re-merge Sarvam judge candidates into affected fields only (Phase 4 — later steps).
 */
interface OcrEnsembleSarvamJudgeMergerInterface
{
    /**
     * @param  list<string>  $affectedFieldKeys
     */
    public function mergeAffectedFields(
        FieldResolutionEnvelope $envelope,
        OcrEngineFieldCandidatesDto $sarvamCandidates,
        array $affectedFieldKeys,
    ): FieldResolutionEnvelope;
}
