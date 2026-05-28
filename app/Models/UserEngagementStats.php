<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEngagementStats extends Model
{
    protected $table = 'user_engagement_stats';

    protected $fillable = [
        'user_id',
        'ads_viewed_count',
        'referrals_done',
        'profiles_completed',
        'daily_login_streak',
        'unlock_credits_available',
    ];

    protected $casts = [
        'ads_viewed_count' => 'integer',
        'referrals_done' => 'integer',
        'profiles_completed' => 'integer',
        'daily_login_streak' => 'integer',
        'unlock_credits_available' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
