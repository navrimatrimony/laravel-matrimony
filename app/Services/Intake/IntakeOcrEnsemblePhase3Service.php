<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatchItem;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldExtractorInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldNormalizerInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldValidatorInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleFieldVoterInterface;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleParseInputAssemblerInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionMeta;
use App\Services\Intake\OcrEnsemble\Data\OcrEnsembleExtractionResultDto;
use App\Services\Intake\OcrEnsemble\Data\Phase3ResolutionResult;
use App\Services\Intake\OcrEnsemble\OcrEnsemblePhase3Constants;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleParseInputAssemblySupport;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OCR Ensemble Phase 3 orchestrator (field extract → normalize → vote → validate → assemble → persist).
 */
class IntakeOcrEnsemblePhase3Service
{
    public function __construct(
        private readonly IntakeOcrEnsembleGate $ensembleGate,
        private readonly OcrEnsembleFieldExtractorInterface $fieldExtractor,
        private readonly OcrEnsembleFieldNormalizerInterface $fieldNormalizer,
        private readonly OcrEnsembleFieldVoterInterface $fieldVoter,
        private readonly OcrEnsembleFieldValidatorInterface $fieldValidator,
        private readonly OcrEnsembleParseInputAssemblerInterface $parseInputAssembler,
    ) {}

    public function runForBulkItemIfApplicable(BulkIntakeBatchItem $item): Phase3ResolutionResult
    {
        if (! $this->ensembleGate->isPhase3Enabled()) {
            return Phase3ResolutionResult::skipped('phase3_gate_disabled');
        }

        if (! $this->isEligibleBulkFileItem($item)) {
            return Phase3ResolutionResult::skipped('bulk_item_ineligible');
        }

        $item->loadMissing('biodataIntake');
        $intake = $item->biodataIntake;
        if (! $intake instanceof BiodataIntake) {
            return Phase3ResolutionResult::skipped('missing_biodata_intake');
        }

        if ($this->isReusedTranscriptItem($item)) {
            return Phase3ResolutionResult::skipped('reused_transcript');
        }

        return $this->resolve($intake);
    }

    public function resolve(BiodataIntake $intake): Phase3ResolutionResult
    {
        if (! $intake->exists) {
            return Phase3ResolutionResult::skipped('intake_not_persisted');
        }

        try {
            $usableAttempts = $this->loadUsableAttempts($intake);
            if ($usableAttempts === []) {
                return Phase3ResolutionResult::skipped('no_usable_ocr_attempts');
            }

            $extraction = $this->fieldExtractor->extractCandidates($usableAttempts);
            if ($extraction->isEmpty()) {
                return Phase3ResolutionResult::skipped('no_field_candidates');
            }

            $voteMode = $this->voteModeForExtraction($extraction);
            $fieldRecords = $this->resolveFieldRecords($extraction, $voteMode);
            $envelope = $this->buildEnvelope($intake, $usableAttempts, $extraction, $voteMode, $fieldRecords);
            $assembledParseInput = $this->parseInputAssembler->assemble(
                $envelope,
                $this->primaryOcrText($usableAttempts, $intake),
            );

            if (! $this->assembledTextMeetsQualityGate($assembledParseInput)) {
                return Phase3ResolutionResult::skipped('assembled_parse_input_too_short');
            }

            $intake->field_resolution_json = $envelope->toArray();
            $intake->last_parse_input_text = $assembledParseInput;
            $intake->save();

            return Phase3ResolutionResult::resolved($envelope, $assembledParseInput);
        } catch (Throwable $exception) {
            Log::warning('phase3_field_resolution_failed', [
                'intake_id' => $intake->id,
                'message' => $exception->getMessage(),
            ]);

            return Phase3ResolutionResult::skipped('phase3_resolution_failed');
        }
    }

    /**
     * @return list<BiodataIntakeOcrAttempt>
     */
    private function loadUsableAttempts(BiodataIntake $intake): array
    {
        $attempts = $intake->ocrAttempts()
            ->where('status', BiodataIntakeOcrAttempt::STATUS_SUCCESS)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->all();

        return $this->fieldExtractor->filterUsableAttempts($attempts);
    }

    /**
     * @param  list<BiodataIntakeOcrAttempt>  $usableAttempts
     * @param  array<string, FieldResolutionFieldRecord>  $fieldRecords
     */
    private function buildEnvelope(
        BiodataIntake $intake,
        array $usableAttempts,
        OcrEnsembleExtractionResultDto $extraction,
        string $voteMode,
        array $fieldRecords,
    ): FieldResolutionEnvelope {
        $enginesPresent = [];
        foreach ($extraction->engines as $engineDto) {
            $enginesPresent[] = $engineDto->engineKey;
        }

        return new FieldResolutionEnvelope(
            meta: new FieldResolutionMeta(
                schemaVersion: OcrEnsemblePhase3Constants::SCHEMA_VERSION,
                pipelineVersion: OcrEnsemblePhase3Constants::PIPELINE_VERSION,
                resolvedAt: now()->toIso8601String(),
                intakeId: (int) $intake->id,
                attemptCount: count($usableAttempts),
                enginesPresent: array_values(array_unique($enginesPresent)),
                voteMode: $voteMode,
                assemblyVersion: OcrEnsemblePhase3Constants::ASSEMBLY_VERSION,
            ),
            fields: $fieldRecords,
        );
    }

