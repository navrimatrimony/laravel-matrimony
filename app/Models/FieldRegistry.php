<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldRegistry extends Model
{
    protected $table = 'field_registry';

    protected $guarded = [];

    /** Day 10: dependency_condition stored as JSON { type: 'equals'|'present', value?: string }. Cast only, no logic. */
    protected $casts = [
        'dependency_condition' => 'array',
    ];
}
