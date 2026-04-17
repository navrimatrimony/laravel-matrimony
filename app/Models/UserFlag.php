<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin / system risk flags (duplicate phone, no photo, etc.).
 * Scores aggregate into business risk views; rules also run live in {@see \App\Services\Admin\AdminDashboardMetricsService}.
 */
class UserFlag extends Model
{
    protected $table = 'user_flags';

    protected $fillable = [
        'user_id',
        'type',
        'score',
        'source',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
