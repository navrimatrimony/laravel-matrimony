<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| ProfileView Model (SSOT Day-9 — Recovery-Day-R4)
|--------------------------------------------------------------------------
|
| Tracks profile views: viewer_profile_id → viewed_profile_id.
| Used for real→real, real→demo, demo→real (view-back).
|
*/
class ProfileView extends Model
{
    protected $fillable = [
        'viewer_profile_id',
        'viewed_profile_id',
    ];

    public function viewerProfile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'viewer_profile_id');
    }

    public function viewedProfile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'viewed_profile_id');
    }
}
