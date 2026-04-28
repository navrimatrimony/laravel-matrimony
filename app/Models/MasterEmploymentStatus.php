<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5 SSOT: Master lookup for employment status (Full Time, Part Time, etc.).
 */
class MasterEmploymentStatus extends Model
{
    protected $table = 'master_employment_statuses';

    protected $fillable = ['name', 'name_mr', 'code', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
