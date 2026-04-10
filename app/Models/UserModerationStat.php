<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserModerationStat extends Model
{
    protected $fillable = [
        'user_id',
        'total_uploads',
        'total_approved',
        'total_rejected',
        'total_review',
        'last_upload_at',
        'risk_score',
        'is_flagged',
    ];

    protected $casts = [
        'last_upload_at' => 'datetime',
        'risk_score' => 'float',
        'is_flagged' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
