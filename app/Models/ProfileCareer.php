<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileCareer extends Model
{
    protected $table = 'profile_career';

    protected $fillable = [
        'profile_id',
        'designation',
        'company',
        'location',
        'start_year',
        'end_year',
        'is_current',
    ];

    protected $casts = [
        'is_current' => 'boolean',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}
