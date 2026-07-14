<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Models\BiodataIntake;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonEvidenceLoaderInterface;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;

/**
 * Step 5a skeleton — evidence loading arrives in a later Phase 5 step.
 */
final class OcrEnsembleComparisonEvidenceLoader implements OcrEnsembleComparisonEvidenceLoaderInterface
{
    public function loadForIntake(BiodataIntake $intake): OcrComparisonEvidenceBundle
    {
        return OcrComparisonEvidenceBundle::empty((int) ($intake->id ?? 0));
    }
}
