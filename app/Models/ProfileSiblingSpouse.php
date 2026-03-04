<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Day 31 Part 2: Optional spouse for a profile sibling. One per sibling.
 */
class ProfileSiblingSpouse extends Model
{
    use SoftDeletes;

    protected $table = 'profile_sibling_spouses';

    protected $fillable = [
        'profile_sibling_id',
        'name',
        'occupation_title',
        'contact_number',
        'address_line',
        'city_id',
        'taluka_id',
        'district_id',
        'state_id',
    ];

    public function sibling()
    {
        return $this->belongsTo(ProfileSibling::class, 'profile_sibling_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
