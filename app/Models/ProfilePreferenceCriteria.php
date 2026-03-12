<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5B: Partner preference criteria (one row per profile).
 * Scalar fields only; multi-select preferences live in pivot tables.
 */
class ProfilePreferenceCriteria extends Model
{
    use HasFactory;

    protected $table = 'profile_preference_criteria';

    protected $fillable = [
        'profile_id',
        'preferred_age_min',
        'preferred_age_max',
        'preferred_income_min',
        'preferred_income_max',
        'preferred_education',
        'preferred_city_id',
        'willing_to_relocate',
        'settled_city_preference_id',
        'marriage_type_preference_id',
    ];

    protected $casts = [
        'willing_to_relocate' => 'boolean',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function settledCity()
    {
        return $this->belongsTo(\App\Models\City::class, 'settled_city_preference_id');
    }

    public function marriageTypePreference()
    {
        return $this->belongsTo(MasterMarriageTypePreference::class, 'marriage_type_preference_id');
    }
}

