<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Artisan;

class PlanPrice extends Model
{
    public const DURATION_MONTHLY = 'monthly';

    public const DURATION_QUARTERLY = 'quarterly';

    public const DURATION_HALF_YEARLY = 'half_yearly';

    public const DURATION_YEARLY = 'yearly';

    public const DURATION_TILL_MARRIAGE = 'till_marriage';

    protected $fillable = [
        'plan_id',
        'duration_type',
        'duration_days',
        'price',
        'original_price',
        'discount_percent',
        'is_popular',
        'is_best_seller',
        'tag',
        'is_visible',
        'sort_order',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'discount_percent' => 'integer',
        'is_popular' => 'boolean',
        'is_best_seller' => 'boolean',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
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
                //
            }
        });

        static::deleted(function () {
            if (app()->runningUnitTests()) {
                return;
            }
            try {
                Artisan::call('view:clear');
            } catch (\Throwable) {
                //
            }
        });
    }

    /**
     * Mirror {@see PlanTerm} rows into the pricing engine table (additive; terms remain for admin/backfill).
     */
    public static function syncFromPlanTerms(Plan $plan): void
    {
        if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
            static::query()->where('plan_id', $plan->id)->delete();

            return;
        }

        $plan->loadMissing('terms');
        foreach ($plan->terms as $term) {
            static::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'duration_type' => $term->billing_key],
                [
                    'duration_days' => $term->duration_days,
                    'price' => $term->price,
                    'original_price' => null,
                    'discount_percent' => $term->discount_percent,
                    'is_popular' => false,
                    'is_best_seller' => false,
                    'tag' => null,
                    'is_visible' => $term->is_visible,
                    'sort_order' => $term->sort_order,
                ]
            );
        }
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function getFinalPriceAttribute(): float
    {
        $base = (float) $this->price;
        $d = (int) ($this->discount_percent ?? 0);
        if ($this->discount_percent !== null && $d > 0) {
            $d = min(100, max(0, $d));

            return round($base * (1 - ($d / 100)), 2);
        }

        return round($base, 2);
    }

    /**
     * Strikethrough list price (original_price or pre-discount base).
     */
    public function getStrikeListPriceAttribute(): ?float
    {
        $final = $this->final_price;
        $orig = $this->original_price !== null ? (float) $this->original_price : null;
        if ($orig !== null && $orig > $final + 0.004) {
            return round($orig, 2);
        }
        $base = (float) $this->price;
        $d = (int) ($this->discount_percent ?? 0);
        if ($d > 0 && $base > $final + 0.004) {
            return round($base, 2);
        }

        return null;
    }
}
