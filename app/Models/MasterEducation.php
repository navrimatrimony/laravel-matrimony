<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5 SSOT: Master lookup for highest education (SSC, HSC, Bachelor, Master, etc.).
 */
class MasterEducation extends Model
{
    protected $table = 'master_education';

    protected $fillable = ['name', 'code', 'group', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
