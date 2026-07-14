<?php

namespace App\Services\Intake;

use App\Models\AdminSetting;

/**
 * Feature gate for OCR ensemble pipeline (Phase 1+).
 * Default off — zero behaviour change until explicitly enabled.
 */
class IntakeOcrEnsembleGate
{
    public const SETTING_KEY = 'intake_ocr_ensemble_enabled';

    public function isEnabled(): bool
    {
        return AdminSetting::getBool(self::SETTING_KEY, false);
    }

    /**
     * Phase 3 field resolution runs only when the ensemble flag is on and phase3 is enabled in config.
     */
    public function isPhase3Enabled(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return (bool) config('ocr.ensemble.phase3.enabled', true);
    }

    /**
     * Phase 4 Sarvam judge runs only when ensemble + Phase 3 + phase4 config are enabled.
     */
    public function isPhase4Enabled(): bool
    {
        if (! $this->isPhase3Enabled()) {
            return false;
        }

        return (bool) config('ocr.ensemble.phase4.enabled', true);
    }

    /**
     * Phase 5 comparison read-path runs when ensemble + phase5 config are enabled.
     * Does not require Phase 4 live calls (legacy/ensemble-not-run empty states still allowed later).
     */
    public function isPhase5Enabled(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return (bool) config('ocr.ensemble.phase5.enabled', true);
    }
}
