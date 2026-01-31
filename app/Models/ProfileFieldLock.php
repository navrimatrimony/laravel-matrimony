<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileFieldLock extends Model
{
    protected $table = 'profile_field_locks';

    protected $fillable = ['profile_id', 'field_key', 'field_type', 'locked_by', 'locked_at'];

    protected $casts = ['locked_at' => 'datetime'];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function lockedByUser()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
