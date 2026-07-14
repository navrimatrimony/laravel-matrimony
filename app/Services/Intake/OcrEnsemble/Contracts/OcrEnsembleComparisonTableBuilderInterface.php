<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;

/**
 * Builds an immutable comparison table from Phase 3/4 evidence (no writes).
 */
interface OcrEnsembleComparisonTableBuilderInterface
{
    public function build(OcrComparisonEvidenceBundle $evidence): OcrComparisonTable;
}
