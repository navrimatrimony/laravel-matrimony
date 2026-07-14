<?php

namespace App\Services\Intake\OcrEnsemble\Contracts;

use App\Models\BiodataIntake;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;

/**
 * Loads read-only OCR attempts + field_resolution_json for Phase 5 comparison.
 */
interface OcrEnsembleComparisonEvidenceLoaderInterface
{
    public function loadForIntake(BiodataIntake $intake): OcrComparisonEvidenceBundle;
}
