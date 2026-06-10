<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakPlatformPayoutSettlement extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';
    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_GENERATED,
        self::STATUS_VOID,
    ];

    protected $table = 'suchak_platform_payout_settlements';

    protected $fillable = [
        'suchak_account_id',
        'statement_number',
        'statement_month',
        'period_start',
        'period_end',
        'statement_status',
        'payout_count',
        'gross_amount',
        'deduction_amount',
        'reversal_amount',
        'net_amount',
        'currency',
        'statement_hash',
        'generated_by_admin_user_id',
        'generated_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'payout_count' => 'integer',
        'gross_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'reversal_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'generated_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function generatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_admin_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SuchakPlatformPayoutSettlementLine::class, 'settlement_statement_id')
            ->orderBy('id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(SuchakPlatformPayout::class, 'settlement_statement_id')
            ->orderBy('paid_at')
            ->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak platform payout settlement statements cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak platform payout settlement statements cannot be deleted.');
    }
}
