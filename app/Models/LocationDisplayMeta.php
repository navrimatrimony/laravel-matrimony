<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional display overrides per {@see Location} row in {@code addresses} — see {@see \App\Services\Location\LocationDisplayFormatter}.
 */
class LocationDisplayMeta extends Model
{
    protected $table = 'location_display_meta';

    protected $guarded = ['id'];

    protected $fillable = [
        'location_id',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
