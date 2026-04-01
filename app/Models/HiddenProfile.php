<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HiddenProfile extends Model
{
    protected $fillable = [
        'owner_profile_id',
        'hidden_profile_id',
    ];

    public function ownerProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'owner_profile_id');
    }

    public function hiddenProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'hidden_profile_id');
    }
}
