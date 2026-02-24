<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileHoroscopeData extends Model
{
    protected $table = 'profile_horoscope_data';

    protected $fillable = [
        'profile_id',
        'rashi_id',
        'nakshatra_id',
        'charan',
        'gan_id',
        'nadi_id',
        'mangal_dosh_type_id',
        'yoni_id',
        'devak',
        'kul',
        'gotra',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function rashi()
    {
        return $this->belongsTo(MasterRashi::class, 'rashi_id');
    }

    public function nakshatra()
    {
        return $this->belongsTo(MasterNakshatra::class, 'nakshatra_id');
    }

    public function gan()
    {
        return $this->belongsTo(MasterGan::class, 'gan_id');
    }

    public function nadi()
    {
        return $this->belongsTo(MasterNadi::class, 'nadi_id');
    }

    public function mangalDoshType()
    {
        return $this->belongsTo(MasterMangalDoshType::class, 'mangal_dosh_type_id');
    }

    public function yoni()
    {
        return $this->belongsTo(MasterYoni::class, 'yoni_id');
    }
}
