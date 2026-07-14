<?php

namespace App\Services\Intake\OcrEnsemble;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatchItem;

/**
 * Read-only OCR ensemble status badges for the Bulk Intake dense list (P5-B2).
 *
 * Derives compact labels from existing item_meta / intake columns / ocr_attempts only.
 * Does not write DB, run OCR/Phase pipelines, or rebuild comparison tables.
 */
final class OcrEnsembleBulkListBadgePresenter
{
    public const BADGE_OCR_COMPLETE = 'ocr_complete';

    public const BADGE_PHASE3_COMPLETE = 'phase3_complete';

    public const BADGE_SARVAM_REVIEWED = 'sarvam_reviewed';

    public const BADGE_COMPARISON_READY = 'comparison_ready';

    public const BADGE_AWAITING_REVIEW = 'awaiting_review';

    public const BADGE_LEGACY_PATH = 'legacy_path';

    public const BADGE_NO_OCR = 'no_ocr';

    /**
     * Fixed display order (deterministic).
     *
     * @var list<string>
     */
    public const DISPLAY_ORDER = [
        self::BADGE_OCR_COMPLETE,
        self::BADGE_PHASE3_COMPLETE,
        self::BADGE_SARVAM_REVIEWED,
        self::BADGE_COMPARISON_READY,
        self::BADGE_AWAITING_REVIEW,
        self::BADGE_LEGACY_PATH,
        self::BADGE_NO_OCR,
    ];

    /**
     * @return list<array{key: string, label: string, class: string}>
     */
    public function badgesForItem(BulkIntakeBatchItem $item): array
    {
        $active = $this->activeKeys($item);
        $badges = [];

        foreach (self::DISPLAY_ORDER as $key) {
            if (! in_array($key, $active, true)) {
                continue;
            }
            $badges[] = [
                'key' => $key,
                'label' => $this->label($key),
                'class' => $this->cssClass($key),
            ];
        }

        return $badges;
    }

    /**
     * @return list<string>
     */
    public function activeKeys(BulkIntakeBatchItem $item): array
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $ensembleStatus = isset($meta['ocr_ensemble_status']) && is_string($meta['ocr_ensemble_status'])
            ? $meta['ocr_ensemble_status']
            : null;

        $intake = $item->biodataIntake;
        $hasFieldResolution = $this->hasFieldResolution($intake);
        $hasSarvamEvidence = $this->hasSarvamEvidence($intake);
        $hasAnyOcrAttempt = $this->hasAnyOcrAttempt($intake);
        $hasEnsemblePath = $ensembleStatus !== null || $hasFieldResolution;

        $keys = [];

        if ($ensembleStatus === 'ocr_ready') {
            $keys[] = self::BADGE_OCR_COMPLETE;
        }

        if ($hasFieldResolution) {
            $keys[] = self::BADGE_PHASE3_COMPLETE;
            $keys[] = self::BADGE_COMPARISON_READY;
        }

        if ($hasSarvamEvidence) {
            $keys[] = self::BADGE_SARVAM_REVIEWED;
        }

        if ($ensembleStatus === 'ocr_ensemble_processing') {
            $keys[] = self::BADGE_AWAITING_REVIEW;
        } elseif ($ensembleStatus === 'ocr_ready' && ! $hasFieldResolution) {
            // Ensemble OCR finished but Phase 3 envelope not present yet.
            $keys[] = self::BADGE_AWAITING_REVIEW;
        }

        if (! $hasEnsemblePath) {
            if (! $intake instanceof BiodataIntake || (! $hasAnyOcrAttempt && trim((string) ($intake->raw_ocr_text ?? '')) === '')) {
                $keys[] = self::BADGE_NO_OCR;
            } else {
                $keys[] = self::BADGE_LEGACY_PATH;
            }
        }

