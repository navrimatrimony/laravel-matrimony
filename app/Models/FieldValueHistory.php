<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase-3 Day 8: Immutable field value change history.
 * Append-only; no update/delete methods. Read-only for UI.
 */
class FieldValueHistory extends Model
{
    protected $table = 'field_value_history';

    protected $fillable = [
        'profile_id',
        'field_key',
        'field_type',
        'old_value',
        'new_value',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}
