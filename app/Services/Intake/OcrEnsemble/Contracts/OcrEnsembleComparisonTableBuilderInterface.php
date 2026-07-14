<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;

/**
 * Builds an immutable comparison table from an EvidenceBundle (no writes).
 *
 * One row per Phase 3 canonical field. Engine columns gated by evidence presence.
 */
interface OcrEnsembleComparisonTableBuilderInterface
{
    public function build(OcrComparisonEvidenceBundle $evidence): OcrComparisonTable;
}
