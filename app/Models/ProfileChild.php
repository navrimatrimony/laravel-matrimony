<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileChild extends Model
{
    protected $table = 'profile_children';

    protected $fillable = [
        'profile_id',
        'child_name',
        'gender',
        'age',
        'child_living_with_id',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}
