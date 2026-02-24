<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5 SSOT: Master lookup for income currency. code (INR, USD, ...), symbol, is_default.
 */
class MasterIncomeCurrency extends Model
{
    protected $table = 'master_income_currencies';

    protected $fillable = ['code', 'symbol', 'is_default', 'is_active'];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
