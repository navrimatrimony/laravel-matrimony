<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasteAlias extends Model
{
    protected $table = 'master_caste_aliases';

    protected $fillable = [
        'caste_id',
        'alias',
        'alias_type',
        'normalized_alias',
    ];

    public function caste(): BelongsTo
    {
        return $this->belongsTo(Caste::class);
    }
}
