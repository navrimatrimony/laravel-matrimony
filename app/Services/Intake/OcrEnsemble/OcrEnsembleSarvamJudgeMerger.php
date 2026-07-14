<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeMergerInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeMergeResult;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponse;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeResponseField;

/**
 * Phase 4e Sarvam judge merger — returns a new envelope; never mutates input or DB.
 */
final class OcrEnsembleSarvamJudgeMerger implements OcrEnsembleSarvamJudgeMergerInterface
{
    public function merge(
        FieldResolutionEnvelope $envelope,
        SarvamJudgeResponse $response,
    ): SarvamJudgeMergeResult {
        // Preserve object identity for unchanged field records (byte-for-byte toArray).
        $fields = $envelope->fields;
        $updatedFields = [];
        $skippedFields = [];

        if (! $response->ok || $response->fields === []) {
            return new SarvamJudgeMergeResult(
                envelope: new FieldResolutionEnvelope(meta: $envelope->meta, fields: $fields),
                changed: false,
                updatedFields: [],
                skippedFields: [
                    '_merge' => ! $response->ok ? 'response_not_ok' : 'empty_response',
                ],
            );
        }

        $judgements = $this->indexJudgements($response);

        foreach (OcrEnsemblePhase4Constants::TRIGGER_FIELDS as $fieldKey) {
            if (! isset($judgements[$fieldKey])) {
                continue;
            }

            $judgement = $judgements[$fieldKey];
            $current = $fields[$fieldKey] ?? null;
            if (! $current instanceof FieldResolutionFieldRecord) {
                $current = FieldResolutionFieldRecord::missingSkeleton('missing_from_envelope');
            }

            $sarvamValue = $this->stringOrNull($judgement->value);
            if ($sarvamValue === null) {
                $skippedFields[$fieldKey] = 'empty_sarvam_value';

                continue;
            }

            if (! $this->shouldAcceptSarvam($current, $judgement)) {
                $skippedFields[$fieldKey] = 'lower_or_equal_confidence';

                continue;
            }

            $fields[$fieldKey] = $this->buildMergedRecord($current, $judgement, $sarvamValue);
            $updatedFields[] = $fieldKey;
        }

        foreach ($judgements as $fieldKey => $_judgement) {
            if ($fieldKey === 'gender') {
                $skippedFields['gender'] = 'gender_never_modified';

                continue;
            }
            if (! in_array($fieldKey, OcrEnsemblePhase4Constants::TRIGGER_FIELDS, true)) {
                if (! isset($skippedFields[$fieldKey])) {
                    $skippedFields[$fieldKey] = 'non_trigger_field';
                }
            }
        }

        $changed = $updatedFields !== [];

        return new SarvamJudgeMergeResult(
            envelope: new FieldResolutionEnvelope(meta: $envelope->meta, fields: $fields),
            changed: $changed,
            updatedFields: $updatedFields,
            skippedFields: $skippedFields,
        );
    }

    /**
     * @return array<string, SarvamJudgeResponseField>
     */
    private function indexJudgements(SarvamJudgeResponse $response): array
    {
        $map = [];
        foreach ($response->fields as $field) {
            if ($field->fieldName === '') {
                continue;
            }
            // First occurrence wins for determinism.
            if (isset($map[$field->fieldName])) {
                continue;
            }
            $map[$field->fieldName] = $field;
        }

        return $map;
    }

    private function shouldAcceptSarvam(
        FieldResolutionFieldRecord $current,
        SarvamJudgeResponseField $judgement,
    ): bool {
        $phase3Confidence = $current->confidence;
        $sarvamConfidence = $judgement->confidence;

        if ($phase3Confidence !== null && $sarvamConfidence !== null) {
            return $sarvamConfidence > $phase3Confidence;
        }

        if ($phase3Confidence !== null && $sarvamConfidence === null) {
            return false;
        }

        if ($phase3Confidence === null && $sarvamConfidence !== null) {
            return true;
        }

        // Both null: only fill gaps (missing / unresolved / blank final).
        $final = $this->stringOrNull($current->final);
        if ($final === null) {
            return true;
        }

        return $current->status !== OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED;
    }

    private function buildMergedRecord(
        FieldResolutionFieldRecord $current,
        SarvamJudgeResponseField $judgement,
        string $sarvamValue,
    ): FieldResolutionFieldRecord {
        return new FieldResolutionFieldRecord(
            final: $sarvamValue,
            status: OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED,
            source: OcrEnsemblePhase4Constants::FIELD_SOURCE_SARVAM_JUDGE,
            winningEngine: OcrEnsemblePhase4Constants::ENGINE_SARVAM_JUDGE,
            confidence: $judgement->confidence,
            reason: OcrEnsemblePhase4Constants::MERGE_REASON,
            candidates: $current->candidates,
            normalized: $current->normalized,
            validator: [
                'passed' => true,
                'code' => OcrEnsemblePhase4Constants::VALIDATOR_CODE_SARVAM_JUDGE,
                'detail' => $judgement->reason,
            ],
            merge: [
                'previous_final' => $current->final,
                'previous_source' => $current->source,
                'previous_confidence' => $current->confidence,
                'previous_status' => $current->status,
                'previous_winning_engine' => $current->winningEngine,
                'previous_reason' => $current->reason,
                'previous_validator' => [
                    'passed' => (bool) ($current->validator['passed'] ?? false),
                    'code' => (string) ($current->validator['code'] ?? ''),
                    'detail' => isset($current->validator['detail']) && is_string($current->validator['detail'])
                        ? $current->validator['detail']
                        : null,
                ],
                'sarvam_confidence' => $judgement->confidence,
                'sarvam_reason' => $judgement->reason,
                'merged_by' => OcrEnsemblePhase4Constants::FIELD_SOURCE_SARVAM_JUDGE,
            ],
        );
    }

    private function stringOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
