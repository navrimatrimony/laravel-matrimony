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
}
