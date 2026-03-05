<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfilePhoto extends Model
{
    protected $table = 'profile_photos';

    protected $fillable = [
        'profile_id',
        'file_path',
        'is_primary',
        'uploaded_via',
        'approved_status',
        'watermark_detected',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}

