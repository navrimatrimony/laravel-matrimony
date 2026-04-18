<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupationMaster extends Model
{
    protected $table = 'occupation_master';

    protected $fillable = [
        'name',
        'normalized_name',
        'category_id',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(OccupationCategory::class, 'category_id');
    }
}
