<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserEntitlement extends Model
{
    protected $table = 'user_entitlements';

    protected $fillable = [
        'user_id',
        'entitlement_key',
        'valid_until',
        'value_override',
        'revoked_at',
    ];

    protected $casts = [
        'valid_until' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}

