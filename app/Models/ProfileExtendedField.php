<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileExtendedField extends Model
{
    protected $table = 'profile_extended_fields';

    protected $fillable = [
        'profile_id',
        'field_key',
        'field_value',
    ];

    public function profile()
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}
