<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReligionAlias extends Model
{
    protected $fillable = [
        'religion_id',
        'alias',
        'alias_type',
        'normalized_alias',
    ];

    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }
}
