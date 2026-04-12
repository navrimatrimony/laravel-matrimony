<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchingEngineConfig extends Model
{
    protected $fillable = [
        'config_key',
        'config_value',
        'is_active',
        'version',
        'created_by',
    ];

    protected $casts = [
        'config_value' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
