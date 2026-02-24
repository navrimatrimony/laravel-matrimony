<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase-4: Raw biodata storage. Read-only usage for audit and re-verification.
 * NO delete / forceDelete. Retention per policy.
 */
class BiodataIntake extends Model
{
    protected $table = 'biodata_intakes';

    /** Phase-4 M3: DRAFT on create; ATTACHED when linked to profile; ARCHIVED admin-only. */
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_ATTACHED = 'ATTACHED';
    public const STATUS_ARCHIVED = 'ARCHIVED';

    protected $fillable = [
        'file_path',
        'original_filename',
        'file_type',
        'raw_ocr_text',
        'uploaded_by',
        'ocr_mode',
        'matrimony_profile_id',
        'intake_status',
        'parse_status',
        'parsed_json',
        'approved_by_user',
        'approved_at',
        'approval_snapshot_json',
        'snapshot_schema_version',
        'intake_locked',
    ];

    protected $casts = [
        'approved_by_user' => 'boolean',
        'intake_locked' => 'boolean',
        'parsed_json' => 'array',
        'approval_snapshot_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::deleting(function (BiodataIntake $model): void {
            throw new \RuntimeException('Biodata intake records are immutable and cannot be deleted.');
        });

        static::updating(function (BiodataIntake $model): void {
            // Phase-5 Day-18 SSOT: raw_ocr_text MUST NEVER be modified after intake creation.
            if ($model->isDirty('raw_ocr_text')) {
                throw new \RuntimeException('raw_ocr_text is immutable and cannot be changed.');
            }
            if (
                $model->getOriginal('intake_locked') === true
                && ! $model->isDirty('intake_locked')
            ) {
                throw new \RuntimeException('Locked biodata intake cannot be updated.');
            }
            if ($model->getOriginal('approved_by_user') && $model->isDirty('approval_snapshot_json')) {
                throw new \RuntimeException('approval_snapshot_json cannot be changed after approval.');
            }
        });
    }

    public function uploadedByUser()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'matrimony_profile_id');
    }
}
