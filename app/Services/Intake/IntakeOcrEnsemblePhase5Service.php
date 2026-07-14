<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonEvidenceLoaderInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonTableBuilderInterface;
use App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult;

/**
 * OCR Ensemble Phase 5 orchestrator (comparison table for operator review).
 *
 * Step 5a: gate wiring + skeleton only. Read-only; no persistence; no UI.
 */
class IntakeOcrEnsemblePhase5Service
{
    public function __construct(
        private readonly IntakeOcrEnsembleGate $ensembleGate,
        private readonly OcrEnsembleComparisonEvidenceLoaderInterface $evidenceLoader,
        private readonly OcrEnsembleComparisonTableBuilderInterface $tableBuilder,
    ) {}

    public function buildComparisonForIntake(BiodataIntake $intake): Phase5ComparisonResult
    {
        if (! $this->ensembleGate->isPhase5Enabled()) {
            return Phase5ComparisonResult::skipped('phase5_gate_disabled');
        }

        if (! $intake->exists) {
            return Phase5ComparisonResult::skipped('intake_not_persisted');
        }

        // Dependencies are bound for later steps; 5a does not assemble rows yet.
        return Phase5ComparisonResult::notImplemented('phase5_v1_skeleton');
    }
}
