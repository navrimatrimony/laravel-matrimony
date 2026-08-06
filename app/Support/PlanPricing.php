<?php

namespace App\Support;

/**
 * Member/admin plan catalog pricing helpers.
 *
 * SSOT amounts: MRP = {@see \App\Models\PlanTerm::$price} / {@see \App\Models\Plan::$price},
 * payable = {@see \App\Models\PlanTerm::$selling_price} / {@see \App\Models\Plan::$selling_price}.
 * Display discount % is derived only — never used to compute charge.
 */
final class PlanPricing
{
    public static function normalizeMoney(float|int|string|null $value): float
    {
        // Catalog / checkout amounts are whole rupees only (no paise decimals in plan pricing UI).
        return (float) max(0, (int) round((float) $value));
    }

    /** Format for member/admin display — Latin digits, no decimal places. */
    public static function formatRupees(float|int|string|null $value): string
    {
        return number_format(self::normalizeMoney($value), 0, '.', ',');
    }

    /**
     * Whole-number display percent only: round(((MRP - selling) / MRP) * 100).
     * Never used as the payable SSOT.
     */
    public static function displayDiscountPercent(float|int|string|null $mrp, float|int|string|null $selling): int
    {
        $mrpN = self::normalizeMoney($mrp);
        $sellingN = self::normalizeMoney($selling);
        if ($mrpN <= 0.0 || $sellingN >= $mrpN) {
            return 0;
        }

        return (int) round((($mrpN - $sellingN) / $mrpN) * 100);
    }

    public static function hasDisplayDiscount(float|int|string|null $mrp, float|int|string|null $selling): bool
    {
        return self::displayDiscountPercent($mrp, $selling) > 0;
    }

    /**
     * @deprecated Temporary mirror of {@see displayDiscountPercent} for the deprecated discount_percent column.
     */
    public static function deprecatedDiscountColumnValue(float|int|string|null $mrp, float|int|string|null $selling): ?int
    {
        $pct = self::displayDiscountPercent($mrp, $selling);

        return $pct > 0 ? $pct : null;
    }
}
