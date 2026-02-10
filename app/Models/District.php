<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase-4 Day-8: Location Hierarchy - District Model
 */
class District extends Model
{
    protected $fillable = ['state_id', 'name'];

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function talukas(): HasMany
    {
        return $this->hasMany(Taluka::class);
    }
}
