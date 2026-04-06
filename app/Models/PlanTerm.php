<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Artisan;

class PlanTerm extends Model
{
    public const BILLING_MONTHLY = 'monthly';

    public const BILLING_QUARTERLY = 'quarterly';

    public const BILLING_HALF_YEARLY = 'half_yearly';

    public const BILLING_YEARLY = 'yearly';

    protected $fillable = [
        'plan_id',
        'billing_key',
        'duration_days',
        'price',
        'discount_percent',
        'is_visible',
        'sort_order',
    ];

    protected $casts = [
        'duration_days' => 'integer',
        'price' => 'decimal:2',
        'discount_percent' => 'integer',
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

    public static function billingKeys(): array
    {
        return [
            self::BILLING_MONTHLY,
            self::BILLING_QUARTERLY,
            self::BILLING_HALF_YEARLY,
            self::BILLING_YEARLY,
        ];
    }

    public static function durationDaysFor(string $billingKey): int
    {
        return match ($billingKey) {
            self::BILLING_MONTHLY => 30,
            self::BILLING_QUARTERLY => 90,
            self::BILLING_HALF_YEARLY => 180,
            self::BILLING_YEARLY => 365,
            default => 30,
        };
    }

    public static function defaultSortOrder(string $billingKey): int
    {
        return match ($billingKey) {
            self::BILLING_MONTHLY => 10,
            self::BILLING_QUARTERLY => 20,
            self::BILLING_HALF_YEARLY => 30,
            self::BILLING_YEARLY => 40,
            default => 50,
        };
    }

    /**
     * Insert any missing billing_key rows for a paid plan from {@see Plan::price} / {@see Plan::discount_percent}.
     * Does not change or delete existing {@see PlanTerm} rows.
     */
    public static function fillMissingTermsForPlan(Plan $plan): void
    {
        if (strtolower((string) $plan->slug) === 'free') {
            return;
        }

        $monthly = (float) $plan->price;
        $disc = $plan->discount_percent;
        $defs = [
            [self::BILLING_MONTHLY, 30, $monthly, $disc, true],
            [self::BILLING_QUARTERLY, 90, round($monthly * 3 * 0.95), null, false],
            [self::BILLING_HALF_YEARLY, 180, round($monthly * 6 * 0.90), null, false],
            [self::BILLING_YEARLY, 365, round($monthly * 12 * 0.85), null, false],
        ];

        foreach ($defs as [$key, $days, $price, $dPct, $visible]) {
            $exists = static::query()
                ->where('plan_id', $plan->id)
                ->where('billing_key', $key)
                ->exists();
            if ($exists) {
                continue;
            }

            static::query()->create([
                'plan_id' => $plan->id,
                'billing_key' => $key,
                'duration_days' => $days,
                'price' => $price,
                'discount_percent' => $dPct,
                'is_visible' => $visible,
                'sort_order' => static::defaultSortOrder($key),
            ]);
        }

        PlanPrice::syncFromPlanTerms($plan->fresh('terms'));
    }

    /**
     * Create or update the four billing rows for a paid plan from base monthly list price.
     */
    public static function syncDefaultsForPlan(Plan $plan): void
    {
        if (strtolower((string) $plan->slug) === 'free') {
            static::query()->where('plan_id', $plan->id)->delete();

            return;
        }

        $monthly = (float) $plan->price;
        $disc = $plan->discount_percent;
        $defs = [
            [self::BILLING_MONTHLY, 30, $monthly, $disc, true],
            [self::BILLING_QUARTERLY, 90, round($monthly * 3 * 0.95), null, false],
            [self::BILLING_HALF_YEARLY, 180, round($monthly * 6 * 0.90), null, false],
            [self::BILLING_YEARLY, 365, round($monthly * 12 * 0.85), null, false],
        ];
        foreach ($defs as [$key, $days, $price, $dPct, $visible]) {
            static::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'billing_key' => $key],
                [
                    'duration_days' => $days,
                    'price' => $price,
                    'discount_percent' => $dPct,
                    'is_visible' => $visible,
                    'sort_order' => static::defaultSortOrder($key),
                ]
            );
        }

        PlanPrice::syncFromPlanTerms($plan->fresh('terms'));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

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
}
