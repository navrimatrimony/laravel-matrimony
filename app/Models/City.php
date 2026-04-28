<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Phase-4 Day-8: Location Hierarchy - City Model
 */
class City extends Model
{
    protected $fillable = ['taluka_id', 'parent_city_id', 'name', 'name_mr', 'pincode', 'population'];

    public function taluka(): BelongsTo
    {
        return $this->belongsTo(Taluka::class);
    }

    public function parentCity(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_city_id');
    }

    public function displayMeta(): HasOne
    {
        return $this->hasOne(CityDisplayMeta::class);
    }
}
