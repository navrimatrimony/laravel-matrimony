<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional display overrides per {@see City} — see {@see \App\Services\Location\LocationDisplayFormatter}.
 */
class CityDisplayMeta extends Model
{
    protected $table = 'city_display_meta';

    protected $guarded = ['id'];

    protected $fillable = [
        'city_id',
        'is_district_hq',
        'display_priority',
        'hide_state',
        'hide_country',
    ];

    protected function casts(): array
    {
        return [
            'is_district_hq' => 'boolean',
            'hide_state' => 'boolean',
            'hide_country' => 'boolean',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
