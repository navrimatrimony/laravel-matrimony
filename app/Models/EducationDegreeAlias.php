<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EducationDegreeAlias extends Model
{
    protected $table = 'education_degree_aliases';

    protected $fillable = [
        'education_degree_id',
        'alias',
        'normalized_alias',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function degree(): BelongsTo
    {
        return $this->belongsTo(EducationDegree::class, 'education_degree_id');
    }
}
