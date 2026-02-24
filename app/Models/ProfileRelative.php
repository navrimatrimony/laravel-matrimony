<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Extended family / relatives. One row per relative; relation_type is a string (e.g. uncle, aunt, cousin).
 */
class ProfileRelative extends Model
{
    protected $table = 'profile_relatives';

    protected $fillable = [
        'profile_id',
        'relation_type',
        'name',
        'occupation',
        'city_id',
        'state_id',
        'contact_number',
        'notes',
        'is_primary_contact',
    ];

    protected $casts = [
        'is_primary_contact' => 'boolean',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function state()
    {
        return $this->belongsTo(State::class, 'state_id');
    }
}
