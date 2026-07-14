<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatchItem;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleParseInputAssemblerInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeClientInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeMergerInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeRequestBuilderInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeTriggerEvaluatorInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\Phase4JudgeResult;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponse;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleParseInputAssemblySupport;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OCR Ensemble Phase 4 orchestrator:
 * trigger → request → Sarvam client → merge → quality gate → persist.
 *
 * Soft-fails on HTTP/merge errors. Never modifies raw_ocr_text. Never throws to callers.
 */
class IntakeOcrEnsemblePhase4Service
{
    public function __construct(
        private readonly IntakeOcrEnsembleGate $ensembleGate,
        private readonly OcrEnsembleSarvamJudgeTriggerEvaluatorInterface $triggerEvaluator,
        private readonly OcrEnsembleSarvamJudgeRequestBuilderInterface $requestBuilder,
        private readonly OcrEnsembleSarvamJudgeClientInterface $sarvamJudgeClient,
        private readonly OcrEnsembleSarvamJudgeMergerInterface $sarvamJudgeMerger,
        private readonly OcrEnsembleParseInputAssemblerInterface $parseInputAssembler,
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

        try {
            return $this->runPipeline($intake, $envelope);
        } catch (Throwable $exception) {
            Log::warning('phase4_sarvam_judge_failed', [
                'intake_id' => $intake->id,
                'message' => $exception->getMessage(),
            ]);

            return Phase4JudgeResult::softFailed('phase4_judge_exception');
        }
    }

    private function runPipeline(BiodataIntake $intake, FieldResolutionEnvelope $envelope): Phase4JudgeResult
    {
        $triggerReport = $this->triggerEvaluator->evaluate($envelope);
        if (! $triggerReport->shouldJudge()) {
            Log::info('phase4_sarvam_judge_skipped', [
                'intake_id' => $intake->id,
                'reason' => 'no_triggers',
            ]);

            return Phase4JudgeResult::skipped('no_triggers', $triggerReport);
        }

        $primaryOcrText = $this->primaryOcrText($intake);
        $request = $this->requestBuilder->build($triggerReport, $envelope, $primaryOcrText);
        if ($request->isEmpty()) {
            return Phase4JudgeResult::skipped('empty_judge_request', $triggerReport);
        }

        $sarvamResponse = $this->sarvamJudgeClient->judge($request);
        if (! $sarvamResponse->ok) {
            Log::warning('phase4_sarvam_http_soft_failed', [
                'intake_id' => $intake->id,
                'outcome' => $sarvamResponse->outcome,
                'error_code' => $sarvamResponse->errorCode,
                'attempt_count' => $sarvamResponse->attemptCount,
            ]);

            return Phase4JudgeResult::softFailed(
                $this->softFailReason($sarvamResponse),
                $triggerReport,
            );
        }

        $mergeResult = $this->sarvamJudgeMerger->merge($envelope, $sarvamResponse);
        if (! $mergeResult->changed) {
            Log::info('phase4_sarvam_merge_noop', [
                'intake_id' => $intake->id,
                'skipped_fields' => $mergeResult->skippedFields,
            ]);

            return Phase4JudgeResult::noop('merge_noop', $triggerReport);
        }

        $assembledParseInput = $this->parseInputAssembler->assemble(
            $mergeResult->envelope,
            $primaryOcrText,
        );

        if (! $this->assembledTextMeetsQualityGate($assembledParseInput)) {
            Log::warning('phase4_assembled_parse_input_too_short', [
                'intake_id' => $intake->id,
                'length' => mb_strlen(trim($assembledParseInput), 'UTF-8'),
                'updated_fields' => $mergeResult->updatedFields,
            ]);

            return Phase4JudgeResult::noop('assembled_parse_input_too_short', $triggerReport);
        }

        // Persist merged envelope + rebuilt parse input only. raw_ocr_text is never touched.
        $intake->field_resolution_json = $mergeResult->envelope->toArray();
        $intake->last_parse_input_text = $assembledParseInput;
        $intake->save();

        Log::info('phase4_sarvam_judge_resolved', [
            'intake_id' => $intake->id,
            'updated_fields' => $mergeResult->updatedFields,
        ]);

        return Phase4JudgeResult::resolved(
            $mergeResult->envelope,
            $assembledParseInput,
            $triggerReport,
        );
    }

    private function softFailReason(SarvamJudgeResponse $response): string
    {
        return match ($response->outcome) {
            SarvamJudgeResponse::OUTCOME_TIMEOUT => 'sarvam_timeout',
            SarvamJudgeResponse::OUTCOME_HTTP_ERROR => 'sarvam_http_error',
            SarvamJudgeResponse::OUTCOME_INVALID_JSON => 'sarvam_invalid_json',
            SarvamJudgeResponse::OUTCOME_EMPTY_RESPONSE => 'sarvam_empty_response',
            SarvamJudgeResponse::OUTCOME_CONFIG_ERROR => 'sarvam_config_error',
            default => 'sarvam_soft_failed',
        };
    }

    private function primaryOcrText(BiodataIntake $intake): string
    {
        $attempt = $intake->ocrAttempts()
            ->where('status', BiodataIntakeOcrAttempt::STATUS_SUCCESS)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if ($attempt !== null && trim((string) $attempt->raw_text) !== '') {
            return (string) $attempt->raw_text;
        }

        return (string) ($intake->raw_ocr_text ?? '');
    }

    private function assembledTextMeetsQualityGate(string $assembledParseInput): bool
    {
        return mb_strlen(trim($assembledParseInput), 'UTF-8')
            >= OcrEnsembleParseInputAssemblySupport::MIN_ASSEMBLED_TEXT_LENGTH;
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
