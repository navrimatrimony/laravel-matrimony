<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeRequest;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeTriggerReport;

/**
 * Builds an immutable Sarvam judge request from a trigger report + Phase 3 envelope.
 */
interface OcrEnsembleSarvamJudgeRequestBuilderInterface
{
    public function build(
        SarvamJudgeTriggerReport $triggerReport,
        FieldResolutionEnvelope $envelope,
        string $primaryOcrText,
    ): SarvamJudgeRequest;
}
