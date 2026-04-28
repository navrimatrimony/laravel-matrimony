<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shaadi.com-style education hierarchy: Level 2 (Degree under a category).
 * e.g. B.E / B.Tech, MBA, with full_form as description.
 */
class EducationDegree extends Model
{
    protected $table = 'education_degrees';

    protected $fillable = ['category_id', 'code', 'code_mr', 'title', 'title_mr', 'full_form', 'full_form_mr', 'sort_order'];

    public function category()
    {
        return $this->belongsTo(EducationCategory::class, 'category_id');
    }
}
