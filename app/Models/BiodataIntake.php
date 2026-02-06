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
    ];

    protected $casts = [
        //
    ];

    public function uploadedByUser()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'matrimony_profile_id');
    }
}
