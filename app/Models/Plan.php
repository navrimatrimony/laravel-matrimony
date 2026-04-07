<?php

namespace App\Models;

use App\Services\FeatureUsageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'slug',
        'tier',
        'price',
        'discount_percent',
        'duration_days',
        'is_active',
        'is_visible',
        'sort_order',
        'highlight',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_percent' => 'integer',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
        'highlight' => 'boolean',
        'tier' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(function () {
            if (app()->runningUnitTests()) {
                return;
            }
            try {
                Artisan::call('view:clear');
            } catch (\Throwable) {
                // Non-fatal: pricing is always read from DB via accessors.
            }
        });
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    /**
     * Cached {@see features()} rows (60s TTL). Invalidated when {@see PlanFeature} rows change.
     *
     * @return Collection<int, PlanFeature>
     */
    public function getCachedFeatures(): Collection
    {
        return Cache::rememberForever(
            "plan_features_{$this->id}",
            fn () => $this->features()->get()
        );
    }

    /**
     * Typed feature value wrapper over {@see getFeatureValue} using {@see config('plan_features')}.
     * Does not replace existing string-based APIs (backward compatible).
     */
    public function getTypedFeatureValue(string $key): mixed
    {
        $value = $this->getFeatureValue($key);
        if ($value === null) {
            return null;
        }

        $normalized = app(FeatureUsageService::class)->normalizeFeatureKey($key);
        $config = config('plan_features')[$normalized] ?? null;
        if (! $config || ! is_array($config)) {
            return $value;
        }

        return match ((string) ($config['type'] ?? '')) {
            'limit', 'days' => (int) $value,
            'boolean' => (bool) ((int) $value),
            default => $value,
        };
    }

    public function forgetCachedPlanFeatures(): void
    {
        static::forgetCachedPlanFeaturesByPlanId((int) $this->id);
    }

    public static function forgetCachedPlanFeaturesByPlanId(int $planId): void
    {
        Cache::forget("plan_features_{$planId}");
    }

    /**
     * Feature value for a key, with {@see FeatureUsageService} alias normalization.
     */
    public function getFeatureValue(string $key): ?string
    {
        $normalized = app(FeatureUsageService::class)->normalizeFeatureKey($key);
        $rows = $this->relationLoaded('features')
            ? $this->features
            : $this->getCachedFeatures();

        $row = $rows->firstWhere('key', $normalized);

        return $row?->value !== null ? (string) $row->value : null;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(PlanTerm::class)->orderBy('sort_order');
    }

    public function visibleTerms(): HasMany
    {
        return $this->terms()->where('is_visible', true);
    }

    public function planPrices(): HasMany
    {
        return $this->hasMany(PlanPrice::class)->orderBy('sort_order');
    }

    public function visiblePlanPrices(): HasMany
    {
        return $this->hasMany(PlanPrice::class)
            ->where('is_visible', true)
            ->orderBy('sort_order');
    }

    /**
     * Final price after discount_percent (computed only; never stored in DB).
     */
    public function getFinalPriceAttribute(): float
    {
        $base = (float) $this->price;
        $d = (int) ($this->discount_percent ?? 0);
        if ($this->discount_percent && $d > 0) {
            $d = min(100, max(0, $d));

            return round($base * (1 - ($d / 100)), 2);
        }

        return round($base, 2);
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
