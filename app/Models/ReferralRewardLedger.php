<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralRewardLedger extends Model
{
    protected $fillable = [
        'user_referral_id',
        'referrer_id',
        'referred_user_id',
        'performed_by_admin_id',
        'action_type',
        'bonus_days',
        'feature_bonus',
        'reason',
        'meta',
    ];

    protected $casts = [
        'bonus_days' => 'integer',
        'feature_bonus' => 'array',
        'meta' => 'array',
    ];

    public function userReferral(): BelongsTo
    {
        return $this->belongsTo(UserReferral::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function performedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_admin_id');
    }
}

