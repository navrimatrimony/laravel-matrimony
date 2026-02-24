<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileEducation extends Model
{
    protected $table = 'profile_education';

    protected $fillable = [
        'profile_id',
        'degree',
        'specialization',
        'university',
        'year_completed',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}
