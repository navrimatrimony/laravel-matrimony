<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Temporary master: Income ranges for dropdown.
 * Replace with exact Shaadi-style data later.
 */
class IncomeRange extends Model
{
    protected $table = 'income_ranges';

    protected $fillable = ['name', 'name_mr', 'slug', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
