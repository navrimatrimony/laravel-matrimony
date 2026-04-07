<?php

namespace App\Services;

use App\Models\MasterIncomeCurrency;

/**
 * Centralized Income Engine: normalized annual amount computation and display formatting.
 * Used for matching logic and profile display. SSOT for income rules.
 *
 * Normalized annual strategy:
 * - exact / approximate: amount converted to annual (by period), then stored.
 * - range: midpoint of min/max converted to annual.
 * - undisclosed: null.
 */
class IncomeEngineService
{
    public const PERIOD_ANNUAL = 'annual';

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_WEEKLY = 'weekly';

    public const PERIOD_DAILY = 'daily';

    public const VALUE_TYPE_EXACT = 'exact';

    public const VALUE_TYPE_APPROXIMATE = 'approximate';

    public const VALUE_TYPE_RANGE = 'range';

    public const VALUE_TYPE_UNDISCLOSED = 'undisclosed';

    /** Multipliers to convert to annual (e.g. monthly * 12). */
    private const PERIOD_TO_ANNUAL = [
        'annual' => 1,
        'monthly' => 12,
        'weekly' => 52,
        'daily' => 365,
    ];

    /**
     * Compute normalized annual amount for matching.
     * - exact/approximate: single amount × period multiplier.
     * - range: midpoint(min, max) × period multiplier.
     * - undisclosed: null.
     */
    public function normalizeToAnnual(
        ?string $valueType,
        ?string $period,
        ?float $amount,
        ?float $minAmount,
        ?float $maxAmount
    ): ?float {
        if ($valueType === self::VALUE_TYPE_UNDISCLOSED || $valueType === null) {
            return null;
        }

        $mult = self::PERIOD_TO_ANNUAL[$period ?? 'annual'] ?? 1;

        if ($valueType === self::VALUE_TYPE_RANGE) {
            if ($minAmount === null && $maxAmount === null) {
                return null;
            }
            $min = (float) ($minAmount ?? 0);
            $max = (float) ($maxAmount ?? $min);
            $midpoint = ($min + $max) / 2;

            return round($midpoint * $mult, 2);
        }

        if ($amount === null || $amount === '') {
            return null;
        }

        return round((float) $amount * $mult, 2);
    }

    /**
     * Format income for display. Uses currency from profile/relation when provided.
     * Indian number formatting for INR (lakh/crore style optional; here we use comma-separated).
     *
     * @param  array{income_period?: string, income_value_type?: string, income_amount?: float, income_min_amount?: float, income_max_amount?: float, income_currency_id?: int, income_private?: bool}  $data  Raw income fields (prefix-agnostic: pass income_* or family_income_* keys)
     * @param  string  $prefix  Key prefix: 'income' or 'family_income'
     * @param  MasterIncomeCurrency|null  $currency  Resolved currency (e.g. from profile->incomeCurrency)
     */
    public function formatForDisplay(array $data, string $prefix, ?MasterIncomeCurrency $currency = null): string
    {
        $private = $data[$prefix.'_private'] ?? false;
        if ($private) {
            return 'Income hidden';
        }

        $valueType = $data[$prefix.'_value_type'] ?? null;
        $legacyAmountKey = $prefix === 'income' ? 'annual_income' : 'family_income';
        if ($valueType === self::VALUE_TYPE_UNDISCLOSED || $valueType === null) {
            $amount = $data[$prefix.'_amount'] ?? $data[$legacyAmountKey] ?? null;
            if ($amount === null || $amount === '') {
                return 'Not disclosed';
            }
        }

        $symbol = $currency ? $currency->displaySymbol() : '₹';
        $code = $currency ? ($currency->code ?? 'INR') : 'INR';
        $isInr = strtoupper($code) === 'INR';

        $period = $data[$prefix.'_period'] ?? 'annual';
        $periodLabel = $this->periodLabel($period);

        switch ($valueType) {
            case self::VALUE_TYPE_EXACT:
                $amount = $data[$prefix.'_amount'] ?? $data[$legacyAmountKey] ?? null;
                if ($amount === null || $amount === '') {
                    return 'Not disclosed';
                }

                return $symbol.$this->formatNumber($amount, $isInr).' '.$periodLabel;
            case self::VALUE_TYPE_APPROXIMATE:
                $amount = $data[$prefix.'_amount'] ?? $data[$legacyAmountKey] ?? null;
                if ($amount === null || $amount === '') {
                    return 'Not disclosed';
                }

                return 'Approx. '.$symbol.$this->formatNumber($amount, $isInr).' '.$periodLabel;
            case self::VALUE_TYPE_RANGE:
                $min = $data[$prefix.'_min_amount'] ?? null;
                $max = $data[$prefix.'_max_amount'] ?? null;
                if ($min === null && $max === null) {
                    return 'Not disclosed';
                }
                $minStr = $symbol.$this->formatNumber((float) ($min ?? 0), $isInr);
                $maxStr = $symbol.$this->formatNumber((float) ($max ?? $min ?? 0), $isInr);

                return $minStr.' – '.$maxStr.' '.$periodLabel;
            default:
                $amount = $data[$prefix.'_amount'] ?? $data[$legacyAmountKey] ?? null;
                if ($amount !== null && $amount !== '') {
                    return $symbol.$this->formatNumber($amount, $isInr).' '.$periodLabel;
                }

                return 'Not disclosed';
        }
    }

    private function periodLabel(string $period): string
    {
        return match ($period) {
            'monthly' => 'monthly',
            'weekly' => 'weekly',
            'daily' => 'daily',
            default => 'annually',
        };
    }

    /** Indian style: 12,00,000 (lakh) / 1.2 Cr; otherwise Western commas. */
    private function formatNumber(float $num, bool $indianStyle): string
    {
        if ($indianStyle && $num >= 10000000) {
            return number_format($num / 10000000, 1).' Cr';
        }
        if ($indianStyle && $num >= 100000) {
            return number_format($num / 100000, 1).' L';
        }
        if ($indianStyle) {
            return $this->indianNumberFormat($num);
        }

        return number_format($num, 0);
    }

    /** Indian comma placement: 12,00,000 */
    private function indianNumberFormat(float $num): string
    {
        $n = (string) (int) round($num);
        $len = strlen($n);
        if ($len <= 3) {
            return $n;
        }
        $last3 = substr($n, -3);
        $rest = substr($n, 0, -3);
        $rest = strrev(implode(',', str_split(strrev($rest), 2)));

        return $rest.','.$last3;
    }
}
