<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Extended family / relatives. One row per relative; relation_type is a string (e.g. uncle, aunt, cousin).
 */
class ProfileRelative extends Model
{
    protected $table = 'profile_relatives';

    protected $fillable = [
        'profile_id',
        'relation_type',
        'relative_details',
        'contact_number',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}
