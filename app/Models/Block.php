<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Block Model (SSOT Day-5 — Recovery-Day-R2)
|--------------------------------------------------------------------------
|
| Block = MatrimonyProfile → MatrimonyProfile. User IDs not used.
|
*/
class Block extends Model
{
    protected $fillable = [
        'blocker_profile_id',
        'blocked_profile_id',
    ];

    public function blockerProfile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'blocker_profile_id');
    }

    public function blockedProfile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'blocked_profile_id');
    }
}
