<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfilePhotoReport extends Model
{
    protected $fillable = [
        'reporter_user_id',
        'reported_profile_id',
        'profile_photo_id',
        'reason',
        'status',
        'resolution_reason',
        'resolved_by_admin_id',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reportedProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'reported_profile_id');
    }

    public function profilePhoto(): BelongsTo
    {
        return $this->belongsTo(ProfilePhoto::class, 'profile_photo_id');
    }

    public function resolvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_admin_id');
    }
}
