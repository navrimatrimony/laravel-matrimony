<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Services\Intake\OcrEnsemble\Contracts\OcrEnsembleComparisonEvidenceLoaderInterface;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonAttemptSummary;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEngineEvidence;
use App\Services\Intake\OcrEnsemble\Data\OcrComparisonEvidenceBundle;
use Illuminate\Support\Collection;

/**
 * Loads immutable OCR comparison evidence for one intake (Phase 5b).
 *
 * Read-only: field_resolution_json + ocr_attempts. No row/table/judge logic.
 */
final class OcrEnsembleComparisonEvidenceLoader implements OcrEnsembleComparisonEvidenceLoaderInterface
{
    public function loadForIntake(BiodataIntake $intake): OcrComparisonEvidenceBundle
    {
        $intakeId = (int) ($intake->id ?? 0);
        if ($intakeId <= 0) {
            return OcrComparisonEvidenceBundle::empty(0);
        }

        $fieldResolutionJson = $this->copyFieldResolutionJson($intake);
        $attempts = $this->loadAttempts($intake, $intakeId);

        $summaries = [];
        foreach ($attempts as $attempt) {
            $summaries[] = $this->summarizeAttempt($attempt);
        }

        $tesseractAttempt = $this->selectAttemptForEngine(
            $attempts,
            static fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->engine === OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            preferPrimary: true,
        );
        $secondOcrAttempt = $this->selectAttemptForEngine(
            $attempts,
            static fn (BiodataIntakeOcrAttempt $attempt): bool => self::isSecondOcrEngine((string) $attempt->engine),
            preferPrimary: false,
        );
        $sarvamAttempt = $this->selectAttemptForEngine(
            $attempts,
            static fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->engine === OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
            preferPrimary: false,
        );

        $tesseract = $this->engineEvidence(
            OcrEnsemblePhase5Constants::ENGINE_KEY_TESSERACT,
            OcrEnsemblePhase5Constants::COLUMN_TESSERACT,
            $tesseractAttempt,
        );
        $secondOcr = $this->engineEvidence(
            OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR,
            OcrEnsemblePhase5Constants::COLUMN_SECOND_OCR,
            $secondOcrAttempt,
        );
        $sarvam = $this->engineEvidence(
            OcrEnsemblePhase5Constants::ENGINE_KEY_SARVAM,
            OcrEnsemblePhase5Constants::COLUMN_SARVAM,
            $sarvamAttempt,
        );

        $enginesPresent = [];
        foreach ([$tesseract, $secondOcr, $sarvam] as $engineEvidence) {
            if ($engineEvidence->present) {
                $enginesPresent[] = $engineEvidence->engineKey;
            }
        }

        $primaryModel = $attempts->first(
            static fn (BiodataIntakeOcrAttempt $attempt): bool => (bool) $attempt->is_primary
        );
        $primarySummary = $primaryModel instanceof BiodataIntakeOcrAttempt
            ? $this->summarizeAttempt($primaryModel)
            : null;

        return new OcrComparisonEvidenceBundle(
            intakeId: $intakeId,
            fieldResolutionJson: $fieldResolutionJson,
            attemptSummaries: $summaries,
            enginesPresent: $enginesPresent,
            tesseract: $tesseract,
            secondOcr: $secondOcr,
            sarvam: $sarvam,
            primaryAttempt: $primarySummary,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function copyFieldResolutionJson(BiodataIntake $intake): ?array
    {
        $raw = $intake->field_resolution_json;
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        // Deep copy so callers cannot mutate the Eloquent cast array by reference.
        $encoded = json_encode($raw);
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return Collection<int, BiodataIntakeOcrAttempt>
     */
    private function loadAttempts(BiodataIntake $intake, int $intakeId): Collection
    {
        if ($intake->relationLoaded('ocrAttempts')) {
            return $intake->ocrAttempts
                ->sortBy('id')
                ->values();
        }

        return BiodataIntakeOcrAttempt::query()
            ->where('intake_id', $intakeId)
            ->orderBy('id')
            ->get();
    }

    private function summarizeAttempt(BiodataIntakeOcrAttempt $attempt): OcrComparisonAttemptSummary
    {
        $rawText = $attempt->raw_text;
        $engineMeta = $attempt->engine_meta_json;

        return new OcrComparisonAttemptSummary(
            attemptId: (int) $attempt->id,
            engine: (string) $attempt->engine,
            source: is_string($attempt->source) ? $attempt->source : null,
            status: (string) $attempt->status,
            isPrimary: (bool) $attempt->is_primary,
            rawText: is_string($rawText) ? $rawText : null,
            engineMetaJson: is_array($engineMeta) ? $engineMeta : null,
            qualityScore: $attempt->quality_score !== null ? (float) $attempt->quality_score : null,
            durationMs: $attempt->duration_ms !== null ? (int) $attempt->duration_ms : null,
            selectedReason: is_string($attempt->selected_reason) ? $attempt->selected_reason : null,
            preprocessingVersion: is_string($attempt->preprocessing_version) ? $attempt->preprocessing_version : null,
            promptVersion: is_string($attempt->prompt_version) ? $attempt->prompt_version : null,
            parserVersion: is_string($attempt->parser_version) ? $attempt->parser_version : null,
        );
    }

    /**
     * @param  Collection<int, BiodataIntakeOcrAttempt>  $attempts
     * @param  callable(BiodataIntakeOcrAttempt): bool  $matcher
     */
    private function selectAttemptForEngine(Collection $attempts, callable $matcher, bool $preferPrimary): ?BiodataIntakeOcrAttempt
    {
        $matched = $attempts->filter($matcher)->values();
        if ($matched->isEmpty()) {
            return null;
        }

        if ($preferPrimary) {
            $primary = $matched->first(
                static fn (BiodataIntakeOcrAttempt $attempt): bool => (bool) $attempt->is_primary
            );
            if ($primary instanceof BiodataIntakeOcrAttempt) {
                return $primary;
            }
        }

        $success = $matched
            ->filter(static fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->status === BiodataIntakeOcrAttempt::STATUS_SUCCESS)
            ->sortByDesc('id')
            ->first();
        if ($success instanceof BiodataIntakeOcrAttempt) {
            return $success;
        }

        return $matched->sortByDesc('id')->first();
    }

    private function engineEvidence(
        string $engineKey,
        string $comparisonColumn,
        ?BiodataIntakeOcrAttempt $attempt,
    ): OcrComparisonEngineEvidence {
        if (! $attempt instanceof BiodataIntakeOcrAttempt) {
            return OcrComparisonEngineEvidence::empty($engineKey, $comparisonColumn);
        }

        return OcrComparisonEngineEvidence::fromAttempt(
            $engineKey,
            $comparisonColumn,
            $this->summarizeAttempt($attempt),
        );
    }

    private static function isSecondOcrEngine(string $engine): bool
    {
        if ($engine === OcrEnsemblePhase5Constants::ENGINE_KEY_SECOND_OCR) {
            return true;
        }

        // Blueprint allows future constants like second_ocr_*; keep prefix match.
        return str_starts_with($engine, 'second_ocr');
    }
}
