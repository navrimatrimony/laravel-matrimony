<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileBoost extends Model
{
    protected $fillable = [
        'user_id',
        'starts_at',
        'ends_at',
        'source',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<ProfileBoost>  $query
     * @return Builder<ProfileBoost>
     */
    public function scopeActiveAt(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }
}
