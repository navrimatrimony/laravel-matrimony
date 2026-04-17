<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional append-only activity stream (login, interest, chat, etc.).
 * Dashboard metrics prefer aggregate queries on domain tables when possible.
 */
class UserActivity extends Model
{
    public $timestamps = false;

    protected $table = 'user_activities';

    protected $fillable = [
        'user_id',
        'type',
        'meta',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
