<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeRequestBuilderInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeRequest;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeRequestField;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeTriggerReport;
use App\Services\Intake\OcrEnsemble\Support\OcrEnsembleSarvamJudgeRequestSupport;

/**
 * Immutable, deterministic Sarvam judge request builder (no HTTP / no API).
 */
final class OcrEnsembleSarvamJudgeRequestBuilder implements OcrEnsembleSarvamJudgeRequestBuilderInterface
{
    public function build(
        SarvamJudgeTriggerReport $triggerReport,
        FieldResolutionEnvelope $envelope,
        string $primaryOcrText,
    ): SarvamJudgeRequest {
        $triggered = $triggerReport->triggeredFields;
        $triggerReasons = [];
        $fields = [];

        // Canonical order from frozen TRIGGER_FIELDS — guarantees determinism + no duplicates.
        foreach (OcrEnsemblePhase4Constants::TRIGGER_FIELDS as $fieldKey) {
            if (! array_key_exists($fieldKey, $triggered)) {
                continue;
            }

            $reason = (string) $triggered[$fieldKey];
            $triggerReasons[$fieldKey] = $reason;
            $fields[] = $this->buildField(
                fieldKey: $fieldKey,
                triggerReason: $reason,
                envelope: $envelope,
                primaryOcrText: $primaryOcrText,
            );
        }

        return new SarvamJudgeRequest(
            schemaVersion: OcrEnsemblePhase4Constants::SCHEMA_VERSION,
            pipelineVersion: OcrEnsemblePhase4Constants::PIPELINE_VERSION,
            intakeId: $envelope->meta->intakeId,
            triggerReasons: $triggerReasons,
            fields: $fields,
        );
    }

    private function buildField(
        string $fieldKey,
        string $triggerReason,
        FieldResolutionEnvelope $envelope,
        string $primaryOcrText,
    ): SarvamJudgeRequestField {
        $record = $envelope->fields[$fieldKey] ?? null;
        if (! $record instanceof FieldResolutionFieldRecord) {
            $record = OcrEnsembleSarvamJudgeRequestSupport::missingFieldRecord();
        }

        $candidates = OcrEnsembleSarvamJudgeRequestSupport::sortedNullableStringMap($record->candidates);
        $normalized = OcrEnsembleSarvamJudgeRequestSupport::sortedNullableStringMap($record->normalized);

        $candidateValues = array_values(array_filter(
            array_merge(array_values($candidates), array_values($normalized)),
            static fn ($value) => is_string($value) && trim($value) !== ''
        ));

        return new SarvamJudgeRequestField(
            fieldName: $fieldKey,
            triggerReason: $triggerReason,
            resolvedValue: OcrEnsembleSarvamJudgeRequestSupport::stringOrNull($record->final),
            normalizedValue: OcrEnsembleSarvamJudgeRequestSupport::pickNormalizedValue($record),
            status: $record->status,
            source: $record->source,
            winningEngine: $record->winningEngine,
            confidence: $record->confidence,
            fieldReason: $record->reason,
            candidates: $candidates,
            normalized: $normalized,
            validator: [
                'passed' => (bool) ($record->validator['passed'] ?? false),
                'code' => (string) ($record->validator['code'] ?? ''),
                'detail' => isset($record->validator['detail']) && is_string($record->validator['detail'])
                    ? $record->validator['detail']
                    : null,
            ],
            ocrSnippets: OcrEnsembleSarvamJudgeRequestSupport::extractOcrSnippets(
                $fieldKey,
                $primaryOcrText,
                $candidateValues
            ),
            engineMetadata: OcrEnsembleSarvamJudgeRequestSupport::buildEngineMetadata(
                $record,
                $envelope->meta->enginesPresent
            ),
        );
    }
}
