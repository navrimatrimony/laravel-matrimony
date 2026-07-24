<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;

/**
 * Shaadi.com-style education hierarchy: Level 1 (Category).
 * Table {@code master_education_categories} (formerly {@code education_categories}).
 */
class EducationCategory extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_education_categories';

    protected $fillable = ['name', 'name_mr', 'slug', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function degrees()
    {
        return $this->hasMany(EducationDegree::class, 'category_id')->orderBy('sort_order');
    }

    public function localizedName(): string
    {
        return $this->localizedText('name');
    }
}