    /**
     * @return array<string, FieldResolutionFieldRecord>
     */
    private function resolveFieldRecords(OcrEnsembleExtractionResultDto $extraction, string $voteMode): array
    {
        $fieldRecords = [];
        foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
            $candidatesByEngine = $this->candidatesForField($extraction, $fieldKey);
            $normalizedByEngine = $this->fieldNormalizer->normalizeField($fieldKey, $candidatesByEngine);
            $voteRecord = $this->fieldVoter->voteField($fieldKey, $normalizedByEngine, $voteMode);
            $validation = $this->fieldValidator->validateField($fieldKey, $normalizedByEngine);
            $fieldRecords[$fieldKey] = $this->mergeFieldRecord(
                $candidatesByEngine,
                $normalizedByEngine,
                $voteRecord,
                $validation,
            );
        }

        return $fieldRecords;
    }

    /**
     * @param  array<string, string|null>  $candidatesByEngine
     * @param  array<string, string|null>  $normalizedByEngine
     * @param  array{passed: bool, code: string, detail: string|null, winning_engine: string|null, final: string|null}  $validation
     */
    private function mergeFieldRecord(
        array $candidatesByEngine,
        array $normalizedByEngine,
        FieldResolutionFieldRecord $voteRecord,
        array $validation,
    ): FieldResolutionFieldRecord {
        if ($validation['passed'] && is_string($validation['final']) && trim($validation['final']) !== '') {
            return new FieldResolutionFieldRecord(
                final: $validation['final'],
                status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
                source: OcrEnsemblePhase3Constants::FIELD_SOURCE_VALIDATOR,
                winningEngine: $validation['winning_engine'] ?? $voteRecord->winningEngine,
                confidence: null,
                reason: $this->resolutionReason($voteRecord),
                candidates: $candidatesByEngine,
                normalized: $normalizedByEngine,
                validator: [
                    'passed' => true,
                    'code' => $validation['code'],
                    'detail' => $validation['detail'],
                ],
            );
        }

        return new FieldResolutionFieldRecord(
            final: null,
            status: OcrEnsemblePhase3Constants::FIELD_STATUS_MISSING,
            source: OcrEnsemblePhase3Constants::FIELD_SOURCE_MISSING,
            winningEngine: $validation['winning_engine'] ?? $voteRecord->winningEngine,
            confidence: null,
            reason: 'no_valid_candidate_after_validator',
            candidates: $candidatesByEngine,
            normalized: $normalizedByEngine,
            validator: [
                'passed' => false,
                'code' => $validation['code'],
                'detail' => $validation['detail'],
            ],
        );
    }

    private function resolutionReason(FieldResolutionFieldRecord $voteRecord): string
    {
        if ($voteRecord->reason === 'single_engine_pass_through') {
            return 'single_engine_pass_through_after_validator';
        }

        return $voteRecord->reason.'_after_validator';
    }

    /**
     * @return array<string, string|null>
     */
    private function candidatesForField(OcrEnsembleExtractionResultDto $extraction, string $fieldKey): array
    {
        $candidates = [];
        foreach ($extraction->engines as $engineDto) {
            $candidates[$engineDto->engineKey] = $engineDto->field($fieldKey);
        }

        return $candidates;
    }

    private function voteModeForExtraction(OcrEnsembleExtractionResultDto $extraction): string
    {
        return count($extraction->engines) > 1
            ? OcrEnsemblePhase3Constants::VOTE_MODE_MULTI_ENGINE
            : OcrEnsemblePhase3Constants::VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH;
    }

    /**
     * @param  list<BiodataIntakeOcrAttempt>  $attempts
     */
    private function primaryOcrText(array $attempts, BiodataIntake $intake): string
    {
        foreach ($attempts as $attempt) {
            if ($attempt->is_primary && trim((string) $attempt->raw_text) !== '') {
                return (string) $attempt->raw_text;
            }
        }

        foreach ($attempts as $attempt) {
            if (trim((string) $attempt->raw_text) !== '') {
                return (string) $attempt->raw_text;
            }
        }

        return (string) ($intake->raw_ocr_text ?? '');
    }

    private function assembledTextMeetsQualityGate(string $assembledParseInput): bool
    {
        return mb_strlen(trim($assembledParseInput), 'UTF-8') >= OcrEnsembleParseInputAssemblySupport::MIN_ASSEMBLED_TEXT_LENGTH;
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
