<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Shaadi.com-style education hierarchy: Level 2 (Degree under a category).
 * Table {@code master_education} (formerly {@code education_degrees}).
 */
class EducationDegree extends Model
{
    protected $table = 'master_education';

    protected $fillable = ['category_id', 'code', 'code_mr', 'full_form', 'sort_order'];

    public function category()
    {
        return $this->belongsTo(EducationCategory::class, 'category_id');
    }

    /** Short label for lists / preference rows: Marathi code when locale MR and set, else English {@code code}. */
    public function shortDisplayLabel(): string
    {
        if (app()->getLocale() === 'mr' && filled($this->code_mr)) {
            return trim((string) $this->code_mr);
        }

        return trim((string) ($this->code ?? ''));
    }
}
