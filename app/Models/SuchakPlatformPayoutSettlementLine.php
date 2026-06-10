<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SuchakPlatformPayoutSettlementLine extends Model
{
    use HasFactory;

    public const TYPE_PAYOUT = 'payout';

    public const TYPES = [
        self::TYPE_PAYOUT,
    ];

    protected $table = 'suchak_platform_payout_settlement_lines';

    protected $fillable = [
        'settlement_statement_id',
        'platform_payout_id',
        'suchak_account_id',
        'line_type',
        'gross_amount',
        'deduction_amount',
        'reversal_amount',
        'net_amount',
        'currency',
        'line_note',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'reversal_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function settlementStatement(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformPayoutSettlement::class, 'settlement_statement_id');
    }

    public function platformPayout(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformPayout::class, 'platform_payout_id');
    }

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak platform payout settlement lines cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak platform payout settlement lines cannot be deleted.');
    }
}
