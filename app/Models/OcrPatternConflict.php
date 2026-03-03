<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcrPatternConflict extends Model
{
    protected $table = 'ocr_pattern_conflicts';

    public $timestamps = true;

    protected $fillable = [
        'field_key',
        'wrong_pattern',
        'existing_corrected_value',
        'proposed_corrected_value',
        'observation_count',
    ];

    protected $casts = [
        'observation_count' => 'integer',
    ];
}
