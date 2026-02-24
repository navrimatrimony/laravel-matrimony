<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CityAlias extends Model
{
    protected $guarded = ['id'];

    protected $fillable = ['city_id', 'alias_name', 'normalized_alias', 'is_active'];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
