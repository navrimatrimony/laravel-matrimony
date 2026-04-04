<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional cache of computed matching results for a profile (see MatchingService).
 */
class ProfileMatch extends Model
{
    protected $table = 'profile_matches';

    protected $fillable = [
        'profile_id',
        'matched_profile_id',
        'score',
        'json_reasons',
    ];

    protected $casts = [
        'json_reasons' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    public function matchedProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'matched_profile_id');
    }
}
