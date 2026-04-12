<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMatchBehavior extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id',
        'target_profile_id',
        'action',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'target_profile_id');
    }
}
