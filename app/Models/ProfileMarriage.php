<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileMarriage extends Model
{
    protected $table = 'profile_marriages';

    protected $fillable = [
        'profile_id',
        'marital_status_id',
        'marriage_year',
        'separation_year',
        'divorce_year',
        'spouse_death_year',
        'divorce_status',
        'remarriage_reason',
        'notes',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function maritalStatus()
    {
        return $this->belongsTo(MasterMaritalStatus::class, 'marital_status_id');
    }
}