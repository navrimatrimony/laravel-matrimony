<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Alliance & native network — surname + location, separate from profile_relatives.
 */
class ProfileAllianceNetwork extends Model
{
    protected $table = 'profile_alliance_networks';

    protected $fillable = [
        'profile_id',
        'surname',
        'city_id',
        'taluka_id',
        'district_id',
        'state_id',
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

    public function taluka()
    {
        return $this->belongsTo(Taluka::class, 'taluka_id');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function state()
    {
        return $this->belongsTo(State::class, 'state_id');
    }
}
