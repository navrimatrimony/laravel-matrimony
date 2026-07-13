<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeTriggerEvaluatorInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeTriggerReport;

/**
 * Step 4a skeleton — trigger rules arrive in Step 4b.
 */
final class OcrEnsembleSarvamJudgeTriggerEvaluator implements OcrEnsembleSarvamJudgeTriggerEvaluatorInterface
{
    public function evaluate(FieldResolutionEnvelope $envelope): SarvamJudgeTriggerReport
    {
        unset($envelope);

        return SarvamJudgeTriggerReport::empty('phase4_v1_skeleton');
    }
}
