<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Temporary master: Colleges (College Attended).
 * Replace with full list later.
 */
class College extends Model
{
    protected $table = 'colleges';

    protected $fillable = ['name', 'slug', 'city', 'state', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
