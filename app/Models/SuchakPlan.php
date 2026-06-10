<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SuchakPlan extends Model
{
    use HasFactory;

    protected $table = 'suchak_plans';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_amount',
        'currency',
        'billing_period_days',
        'is_active',
        'is_visible',
        'sort_order',
    ];

    protected $casts = [
        'price_amount' => 'decimal:2',
        'billing_period_days' => 'integer',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(SuchakPlanFeature::class);
    }

    public function enabledFeatures(): HasMany
    {
        return $this->features()->where('is_enabled', true);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SuchakSubscription::class);
    }

    public function planPayments(): HasMany
    {
        return $this->hasMany(SuchakPlanPayment::class);
    }

    public function hasConfiguredPrice(): bool
    {
        return $this->price_amount !== null && $this->currency !== null;
    }
}
