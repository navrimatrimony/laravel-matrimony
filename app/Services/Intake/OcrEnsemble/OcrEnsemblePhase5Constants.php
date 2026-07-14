<?php

namespace App\Services\Intake\OcrEnsemble;

/**
 * Frozen Phase 5 OCR comparison constants (Blueprint §7 / Phase Contract §Phase 5).
 *
 * Read-only comparison surfaces. No write paths in Phase 5a.
 */
final class OcrEnsemblePhase5Constants
{
    public const PIPELINE_VERSION = 'phase5_v1';

    public const SCHEMA_VERSION = 'phase5_comparison_v1';

    /** Allowed UI placement (later steps); foundation records the contract only. */
    public const SURFACE_CORRECT_CANDIDATE = 'correct_candidate';

    public const COLUMN_FIELD = 'field';

    public const COLUMN_FINAL = 'final';

    public const COLUMN_TESSERACT = 'tesseract';

    public const COLUMN_SECOND_OCR = 'second_ocr';

    public const COLUMN_SARVAM = 'sarvam';

    public const COLUMN_REASON = 'reason';

    /**
     * Blueprint comparison columns in display order.
     *
     * @var list<string>
     */
    public const TABLE_COLUMNS = [
        self::COLUMN_FIELD,
        self::COLUMN_FINAL,
        self::COLUMN_TESSERACT,
        self::COLUMN_SECOND_OCR,
        self::COLUMN_SARVAM,
        self::COLUMN_REASON,
    ];

    /** Maps comparison columns to ocr_attempt / vote engine keys. */
    public const ENGINE_KEY_TESSERACT = OcrEnsemblePhase3Constants::ENGINE_LARAVEL_NATIVE_OCR;

    public const ENGINE_KEY_SECOND_OCR = OcrEnsemblePhase3Constants::ENGINE_SECOND_OCR;

    public const ENGINE_KEY_SARVAM = OcrEnsemblePhase3Constants::ENGINE_SARVAM_AI_VISION;

    public const EMPTY_STATE_ENSEMBLE_NOT_RUN = 'ensemble_not_run';

    public const EMPTY_STATE_LEGACY_INTAKE = 'legacy_intake';

    public const EMPTY_STATE_GATE_DISABLED = 'phase5_gate_disabled';
}
