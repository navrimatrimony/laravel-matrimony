<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchingBoostRule extends Model
{
    protected $fillable = [
        'boost_type',
        'value',
        'max_cap',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'value' => 'integer',
        'max_cap' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];
}
