<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchingField extends Model
{
    protected $fillable = [
        'field_key',
        'label',
        'type',
        'category',
        'is_active',
        'weight',
        'max_weight',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'weight' => 'integer',
        'max_weight' => 'integer',
    ];
}
