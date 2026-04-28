<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupationMaster extends Model
{
    protected $table = 'occupation_master';

    protected $fillable = [
        'name',
        'name_mr',
        'normalized_name',
        'category_id',
        'sort_order',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(OccupationCategory::class, 'category_id');
    }
}
