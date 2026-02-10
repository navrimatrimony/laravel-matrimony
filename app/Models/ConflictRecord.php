<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase-3 Day-4: Conflict Record Model
 * Tracks data conflicts requiring resolution per Authority Order (Law 4).
 */
class ConflictRecord extends Model
{
    protected $table = 'conflict_records';

    protected $fillable = [
        'profile_id',
        'field_name',
        'field_type',
        'old_value',
        'new_value',
        'source',
        'detected_at',
        'resolution_status',
        'resolved_by',
        'resolved_at',
        'resolution_reason',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public $timestamps = true;

    /**
     * Relationship: ConflictRecord → MatrimonyProfile
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    /**
     * Relationship: ConflictRecord → User (resolver)
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
