<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeMergeResult;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponse;

/**
 * Merges Sarvam judge field values into a new FieldResolutionEnvelope (read-only toward DB).
 */
interface OcrEnsembleSarvamJudgeMergerInterface
{
    public function merge(
        FieldResolutionEnvelope $envelope,
        SarvamJudgeResponse $response,
    ): SarvamJudgeMergeResult;
}
