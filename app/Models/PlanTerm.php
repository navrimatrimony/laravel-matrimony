<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PlanTerm extends Model
{
    public const BILLING_MONTHLY = 'monthly';

    public const BILLING_QUARTERLY = 'quarterly';

    public const BILLING_HALF_YEARLY = 'half_yearly';

    public const BILLING_YEARLY = 'yearly';

    public const BILLING_FIVE_YEARLY = 'five_yearly';

    public const BILLING_LIFETIME = 'lifetime';

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
        static::deleting(function (PlanTerm $term) {
            if (static::hasSubscriptionReferences((int) $term->id)) {
                throw ValidationException::withMessages([
                    'plan_terms' => [__('subscriptions.plan_term_in_use')],
                ]);
            }
        });

    }

    /**
     * True when any subscription row references this term (FK or immutable checkout snapshot).
     */
    public static function hasSubscriptionReferences(int $planTermId): bool
    {
        if ($planTermId <= 0) {
            return false;
        }

        return Subscription::query()
            ->where(function ($q) use ($planTermId) {
                $q->where('plan_term_id', $planTermId)
                    ->orWhere('meta->checkout_snapshot->plan_term_id', $planTermId);
            })
            ->exists();
    }

    /**
     * All catalog billing keys (coupons, validation). Legacy alias kept as {@see billingKeys()}.
     *
     * @return list<string>
     */
    public static function presetBillingKeys(): array
    {
        return [
            self::BILLING_MONTHLY,
            self::BILLING_QUARTERLY,
            self::BILLING_HALF_YEARLY,
            self::BILLING_YEARLY,
            self::BILLING_FIVE_YEARLY,
            self::BILLING_LIFETIME,
        ];
    }

    /**
     * @return list<string>
     */
    public static function billingKeys(): array
    {
        return self::presetBillingKeys();
    }

    public static function durationDaysFor(string $billingKey): int
    {
        return match ($billingKey) {
            self::BILLING_MONTHLY => 30,
            self::BILLING_QUARTERLY => 90,
            self::BILLING_HALF_YEARLY => 180,
            self::BILLING_YEARLY => 365,
            self::BILLING_FIVE_YEARLY => 1825,
            self::BILLING_LIFETIME => 0,
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
            self::BILLING_FIVE_YEARLY => 50,
            self::BILLING_LIFETIME => 60,
            default => 100,
        };
    }

    /**
     * Human label key under {@code subscriptions.billing_*}.
     */
    public static function billingLabelKey(string $billingKey): string
    {
        return 'subscriptions.billing_'.$billingKey;
    }

    /**
     * Insert any missing billing_key rows for a paid plan from {@see Plan::price} / {@see Plan::discount_percent}.
     * Does not change or delete existing {@see PlanTerm} rows.
     */
    public static function fillMissingTermsForPlan(Plan $plan): void
    {
        if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
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

        PlanPrice::ensureMirrorMatchesTerms($plan->fresh('terms'));
    }

    /**
     * Seed / backfill: four classic billing rows from base monthly list price.
     */
    public static function syncDefaultsForPlan(Plan $plan): void
    {
        if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
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

        PlanPrice::ensureMirrorMatchesTerms($plan->fresh('terms'));
    }

    /**
     * Replace all {@see PlanTerm} rows for a plan: delete existing rows, then insert request order (deterministic).
     *
     * @param  list<array{billing_key: string, price: float|int|string, discount_percent?: mixed, is_visible?: bool}>  $rows
     */
    public static function syncAdminTermRows(Plan $plan, array $rows): void
    {
        if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
            static::query()->where('plan_id', $plan->id)->delete();
            PlanPrice::query()->where('plan_id', $plan->id)->delete();

            return;
        }

        $existingIds = static::query()->where('plan_id', $plan->id)->pluck('id');
        foreach ($existingIds as $tid) {
            if (static::hasSubscriptionReferences((int) $tid)) {
                throw ValidationException::withMessages([
                    'plan_terms' => [__('subscriptions.plan_term_replace_blocked_in_use')],
                ]);
            }
        }

        static::query()->where('plan_id', $plan->id)->delete();

        $insertedKeys = [];
        foreach ($rows as $index => $row) {
            $key = (string) ($row['billing_key'] ?? '');
            if ($key === '' || ! in_array($key, self::presetBillingKeys(), true)) {
                continue;
            }
            if (isset($insertedKeys[$key])) {
                continue;
            }
            $insertedKeys[$key] = true;

            $price = (float) ($row['price'] ?? 0);
            $rawD = $row['discount_percent'] ?? null;
            $disc = ($rawD === '' || $rawD === null)
                ? null
                : max(0, min(100, (int) round((float) $rawD)));
            $visible = filter_var($row['is_visible'] ?? true, FILTER_VALIDATE_BOOLEAN)
                || (string) ($row['is_visible'] ?? '') === '1';

            static::query()->create([
                'plan_id' => $plan->id,
                'billing_key' => $key,
                'duration_days' => self::durationDaysFor($key),
                'price' => max(0, $price),
                'discount_percent' => $disc,
                'is_visible' => $visible,
                'sort_order' => ((int) $index + 1) * 10,
            ]);
        }

        PlanPrice::ensureMirrorMatchesTerms($plan->fresh('terms'));
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
