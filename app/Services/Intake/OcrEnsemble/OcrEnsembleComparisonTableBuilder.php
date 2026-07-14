<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonTableBuilderInterface;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAuditMeta;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;

/**
 * Step 5a skeleton — row assembly arrives in a later Phase 5 step.
 */
final class OcrEnsembleComparisonTableBuilder implements OcrEnsembleComparisonTableBuilderInterface
{
    public function build(OcrComparisonEvidenceBundle $evidence): OcrComparisonTable
    {
        return OcrComparisonTable::empty(
            OcrComparisonAuditMeta::skeleton(
                $evidence->intakeId,
                OcrEnsemblePhase5Constants::EMPTY_STATE_ENSEMBLE_NOT_RUN
            )
        );
    }
}
