<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase-4 Day-8: Location Hierarchy - City Model
 */
class City extends Model
{
    protected $fillable = ['taluka_id', 'name'];

    public function taluka(): BelongsTo
    {
        return $this->belongsTo(Taluka::class);
    }
}
