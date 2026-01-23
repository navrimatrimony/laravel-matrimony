<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| AbuseReport Model
|--------------------------------------------------------------------------
|
| 👉 Represents abuse reports submitted by users against profiles
| 👉 Admin can resolve reports with mandatory reasons
|
*/
class AbuseReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'reporter_user_id',
        'reported_profile_id',
        'reason',
        'status',
        'resolution_reason',
        'resolved_by_admin_id',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /**
     * User who submitted the report
     */
    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /**
     * Profile that was reported
     */
    public function reportedProfile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'reported_profile_id');
    }

    /**
     * Admin who resolved the report
     */
    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by_admin_id');
    }
}