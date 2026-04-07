<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    protected $fillable = [
        'plan_id',
        'key',
        'value',
    ];

    protected static function booted(): void
    {
        static::saved(function (PlanFeature $feature): void {
            Plan::forgetCachedPlanFeaturesByPlanId((int) $feature->plan_id);
        });

        static::deleted(function (PlanFeature $feature): void {
            Plan::forgetCachedPlanFeaturesByPlanId((int) $feature->plan_id);
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
