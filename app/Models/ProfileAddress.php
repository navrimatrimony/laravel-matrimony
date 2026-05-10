<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileAddress extends Model
{
    protected $table = 'profile_addresses';

    protected $fillable = [
        'profile_id',
        'address_scope',
        'address_type_id',
        'address_line',
        'location_id',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    /**
     * Leaf row in unified {@code addresses} (city, suburb, village, …).
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
