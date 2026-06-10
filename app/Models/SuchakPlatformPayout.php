<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class SuchakPlatformPayout extends Model
{
    use HasFactory;

    public const REASON_PLATFORM_CUSTOMER_PAYMENT_REWARD = 'platform_customer_payment_reward';
    public const REASON_PLATFORM_LEAD_QUALIFICATION = 'platform_lead_qualification';
    public const REASON_PLATFORM_VISIT_REWARD = 'platform_visit_reward';
    public const REASON_ADMIN_ADJUSTMENT = 'admin_adjustment';

    public const REASONS = [
        self::REASON_PLATFORM_CUSTOMER_PAYMENT_REWARD,
        self::REASON_PLATFORM_LEAD_QUALIFICATION,
        self::REASON_PLATFORM_VISIT_REWARD,
        self::REASON_ADMIN_ADJUSTMENT,
    ];

    public const EVENT_PLATFORM_CUSTOMER_PAYMENT = 'platform_customer_payment';
    public const EVENT_PLATFORM_LEAD_ACCEPTED = 'platform_lead_accepted';
    public const EVENT_PLATFORM_VISIT_CONFIRMED = 'platform_visit_confirmed';
    public const EVENT_ADMIN_CONFIRMED = 'admin_confirmed';

    public const PLATFORM_EVENT_TYPES = [
        self::EVENT_PLATFORM_CUSTOMER_PAYMENT,
        self::EVENT_PLATFORM_LEAD_ACCEPTED,
        self::EVENT_PLATFORM_VISIT_CONFIRMED,
        self::EVENT_ADMIN_CONFIRMED,
    ];

    public const SOURCE_PLATFORM_CONFIRMED_EVENT = 'platform_confirmed_event';
    public const SOURCE_ADMIN_VERIFIED_CASE = 'admin_verified_case';

    public const QUALIFICATION_SOURCES = [
        self::SOURCE_PLATFORM_CONFIRMED_EVENT,
        self::SOURCE_ADMIN_VERIFIED_CASE,
    ];

    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [
        self::STATUS_ON_HOLD,
        self::STATUS_QUALIFIED,
        self::STATUS_APPROVED,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
        self::STATUS_REVERSED,
    ];

    protected $table = 'suchak_platform_payouts';

    protected $fillable = [
        'suchak_account_id',
        'customer_context_id',
        'payment_context_id',
        'matrimony_profile_id',
        'platform_event_type',
        'platform_event_key',
        'payout_reason',
        'qualification_source',
        'payout_status',
        'settlement_statement_id',
        'amount',
        'deduction_amount',
        'reversal_amount',
        'net_amount',
        'currency',
        'liability_recognized_at',
        'qualified_by_user_id',
        'qualification_note',
        'hold_reason',
        'approved_by_user_id',
        'approved_at',
        'paid_by_user_id',
        'paid_at',
        'payout_reference_number',
        'payout_reference_note',
        'cancelled_by_user_id',
        'cancelled_at',
        'reversed_by_user_id',
        'reversed_at',
        'status_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'reversal_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'liability_recognized_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function customerContext(): BelongsTo
    {
        return $this->belongsTo(SuchakCustomerContext::class, 'customer_context_id');
    }

    public function paymentContext(): BelongsTo
    {
        return $this->belongsTo(SuchakPaymentContext::class, 'payment_context_id');
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function qualifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }

    public function settlementStatement(): BelongsTo
    {
        return $this->belongsTo(SuchakPlatformPayoutSettlement::class, 'settlement_statement_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(SuchakPlatformPayoutDetail::class, 'platform_payout_id')
            ->orderByDesc('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SuchakPlatformPayoutEvent::class, 'platform_payout_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function settlementLine(): HasOne
    {
        return $this->hasOne(SuchakPlatformPayoutSettlementLine::class, 'platform_payout_id');
    }

    public function latestDetail(): ?SuchakPlatformPayoutDetail
    {
        return $this->details->first();
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak platform payout records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak platform payout records cannot be deleted.');
    }
}
