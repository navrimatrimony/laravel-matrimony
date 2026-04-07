<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5 SSOT: Master lookup for income currency. code (INR, USD, ...), symbol, is_default.
 *
 * {@see displaySymbol()} Prefer UTF-8 symbols from {@see SYMBOL_BY_CODE} so UI stays correct even if
 * the DB `symbol` column was stored under a wrong charset (mojibake).
 */
class MasterIncomeCurrency extends Model
{
    /** @var array<string, string> ISO code (uppercase) => display symbol */
    public const SYMBOL_BY_CODE = [
        'INR' => '₹',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'AED' => 'د.إ',
        'CAD' => 'C$',
        'AUD' => 'A$',
        'SGD' => 'S$',
    ];

    protected $table = 'master_income_currencies';

    protected $fillable = ['code', 'symbol', 'is_default', 'is_active'];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public static function symbolForCode(?string $code): ?string
    {
        $c = strtoupper(trim((string) $code));
        if ($c === '') {
            return null;
        }

        return self::SYMBOL_BY_CODE[$c] ?? null;
    }

    /**
     * Correct UTF-8 symbol for UI (avoids corrupted `symbol` column bytes).
     */
    public function displaySymbol(): string
    {
        $fromCode = self::symbolForCode($this->code);
        if ($fromCode !== null) {
            return $fromCode;
        }

        return trim((string) ($this->attributes['symbol'] ?? ''));
    }
}
