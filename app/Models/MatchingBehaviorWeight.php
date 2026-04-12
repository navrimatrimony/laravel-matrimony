<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchingBehaviorWeight extends Model
{
    protected $fillable = [
        'action',
        'weight',
        'decay_days',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'integer',
        'decay_days' => 'integer',
        'is_active' => 'boolean',
    ];
}
