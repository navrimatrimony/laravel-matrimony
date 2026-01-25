<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Shortlist Model (SSOT Day-5 — Recovery-Day-R2)
|--------------------------------------------------------------------------
|
| Shortlist = MatrimonyProfile → MatrimonyProfile. User IDs not used.
| Private: only owner can view.
|
*/
class Shortlist extends Model
{
    protected $fillable = [
        'owner_profile_id',
        'shortlisted_profile_id',
    ];

    public function ownerProfile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'owner_profile_id');
    }

    public function shortlistedProfile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'shortlisted_profile_id');
    }
}
