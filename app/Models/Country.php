<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase-4 Day-8: Location Hierarchy - Country Model
 */
class Country extends Model
{
    protected $fillable = ['name'];

    /**
     * A country has many states.
     */
    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }
}
