<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Temporary master: Working With (Private Company, Government, etc.).
 * Replace with exact Shaadi-style data later.
 */
class WorkingWithType extends Model
{
    protected $table = 'working_with_types';

    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function professions()
    {
        return $this->hasMany(Profession::class, 'working_with_type_id')->orderBy('sort_order');
    }
}
