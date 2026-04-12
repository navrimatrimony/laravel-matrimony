<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records when a member dismisses a suggested match from /matches (one row per action).
 * {@see \App\Services\Matching\MatchingService} hides a candidate after three skips.
 */
class ProfileMatchTabSkip extends Model
{
    protected $table = 'profile_match_tab_skips';

    protected $fillable = [
        'observer_profile_id',
        'candidate_profile_id',
    ];

    public function observerProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'observer_profile_id');
    }

    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'candidate_profile_id');
    }
}
