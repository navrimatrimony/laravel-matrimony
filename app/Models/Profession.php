<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Temporary master: Professions (Working As).
 * Replace with exact Shaadi-style data later.
 */
class Profession extends Model
{
    protected $table = 'professions';

    protected $fillable = ['working_with_type_id', 'name', 'name_mr', 'slug', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function workingWithType()
    {
        return $this->belongsTo(WorkingWithType::class, 'working_with_type_id');
    }
}
