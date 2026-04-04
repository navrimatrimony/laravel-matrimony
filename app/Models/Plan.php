<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'price',
        'discount_percent',
        'duration_days',
        'is_active',
        'sort_order',
        'highlight',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_percent' => 'integer',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'highlight' => 'boolean',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    /**
     * Final price after admin discount (not stored; avoids drift).
     */
    public function getFinalPriceAttribute(): float
    {
        $base = (float) $this->price;
        $d = (int) ($this->discount_percent ?? 0);
        if ($d <= 0) {
            return round($base, 2);
        }
        $d = min(100, max(0, $d));

        return round($base * (1 - $d / 100), 2);
    }

    public function hasActiveDiscount(): bool
    {
        return ((int) ($this->discount_percent ?? 0)) > 0;
    }

    public function featureValue(string $key, ?string $default = null): ?string
    {
        $row = $this->relationLoaded('features')
            ? $this->features->firstWhere('key', $key)
            : $this->features()->where('key', $key)->first();

        if (! $row) {
            return $default;
        }

        return (string) $row->value;
    }

    public static function defaultFree(): ?self
    {
        return static::query()->where('slug', 'free')->where('is_active', true)->first();
    }
}
