<?php

namespace App\Services\Intake\OcrEnsemble;

/**
 * Frozen Phase 4 Sarvam judge constants (Blueprint §5, Phase Contract §Phase 4).
 */
final class OcrEnsemblePhase4Constants
{
    public const PIPELINE_VERSION = 'phase4_v1';

    public const SCHEMA_VERSION = 'phase4_judge_v1';

    public const ENGINE_SARVAM_JUDGE = 'sarvam_ai_vision';

    /** Fields that may trigger Sarvam when unresolved after Phase 3. */
    public const TRIGGER_FIELD_FULL_NAME = 'full_name';

    public const TRIGGER_FIELD_DATE_OF_BIRTH = 'date_of_birth';

    public const TRIGGER_FIELD_PRIMARY_CONTACT_NUMBER = 'primary_contact_number';

    public const TRIGGER_FIELD_RELIGION = 'religion';

    /** @var list<string> */
    public const TRIGGER_FIELDS = [
        self::TRIGGER_FIELD_FULL_NAME,
        self::TRIGGER_FIELD_DATE_OF_BIRTH,
        self::TRIGGER_FIELD_PRIMARY_CONTACT_NUMBER,
        self::TRIGGER_FIELD_RELIGION,
    ];

    public const TRIGGER_REASON_NAME_CONFLICT = 'name_conflict';

    public const TRIGGER_REASON_DOB_MISSING = 'dob_missing';

    public const TRIGGER_REASON_MOBILE_MISSING = 'mobile_missing';

    public const TRIGGER_REASON_RELIGION_MISSING = 'religion_missing';

    /**
     * Explicitly excluded from Sarvam triggers (Blueprint §5.1).
     *
     * @var list<string>
     */
    public const NON_TRIGGER_FIELDS = [
        'gender',
        'income',
        'marital_status',
    ];
}
