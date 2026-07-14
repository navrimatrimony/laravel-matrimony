<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonEvidenceLoaderInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonTableBuilderInterface;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;
use App\Services\Intake\OcrEnsemble\Data\Phase5ComparisonResult;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase5Constants;

/**
 * OCR Ensemble Phase 5 orchestrator (comparison table for operator review).
 *
 * Flow: gate → eligibility → EvidenceLoader → TableBuilder → Phase5ComparisonResult.
 * Read-only; no persistence; no UI.
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

        if (! $intake->exists || (int) ($intake->id ?? 0) <= 0) {
            return Phase5ComparisonResult::skipped('intake_not_persisted');
        }

        $evidence = $this->evidenceLoader->loadForIntake($intake);
        $table = $this->tableBuilder->build($evidence);

        if ($this->isEmptyEvidence($evidence)) {
            return Phase5ComparisonResult::empty(
                $this->emptyReason($evidence, $table->audit->emptyState),
                $table,
            );
        }

        return Phase5ComparisonResult::resolved($table);
    }

    private function isEmptyEvidence(OcrComparisonEvidenceBundle $evidence): bool
    {
        return ! $evidence->hasFieldResolution();
    }

    private function emptyReason(OcrComparisonEvidenceBundle $evidence, ?string $auditEmptyState): string
    {
        if (is_string($auditEmptyState) && $auditEmptyState !== '') {
            return $auditEmptyState;
        }

        if ($evidence->attemptCount() === 0 && $evidence->enginesPresent === []) {
            return OcrEnsemblePhase5Constants::EMPTY_STATE_LEGACY_INTAKE;
        }

        return OcrEnsemblePhase5Constants::EMPTY_STATE_ENSEMBLE_NOT_RUN;
    }
}
