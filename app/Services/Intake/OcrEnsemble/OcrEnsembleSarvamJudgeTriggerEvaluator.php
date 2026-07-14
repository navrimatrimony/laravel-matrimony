<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleSarvamJudgeTriggerEvaluatorInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\SarvamJudgeTriggerReport;

/**
 * Read-only Sarvam judge trigger evaluation (Blueprint §5.1).
 *
 * No HTTP, no Sarvam API, no merge, no persistence.
 */
final class OcrEnsembleSarvamJudgeTriggerEvaluator implements OcrEnsembleSarvamJudgeTriggerEvaluatorInterface
{
    public function evaluate(FieldResolutionEnvelope $envelope): SarvamJudgeTriggerReport
    {
        $triggeredFields = [];
        $unresolvedCount = 0;
        $conflictingCount = 0;

        foreach (OcrEnsemblePhase4Constants::TRIGGER_FIELDS as $fieldKey) {
            $record = $envelope->fields[$fieldKey] ?? null;

            if ($this->isUnresolved($record)) {
                $unresolvedCount++;
            }

            if ($this->requiresConflictJudgement($record)) {
                $conflictingCount++;
            }

            $reason = $this->triggerReasonForField($fieldKey, $record);
            if ($reason !== null) {
                $triggeredFields[$fieldKey] = $reason;
            }
        }

        if ($triggeredFields === []) {
            return new SarvamJudgeTriggerReport(
                shouldInvokeSarvam: false,
                triggeredFields: [],
                evaluatedTriggerFields: OcrEnsemblePhase4Constants::TRIGGER_FIELDS,
                skipReason: 'no_triggers',
                unresolvedCount: $unresolvedCount,
                conflictingCount: $conflictingCount,
                reasons: [],
            );
        }

        return new SarvamJudgeTriggerReport(
            shouldInvokeSarvam: true,
            triggeredFields: $triggeredFields,
            evaluatedTriggerFields: OcrEnsemblePhase4Constants::TRIGGER_FIELDS,
            skipReason: null,
            unresolvedCount: $unresolvedCount,
            conflictingCount: $conflictingCount,
            reasons: array_values($triggeredFields),
        );
    }

    private function triggerReasonForField(string $fieldKey, ?FieldResolutionFieldRecord $record): ?string
    {
        if ($record === null) {
            return $this->missingReason($fieldKey);
        }

        if ($this->requiresConflictJudgement($record)) {
            return $fieldKey === OcrEnsemblePhase4Constants::TRIGGER_FIELD_FULL_NAME
                ? OcrEnsemblePhase4Constants::TRIGGER_REASON_NAME_CONFLICT
                : $this->missingReason($fieldKey);
        }

        if ($this->isUnresolved($record)) {
            return $this->missingReason($fieldKey);
        }

        if (! $this->validatorPassed($record)) {
            return $this->missingReason($fieldKey);
        }

        // Blueprint §5.2 / §6.3: validators > confidence — resolved + validator pass = skip.
        // Optional min_confidence (if later configured) never overrides a passing validator.
        if ($this->isBelowConfiguredConfidenceThreshold($record)) {
            return $this->missingReason($fieldKey);
        }

        return null;
    }

    private function isUnresolved(?FieldResolutionFieldRecord $record): bool
    {
        if ($record === null) {
            return true;
        }

        if ($record->status !== OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED) {
            return true;
        }

        return $this->finalValue($record) === null;
    }

    /**
     * Conflicting candidates that still need AI judgement (Blueprint §5.1 name conflict).
     * Successfully validated/resolved fields are never conflict triggers.
     */
    private function requiresConflictJudgement(?FieldResolutionFieldRecord $record): bool
    {
        if ($record === null) {
            return false;
        }

        if ($this->isSuccessfullyResolved($record)) {
            return false;
        }

        if ($record->status === OcrEnsemblePhase3Constants::FIELD_STATUS_CONFLICT) {
            return true;
        }

        return $this->hasConflictingNormalizedCandidates($record);
    }

    private function isSuccessfullyResolved(?FieldResolutionFieldRecord $record): bool
    {
        if ($record === null) {
            return false;
        }

        return $record->status === OcrEnsemblePhase3Constants::FIELD_STATUS_RESOLVED
            && $this->finalValue($record) !== null
            && $this->validatorPassed($record);
    }

    private function hasConflictingNormalizedCandidates(FieldResolutionFieldRecord $record): bool
    {
        $distinct = [];
        foreach ($record->normalized as $value) {
            if (! is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $distinct[$trimmed] = true;
        }

        return count($distinct) >= 2;
    }

    private function validatorPassed(FieldResolutionFieldRecord $record): bool
    {
        return (bool) ($record->validator['passed'] ?? false);
    }

    private function finalValue(FieldResolutionFieldRecord $record): ?string
    {
        if ($record->final === null) {
            return null;
        }

        $trimmed = trim($record->final);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Confidence alone never fires when a min_confidence config key is absent.
     * Blueprint §6.3: validators beat confidence scores.
     */
    private function isBelowConfiguredConfidenceThreshold(FieldResolutionFieldRecord $record): bool
    {
        $threshold = config('ocr.ensemble.phase4.min_confidence');
        if (! is_numeric($threshold)) {
            return false;
        }

        // Passing validator already accepted this field (caller order); still respect
        // explicit threshold only when confidence is present and below the floor.
        if ($this->validatorPassed($record)) {
            return false;
        }

        if ($record->confidence === null) {
            return false;
        }

        return $record->confidence < (float) $threshold;
    }

    private function missingReason(string $fieldKey): string
    {
        return match ($fieldKey) {
            OcrEnsemblePhase4Constants::TRIGGER_FIELD_FULL_NAME => OcrEnsemblePhase4Constants::TRIGGER_REASON_NAME_CONFLICT,
            OcrEnsemblePhase4Constants::TRIGGER_FIELD_DATE_OF_BIRTH => OcrEnsemblePhase4Constants::TRIGGER_REASON_DOB_MISSING,
            OcrEnsemblePhase4Constants::TRIGGER_FIELD_PRIMARY_CONTACT_NUMBER => OcrEnsemblePhase4Constants::TRIGGER_REASON_MOBILE_MISSING,
            OcrEnsemblePhase4Constants::TRIGGER_FIELD_RELIGION => OcrEnsemblePhase4Constants::TRIGGER_REASON_RELIGION_MISSING,
            default => 'unresolved',
        };
    }
}
