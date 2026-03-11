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
        'rule_family_key',
        'rule_version',
        'supersedes_pattern_id',
        'retired_at',
        'retirement_reason',
        'authored_by_type',
        'authored_by_id',
        'promotion_status',
    ];

    protected $casts = [
        'pattern_confidence' => 'float',
        'usage_count' => 'integer',
        'is_active' => 'boolean',
        'rule_version' => 'integer',
        'retired_at' => 'datetime',
    ];

    /**
     * The prior pattern this rule supersedes (if any).
     */
    public function supersedes(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_pattern_id');
    }

    /**
     * Rules that supersede this one (if any).
     */
    public function supersededBy(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'supersedes_pattern_id');
    }
}