        return array_values(array_unique($keys));
    }

    private function hasFieldResolution(?BiodataIntake $intake): bool
    {
        if (! $intake instanceof BiodataIntake) {
            return false;
        }

        $fr = $intake->field_resolution_json;

        return is_array($fr) && $fr !== [];
    }

    private function hasSarvamEvidence(?BiodataIntake $intake): bool
    {
        if (! $intake instanceof BiodataIntake) {
            return false;
        }

        if ($this->fieldResolutionHasSarvamSource($intake)) {
            return true;
        }

        if ($intake->relationLoaded('ocrAttempts')) {
            return $intake->ocrAttempts->contains(
                static fn (BiodataIntakeOcrAttempt $attempt): bool => $attempt->engine === BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION
                    && $attempt->status === BiodataIntakeOcrAttempt::STATUS_SUCCESS
            );
        }

        return BiodataIntakeOcrAttempt::query()
            ->where('intake_id', $intake->id)
            ->where('engine', BiodataIntakeOcrAttempt::ENGINE_SARVAM_AI_VISION)
            ->where('status', BiodataIntakeOcrAttempt::STATUS_SUCCESS)
            ->exists();
    }

    private function fieldResolutionHasSarvamSource(BiodataIntake $intake): bool
    {
        $fr = $intake->field_resolution_json;
        if (! is_array($fr)) {
            return false;
        }

        $fields = is_array($fr['fields'] ?? null) ? $fr['fields'] : [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $source = (string) ($field['source'] ?? '');
            if ($source === OcrEnsemblePhase4Constants::FIELD_SOURCE_SARVAM_JUDGE) {
                return true;
            }
            $winning = (string) ($field['winning_engine'] ?? '');
            if ($winning === OcrEnsemblePhase4Constants::ENGINE_SARVAM_JUDGE) {
                return true;
            }
            $merge = is_array($field['merge'] ?? null) ? $field['merge'] : null;
            if (is_array($merge) && (string) ($merge['merged_by'] ?? '') === OcrEnsemblePhase4Constants::FIELD_SOURCE_SARVAM_JUDGE) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyOcrAttempt(?BiodataIntake $intake): bool
    {
        if (! $intake instanceof BiodataIntake) {
            return false;
        }

        if ($intake->relationLoaded('ocrAttempts')) {
            return $intake->ocrAttempts->isNotEmpty();
        }

        return BiodataIntakeOcrAttempt::query()
            ->where('intake_id', $intake->id)
            ->exists();
    }

    private function label(string $key): string
    {
        return match ($key) {
            self::BADGE_OCR_COMPLETE => 'OCR Complete',
            self::BADGE_PHASE3_COMPLETE => 'Phase 3 Complete',
            self::BADGE_SARVAM_REVIEWED => 'Sarvam Reviewed',
            self::BADGE_COMPARISON_READY => 'Comparison Ready',
            self::BADGE_AWAITING_REVIEW => 'Awaiting Review',
            self::BADGE_LEGACY_PATH => 'Legacy Path',
            self::BADGE_NO_OCR => 'No OCR',
            default => $key,
        };
    }

    private function cssClass(string $key): string
    {
        return match ($key) {
            self::BADGE_OCR_COMPLETE => 'border-sky-300 bg-sky-50 text-sky-800',
            self::BADGE_PHASE3_COMPLETE => 'border-indigo-300 bg-indigo-50 text-indigo-800',
            self::BADGE_SARVAM_REVIEWED => 'border-violet-300 bg-violet-50 text-violet-800',
            self::BADGE_COMPARISON_READY => 'border-emerald-300 bg-emerald-50 text-emerald-800',
            self::BADGE_AWAITING_REVIEW => 'border-amber-300 bg-amber-50 text-amber-900',
            self::BADGE_LEGACY_PATH => 'border-gray-300 bg-gray-50 text-gray-700',
            self::BADGE_NO_OCR => 'border-slate-300 bg-slate-50 text-slate-700',
            default => 'border-gray-300 bg-white text-gray-700',
        };
    }
}
