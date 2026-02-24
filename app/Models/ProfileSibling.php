<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Structured siblings. Does not replace brothers_count / sisters_count.
 */
class ProfileSibling extends Model
{
    protected $table = 'profile_siblings';

    protected $fillable = [
        'profile_id',
        'gender',
        'marital_status',
        'occupation',
        'city_id',
        'notes',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
