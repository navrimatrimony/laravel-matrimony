<?php

namespace App\Models;

use App\Casts\MojibakeSafeUtf8String;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5 SSOT: Master lookup for highest education (SSC, HSC, Bachelor, Master, etc.).
 */
class MasterEducation extends Model
{
    protected $table = 'master_education';

    protected $fillable = ['name', 'name_mr', 'code', 'group', 'sort_order', 'is_active'];

    protected $casts = [
        'name' => MojibakeSafeUtf8String::class,
        'name_mr' => MojibakeSafeUtf8String::class,
        'is_active' => 'boolean',
    ];
}
