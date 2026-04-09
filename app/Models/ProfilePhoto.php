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
        'sort_order',
        'uploaded_via',
        'approved_status',
        'watermark_detected',
        'moderation_scan_json',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'watermark_detected' => 'boolean',
        'moderation_scan_json' => 'array',
    ];

    public function scopeOrdered($query)
    {
        return $query
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}

