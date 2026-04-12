<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchingConfigVersion extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'config_snapshot',
        'changed_by',
        'note',
    ];

    protected $casts = [
        'config_snapshot' => 'array',
    ];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
