<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;

/**
 * Shaadi.com-style education hierarchy: Level 2 (Degree under a category).
 * Table {@code master_education} (formerly {@code education_degrees}).
 */
class EducationDegree extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_education';

    protected $fillable = ['category_id', 'code', 'code_mr', 'full_form', 'sort_order'];

    public function category()
    {
        return $this->belongsTo(EducationCategory::class, 'category_id');
    }

    /** Short label for lists / preference rows: Marathi code when locale MR and set, else English {@code code}. */
    public function shortDisplayLabel(): string
    {
        // Only `code` was translated on master_education; title_mr / full_form_mr were dropped.
        return $this->localizedText('code');
    }
}
