<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shaadi.com-style education hierarchy: Level 1 (Category).
 * e.g. Engineering, Arts / Design, Management.
 */
class EducationCategory extends Model
{
    protected $table = 'education_categories';

    protected $fillable = ['name', 'name_mr', 'slug', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function degrees()
    {
        return $this->hasMany(EducationDegree::class, 'category_id')->orderBy('sort_order');
    }
}
