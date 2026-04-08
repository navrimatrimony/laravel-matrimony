<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeatureConfig extends Model
{
    protected $fillable = [
        'plan_id',
        'feature_key',
        'is_enabled',
        'is_unlimited',
        'limit_total',
        'period',
        'daily_cap',
        'soft_limit_percent',
        'expiry_days',
        'extra_cost_per_action',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_unlimited' => 'boolean',
        'limit_total' => 'integer',
        'daily_cap' => 'integer',
        'soft_limit_percent' => 'integer',
        'expiry_days' => 'integer',
        'extra_cost_per_action' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
