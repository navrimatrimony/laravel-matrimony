<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5 SSOT: Master lookup for complexion.
 */
class MasterComplexion extends Model
{
    protected $table = 'master_complexions';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
