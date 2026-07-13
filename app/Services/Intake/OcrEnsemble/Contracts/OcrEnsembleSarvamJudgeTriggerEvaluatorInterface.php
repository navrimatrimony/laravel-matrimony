<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeTriggerReport;

/**
 * Evaluates Phase 3 {@see FieldResolutionEnvelope} against frozen Sarvam trigger rules (Blueprint §5.1).
 */
interface OcrEnsembleSarvamJudgeTriggerEvaluatorInterface
{
    public function evaluate(FieldResolutionEnvelope $envelope): SarvamJudgeTriggerReport;
}
