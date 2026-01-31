<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileExtendedField extends Model
{
    protected $table = 'profile_extended_fields';

    protected $fillable = ['profile_id', 'field_key', 'field_value'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }
}
