<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase-3 Day-6: Per-profile field lock. Canonical table: profile_field_locks (LAW 24).
 */
class ProfileFieldLock extends Model
{
    protected $table = 'profile_field_locks';

    protected $guarded = [];
}
