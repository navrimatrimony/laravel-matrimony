<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Structured siblings (Day 31 Part 2). Does not replace brothers_count / sisters_count.
 * relation_type: brother | sister. Optional spouse in profile_sibling_spouses.
 */
class ProfileSibling extends Model
{
    use SoftDeletes;

    protected $table = 'profile_siblings';

    protected $fillable = [
        'profile_id',
        'relation_type',
        'name',
        'gender',
        'marital_status',
        'occupation',
        'occupation_master_id',
        'occupation_custom_id',
        'city_id',
        'contact_number',
        'notes',
        'sort_order',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function occupationMaster()
    {
        return $this->belongsTo(OccupationMaster::class, 'occupation_master_id');
    }

    public function occupationCustom()
    {
        return $this->belongsTo(OccupationCustom::class, 'occupation_custom_id');
    }

    public function spouse()
    {
        return $this->hasOne(ProfileSiblingSpouse::class, 'profile_sibling_id');
    }
}
