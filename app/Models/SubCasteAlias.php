<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubCasteAlias extends Model
{
    protected $fillable = [
        'sub_caste_id',
        'alias',
        'alias_type',
        'normalized_alias',
    ];

    public function subCaste(): BelongsTo
    {
        return $this->belongsTo(SubCaste::class, 'sub_caste_id');
    }
}
