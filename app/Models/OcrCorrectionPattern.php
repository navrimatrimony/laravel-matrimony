<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcrCorrectionPattern extends Model
{
    protected $table = 'ocr_correction_patterns';

    public $timestamps = true;

    protected $fillable = [
    'field_key',
    'wrong_pattern',
    'corrected_value',
    'pattern_confidence',
    'usage_count',
    'source',
    'is_active',
];

    protected $casts = [
        'pattern_confidence' => 'float',
        'usage_count' => 'integer',
        'is_active' => 'boolean',
    ];
}