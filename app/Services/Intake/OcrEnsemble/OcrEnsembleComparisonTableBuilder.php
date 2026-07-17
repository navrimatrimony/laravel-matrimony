<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonTableBuilderInterface;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionEnvelope;
use App\Services\Intake\OcrEnsemble\Data\FieldResolutionFieldRecord;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAuditMeta;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonFieldRow;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonTable;

/**
 * Builds immutable comparison rows from EvidenceBundle (Phase 5c).
 *
 * Final/reason/status/source come from field_resolution_json.
 * Engine columns are gated by EvidenceBundle engine presence and read
 * candidates (and winning-engine fallback) from that same FR JSON.
 * No OCR / vote / validate / merge / persistence.
 */
final class OcrEnsembleComparisonTableBuilder implements OcrEnsembleComparisonTableBuilderInterface
{
    public function build(OcrComparisonEvidenceBundle $evidence): OcrComparisonTable
    {
        $envelope = $this->envelopeFromEvidence($evidence);
        $rows = [];

        foreach (OcrEnsemblePhase3Constants::STRUCTURED_FIELDS as $fieldKey) {
            $record = $envelope?->fields[$fieldKey] ?? FieldResolutionFieldRecord::missingSkeleton('missing_field_resolution');
            $rows[] = $this->buildRow($fieldKey, $record, $evidence);
        }

        return new OcrComparisonTable(
            columns: OcrEnsemblePhase5Constants::TABLE_COLUMNS,
            rows: $rows,
            audit: $this->buildAudit($evidence),
        );
    }

    private function envelopeFromEvidence(OcrComparisonEvidenceBundle $evidence): ?FieldResolutionEnvelope
    {
        if (! $evidence->hasFieldResolution()) {
            return null;
        }

        return FieldResolutionEnvelope::fromArray($evidence->fieldResolutionJson ?? []);
    }

    private function buildRow(
        string $fieldKey,
        FieldResolutionFieldRecord $record,
        OcrComparisonEvidenceBundle $evidence,
    ): OcrComparisonFieldRow {
        return new OcrComparisonFieldRow(
            fieldKey: $fieldKey,
            fieldLabel: OcrEnsemblePhase5Constants::fieldLabel($fieldKey),
            finalValue: $this->explicitString($record->final),
            tesseractValue: $this->engineColumnValue(
                present: $evidence->tesseract->present,
                record: $record,
                engineKeys: [
                    OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
                    OcrEnsemblePhase5Constants::COLUMN_TESSERACT,
                ],
            ),
            secondOcrValue: $this->engineColumnValue(
                present: $evidence->secondOcr->present,
                record: $record,
                engineKeys: [
                    OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
                    OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR,
                ],
                allowPrefix: 'second_ocr',
            ),
            sarvamValue: $this->engineColumnValue(
                present: $evidence->sarvam->present,
                record: $record,
                engineKeys: [
                    OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
                    OcrEnsemblePhase5Constants::COLUMN_SARVAM,
                ],
            ),
            reason: $this->explicitString($record->reason !== '' ? $record->reason : null),
            status: $this->explicitString($record->status),
            source: $this->explicitString($record->source),
            winningEngine: $this->explicitString($record->winningEngine),
        );
    }

    /**
     * @param  list<string>  $engineKeys
     */
    private function engineColumnValue(
        bool $present,
        FieldResolutionFieldRecord $record,
        array $engineKeys,
        ?string $allowPrefix = null,
    ): ?string {
        if (! $present) {
            return null;
        }

        $fromCandidates = $this->candidateFromKeys($record->candidates, $engineKeys, $allowPrefix);
        if ($fromCandidates !== null) {
            return $fromCandidates;
        }

        // Phase 4 merge writes Sarvam into final without always updating candidates.
        $winning = is_string($record->winningEngine) ? $record->winningEngine : null;
        if ($winning !== null && $this->winningMatches($winning, $engineKeys, $allowPrefix)) {
            return $this->explicitString($record->final);
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $candidates
     * @param  list<string>  $engineKeys
     */
    private function candidateFromKeys(array $candidates, array $engineKeys, ?string $allowPrefix): ?string
    {
        foreach ($engineKeys as $key) {
            if (! array_key_exists($key, $candidates)) {
                continue;
            }
            $value = $this->explicitString(is_string($candidates[$key]) ? $candidates[$key] : null);
            if ($value !== null) {
                return $value;
            }
        }

        if ($allowPrefix !== null) {
            foreach ($candidates as $key => $value) {
                if (! is_string($key) || ! str_starts_with($key, $allowPrefix)) {
                    continue;
                }
                $normalized = $this->explicitString(is_string($value) ? $value : null);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $engineKeys
     */
    private function winningMatches(string $winningEngine, array $engineKeys, ?string $allowPrefix): bool
    {
        if (in_array($winningEngine, $engineKeys, true)) {
            return true;
        }

        return $allowPrefix !== null && str_starts_with($winningEngine, $allowPrefix);
    }

    private function buildAudit(OcrComparisonEvidenceBundle $evidence): OcrComparisonAuditMeta
    {
        return new OcrComparisonAuditMeta(
            schemaVersion: OcrEnsemblePhase5Constants::SCHEMA_VERSION,
            pipelineVersion: OcrEnsemblePhase5Constants::PIPELINE_VERSION,
            intakeId: $evidence->intakeId,
            surface: OcrEnsemblePhase5Constants::SURFACE_CORRECT_CANDIDATE,
            ensembleRan: $evidence->hasFieldResolution(),
            hasFieldResolutionJson: $evidence->hasFieldResolution(),
            attemptCount: $evidence->attemptCount(),
            enginesPresent: array_values($evidence->enginesPresent),
            emptyState: $this->resolveEmptyState($evidence),
        );
    }

    private function resolveEmptyState(OcrComparisonEvidenceBundle $evidence): ?string
    {
        if ($evidence->hasFieldResolution()) {
            return null;
        }

        if ($evidence->attemptCount() === 0 && $evidence->enginesPresent === []) {
            return OcrEnsemblePhase5Constants::EMPTY_STATE_LEGACY_INTAKE;
        }

        return OcrEnsemblePhase5Constants::EMPTY_STATE_ENSEMBLE_NOT_RUN;
    }

    private function explicitString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
