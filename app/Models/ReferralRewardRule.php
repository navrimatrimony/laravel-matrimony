<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralRewardRule extends Model
{
    protected $fillable = [
        'plan_slug',
        'is_active',
        'bonus_days',
        'chat_send_limit_bonus',
        'contact_view_limit_bonus',
        'interest_send_limit_bonus',
        'daily_profile_view_limit_bonus',
        'who_viewed_me_preview_limit_bonus',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'bonus_days' => 'integer',
        'chat_send_limit_bonus' => 'integer',
        'contact_view_limit_bonus' => 'integer',
        'interest_send_limit_bonus' => 'integer',
        'daily_profile_view_limit_bonus' => 'integer',
        'who_viewed_me_preview_limit_bonus' => 'integer',
    ];
}
