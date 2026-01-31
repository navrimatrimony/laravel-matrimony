<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function resolvedByUser()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
