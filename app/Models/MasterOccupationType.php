<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5 SSOT: Master lookup for occupation type (Private Job, Business, Student, etc.).
 */
class MasterOccupationType extends Model
{
    protected $table = 'master_occupation_types';

    protected $fillable = ['name', 'name_mr', 'code', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
