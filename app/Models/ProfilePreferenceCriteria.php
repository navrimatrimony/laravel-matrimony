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
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}

