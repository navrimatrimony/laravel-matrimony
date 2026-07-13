<?php

namespace App\Services\Intake\OcrEnsemble;

/**
 * Frozen Phase 3 field-resolution schema constants (Blueprint §8.2, Phase Contract §16 fields).
 */
final class OcrEnsemblePhase3Constants
{
    public const SCHEMA_VERSION = 'phase3_v1';

    public const PIPELINE_VERSION = 'phase3_v1';

    public const ASSEMBLY_VERSION = 'parse_input_v1';

    public const VOTE_MODE_SINGLE_ENGINE_PASS_THROUGH = 'single_engine_pass_through';

    public const VOTE_MODE_MULTI_ENGINE = 'multi_engine';

    public const FIELD_STATUS_RESOLVED = 'resolved';

    public const FIELD_STATUS_MISSING = 'missing';

    /** Reserved for Phase 4+ multi-source; must not be written in single-engine Phase 3. */
    public const FIELD_STATUS_CONFLICT = 'conflict';

    public const FIELD_SOURCE_VOTE = 'vote';

    public const FIELD_SOURCE_VALIDATOR = 'validator';

    public const FIELD_SOURCE_SINGLE_ENGINE = 'single_engine';

    public const FIELD_SOURCE_MISSING = 'missing';

    public const ENGINE_LARAVEL_NATIVE_OCR = 'laravel_native_ocr';

    public const ENGINE_SECOND_OCR = 'second_ocr';

    public const ENGINE_SARVAM_AI_VISION = 'sarvam_ai_vision';

    /** @var list<string> */
    public const STRUCTURED_FIELDS = [
        'full_name',
        'date_of_birth',
        'gender',
        'primary_contact_number',
        'height',
        'education',
        'occupation',
        'income',
        'religion',
        'caste',
        'sub_caste',
        'state',
        'district',
        'taluka',
        'village',
        'marital_status',
    ];

    /** @var list<string> */
    public const CRITICAL_FIELDS = [
        'full_name',
        'date_of_birth',
        'primary_contact_number',
        'religion',
        'gender',
    ];
}
