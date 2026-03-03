<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcrCorrectionLog extends Model
{
    protected $table = 'ocr_correction_logs';

    public $timestamps = false;

    protected $fillable = [
        'intake_id',
        'field_key',
        'original_value',
        'corrected_value',
        'ai_confidence_at_parse',
        'snapshot_schema_version',
        'created_at',
    ];
}