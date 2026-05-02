<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pincode extends Model
{
    protected $fillable = [
        'pincode',
        'place_id',
        'latitude',
        'longitude',
        'is_primary',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'is_primary' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'place_id');
    }
}

