<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileAddress extends Model
{
    protected $table = 'profile_addresses';

    protected $fillable = [
        'profile_id',
        'address_type_id',
        'village_id',
        'country_id',
        'state_id',
        'district_id',
        'taluka_id',
        'city_id',
        'postal_code',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function state()
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function taluka()
    {
        return $this->belongsTo(Taluka::class, 'taluka_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function village()
    {
        return $this->belongsTo(Village::class, 'village_id');
    }
}
