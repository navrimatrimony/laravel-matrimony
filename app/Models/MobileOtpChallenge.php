<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileOtpChallenge extends Model
{
    protected $fillable = [
        'challenge_id',
        'mobile',
        'channel',
        'purpose',
        'otp_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'verified_at',
        'last_sent_at',
        'resend_available_at',
        'ip_address',
        'user_agent',
        'locale',
        'terms_version',
        'privacy_version',
        'whatsapp_alerts_opt_in',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'resend_available_at' => 'datetime',
        'whatsapp_alerts_opt_in' => 'boolean',
    ];
}
