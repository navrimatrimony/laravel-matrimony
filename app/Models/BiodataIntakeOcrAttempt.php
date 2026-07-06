<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiodataIntakeOcrAttempt extends Model
{
    public const ENGINE_ML_KIT_FLUTTER = 'ml_kit_flutter';

    public const ENGINE_LARAVEL_NATIVE_OCR = 'laravel_native_ocr';

    public const ENGINE_SARVAM_AI_VISION = 'sarvam_ai_vision';

    public const ENGINE_OPENAI_AI_VISION = 'openai_ai_vision';

    public const ENGINE_REUSED_TRANSCRIPT = 'reused_transcript';

    public const ENGINE_MANUAL_TRANSCRIPT = 'manual_transcript';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_PROFILE_USER = 'profile_user';

    public const ACTOR_SUCHAK = 'suchak';

    public const ACTOR_SYSTEM = 'system';

    public const SURFACE_MOBILE_APP = 'mobile_app';

    public const SURFACE_WEBSITE = 'website';

    public const SURFACE_ADMIN_PANEL = 'admin_panel';

    public const SURFACE_SERVER = 'server';

    public const SURFACE_API = 'api';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const FAILURE_UNREADABLE_IMAGE = 'unreadable_image';

    public const FAILURE_TWO_COLUMN_ORDER_ISSUE = 'two_column_order_issue';

    public const FAILURE_LABEL_VALUE_SPLIT = 'label_value_split';

    public const FAILURE_MARATHI_DIGIT_NORMALIZATION_ISSUE = 'marathi_digit_normalization_issue';

    public const FAILURE_PARSER_NO_FIELDS = 'parser_no_fields';

    public const FAILURE_TEXT_FOUND_MAPPING_FAILED = 'text_found_mapping_failed';

    public const FAILURE_PROVIDER_TIMEOUT = 'provider_timeout';

    public const FAILURE_PROVIDER_ERROR = 'provider_error';

    public const FAILURE_EMPTY_TEXT = 'empty_text';

    public const FAILURE_UNKNOWN = 'unknown';

    protected $table = 'biodata_intake_ocr_attempts';

    protected $fillable = [
        'intake_id',
        'engine',
        'source',
        'created_by_user_id',
        'created_by_actor_type',
        'source_surface',
        'status',
        'raw_text',
        'normalized_text',
        'text_hash',
        'normalized_text_hash',
        'image_hash',
        'perceptual_hash',
        'quality_score',
        'layout_score',
        'field_scores_json',
        'failure_code',
        'failure_message',
        'raw_blocks_json',
        'raw_lines_json',
        'layout_meta_json',
        'engine_meta_json',
        'parser_version',
        'prompt_version',
        'preprocessing_version',
        'selection_policy_version',
        'duration_ms',
        'cost_units',
        'provider_request_id',
        'provider_response_id',
        'is_primary',
        'selected_by',
        'selected_by_user_id',
        'selected_by_actor_type',
        'selected_at',
        'selected_policy',
        'selected_reason',
        'previous_primary_attempt_id',
    ];

    protected $casts = [
        'field_scores_json' => 'array',
        'raw_blocks_json' => 'array',
        'raw_lines_json' => 'array',
        'layout_meta_json' => 'array',
        'engine_meta_json' => 'array',
        'quality_score' => 'float',
        'layout_score' => 'float',
        'cost_units' => 'float',
        'is_primary' => 'boolean',
        'selected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (BiodataIntakeOcrAttempt $model): void {
            $mutableSelectionColumns = [
                'is_primary',
                'selected_by',
                'selected_by_user_id',
                'selected_by_actor_type',
                'selected_at',
                'selected_policy',
                'selected_reason',
                'previous_primary_attempt_id',
                'updated_at',
            ];

            $immutableChanges = array_diff(array_keys($model->getDirty()), $mutableSelectionColumns);
            if ($immutableChanges !== []) {
                throw new \RuntimeException('OCR attempt evidence is append-only; only primary selection metadata can be changed.');
            }
        });

        static::deleting(function (): void {
            throw new \RuntimeException('OCR attempt records are append-only and cannot be deleted.');
        });
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(BiodataIntake::class, 'intake_id');
    }
}
