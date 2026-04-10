<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoModerationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'photo_id',
        'old_status',
        'new_status',
        'admin_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(ProfilePhoto::class, 'photo_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
