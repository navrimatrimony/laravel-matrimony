<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeClientInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeMergerInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeTriggerEvaluatorInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\Phase4JudgeResult;

/**
 * OCR Ensemble Phase 4 orchestrator (trigger evaluate → Sarvam judge → merge → persist).
 *
 * Step 4a: gate wiring + skeleton only. Does not modify Phase 3 pipeline classes.
 */
class IntakeOcrEnsemblePhase4Service
{
    public function __construct(
        private readonly IntakeOcrEnsembleGate $ensembleGate,
        private readonly OcrEnsembleSarvamJudgeTriggerEvaluatorInterface $triggerEvaluator,
        private readonly OcrEnsembleSarvamJudgeClientInterface $sarvamJudgeClient,
        private readonly OcrEnsembleSarvamJudgeMergerInterface $sarvamJudgeMerger,
    ) {}

    public function runForBulkItemIfApplicable(BulkIntakeBatchItem $item): Phase4JudgeResult
    {
        if (! $this->ensembleGate->isPhase4Enabled()) {
            return Phase4JudgeResult::skipped('phase4_gate_disabled');
        }

        if (! $this->isEligibleBulkFileItem($item)) {
            return Phase4JudgeResult::skipped('bulk_item_ineligible');
        }

        $item->loadMissing('biodataIntake');
        $intake = $item->biodataIntake;
        if (! $intake instanceof BiodataIntake) {
            return Phase4JudgeResult::skipped('missing_biodata_intake');
        }

        if ($this->isReusedTranscriptItem($item)) {
            return Phase4JudgeResult::skipped('reused_transcript');
        }

        $envelope = $this->loadFieldResolutionEnvelope($intake);
        if ($envelope === null) {
            return Phase4JudgeResult::skipped('missing_field_resolution_json');
        }

        return $this->judge($intake, $envelope);
    }

    public function judge(BiodataIntake $intake, ?FieldResolutionEnvelope $envelope = null): Phase4JudgeResult
    {
        if (! $intake->exists) {
            return Phase4JudgeResult::skipped('intake_not_persisted');
        }

        $envelope ??= $this->loadFieldResolutionEnvelope($intake);
        if ($envelope === null) {
            return Phase4JudgeResult::skipped('missing_field_resolution_json');
        }

        return Phase4JudgeResult::notImplemented('phase4_v1_skeleton');
    }

    private function loadFieldResolutionEnvelope(BiodataIntake $intake): ?FieldResolutionEnvelope
    {
        $json = $intake->field_resolution_json;
        if (! is_array($json) || $json === []) {
            return null;
        }

        return FieldResolutionEnvelope::fromArray($json);
    }

    private function isEligibleBulkFileItem(BulkIntakeBatchItem $item): bool
    {
        return (string) $item->input_type === BulkIntakeBatchItem::INPUT_FILE;
    }

    private function isReusedTranscriptItem(BulkIntakeBatchItem $item): bool
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];

        return ($meta['ocr_ensemble_skip_reason'] ?? null) === 'reused_transcript';
    }
}
